<?php

declare(strict_types=1);

/**
 * Cobre o bloco novo do plano 040 em webhook_controller::processEvent()
 * (gravar transaction_nsu do PagBank a partir do payload do webhook).
 *
 * NAO exercitado via processEvent() de ponta a ponta: alcancar esse bloco
 * exige passar por fetchStatus() do PagBank (chamada de rede real contra a
 * API), que so acontece depois de PAGBANK_TOKEN configurado — ausente neste
 * ambiente (kernel.php.example, mesma limitacao ja documentada no docblock de
 * WebhookIdempotencyTest para MP_WEBHOOK_SECRET/PAGBANK_TOKEN/INFINITEPAY_HANDLE).
 * Sem token, PagBankGateway::fetchStatus() lanca RuntimeException antes de
 * qualquer chamada HTTP, e processEvent() responde 500 — nunca alcanca o
 * bloco novo. Simular a resposta do PagBank exigiria injetar um double na
 * classe de producao, o que o plano proibe (STOP condition).
 *
 * Em vez disso, este teste replica EXATAMENTE as linhas novas de
 * processEvent() (mesma tecnica ja usada em
 * WebhookIdempotencyTest::testLatePaymentGuardNeverOverwritesAlreadyExpiredOrder
 * para o guard de corrida) contra dados reais no banco, provando:
 *  1. o webhook do PagBank grava transaction_nsu quando a cobranca ainda nao tem;
 *  2. nunca sobrescreve um transaction_nsu ja existente;
 *  3. o bloco novo nao roda quando $infinitepayTransactionNsu ja veio setado
 *     (regressao: o fluxo InfinitePay continua intocado).
 *
 * Os 4 casos de PagBankGateway::extractTransactionNsu() em si (payload valido,
 * sem charges, JSON invalido, id vazio) ja estao cobertos por unidade em
 * PagBankGatewayTest.php (Step 4 do plano 040) — nao duplicados aqui.
 */
final class WebhookTransactionNsuTest extends DBTestCase
{
    private function gatewayIdBySlug(string $slug): int
    {
        $model = new payment_gateways_model();
        $model->set_field([" idx "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $idx = $model->data[0]['idx'] ?? null;
        $this->assertNotNull($idx, "Gateway seed '$slug' nao encontrado (migration 011)");

        return (int)$idx;
    }

    /**
     * @return array{order_id:int, charge_idx:int}
     */
    private function createOrderWithCharge(
        int $gatewayId,
        string $gatewayChargeId,
        ?string $transactionNsu,
        int $totalCents,
        int $amountCents
    ): array {
        $token = bin2hex(random_bytes(16));

        $order = new orders_model();
        $order->populate([
            'token'           => $token,
            'status'          => 'aguardando_pagamento',
            'customer_name'   => 'Cliente Teste',
            'customer_mail'   => 'teste_' . uniqid() . '@example.com',
            'customer_phone'  => '11999999999',
            'customer_cpf'    => '12345678909',
            'ship_zip'        => '01310100',
            'ship_street'     => 'Av. Paulista',
            'ship_number'     => '1000',
            'ship_district'   => 'Bela Vista',
            'ship_city'       => 'São Paulo',
            'ship_uf'         => 'SP',
            'total_cents'     => $totalCents,
            'paid_at'         => null,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $orderId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => $gatewayChargeId,
            'transaction_nsu'     => $transactionNsu,
            'status'              => 'pendente',
            'amount_cents'        => $amountCents,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'paid_at'             => null,
        ]);
        $chargeIdx = $charge->save();
        $this->assertIsInt($chargeIdx);

        return ['order_id' => $orderId, 'charge_idx' => $chargeIdx];
    }

    private function loadCharge(int $idx): array
    {
        $model = new pix_charges_model();
        $model->set_filter(["idx = ?"], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    /**
     * Replica exatamente o bloco novo de webhook_controller::processEvent()
     * (linhas logo apos o `if ($infinitepayTransactionNsu !== null)` do
     * InfinitePay) e persiste via pix_charges_model, igual ao codigo real.
     *
     * @return array<string, mixed>
     */
    private function runNewNsuBlockAndSave(
        int $chargeIdx,
        array $charge,
        ?string $infinitepayTransactionNsu,
        PixGateway $gateway,
        string $rawBody
    ): array {
        $paidAt = date('Y-m-d H:i:s');

        $chargeUpdateData = [
            'status'  => 'pago',
            'paid_at' => $paidAt,
        ];
        if ($infinitepayTransactionNsu !== null) {
            $chargeUpdateData['transaction_nsu'] = $infinitepayTransactionNsu;
        }

        if ($infinitepayTransactionNsu === null && empty($charge['transaction_nsu'])) {
            $webhookNsu = $gateway->extractTransactionNsu($rawBody);
            if ($webhookNsu !== null) {
                $chargeUpdateData['transaction_nsu'] = $webhookNsu;
            }
        }

        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(["idx = ?", "status <> 'expirado'"], [$chargeIdx]);
        $chargeUpdate->populate($chargeUpdateData);
        $chargeResult = $chargeUpdate->save();

        $this->assertInstanceOf(\PDOStatement::class, $chargeResult);
        $this->assertSame(1, $chargeResult->rowCount());

        return $chargeUpdateData;
    }

    public function testPagBankWebhookNsuPersistedWhenChargeHasNoNsuYet(): void
    {
        $gatewayId = $this->gatewayIdBySlug('pagbank');
        $ctx = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'QRCO_' . uniqid(),
            transactionNsu: null,
            totalCents: 10000,
            amountCents: 10000
        );

        $charge = $this->loadCharge($ctx['charge_idx']);
        $this->assertNull($charge['transaction_nsu']);

        $gateway = new PagBankGateway();
        // Sufixo unico (uniqid()) — mesma convencao ja usada em
        // WebhookIdempotencyTest ('txn-' . uniqid(), 'chg-a-' . uniqid()) para
        // nao colidir com a UNIQUE key entre execucoes (ver
        // blocked-customers-tests-dont-rollback: DBTestCase nao protege de
        // verdade contra escritas que persistem entre execucoes do processo).
        $expectedNsu = 'CHAR_TEST_NSU_' . uniqid();
        $rawBody = json_encode(['charges' => [['id' => $expectedNsu]]]);

        $this->runNewNsuBlockAndSave($ctx['charge_idx'], $charge, null, $gateway, (string)$rawBody);

        $updated = $this->loadCharge($ctx['charge_idx']);
        $this->assertSame($expectedNsu, $updated['transaction_nsu']);
        $this->assertSame('pago', $updated['status']);
    }

    public function testPagBankWebhookNsuNeverOverwritesExistingNsu(): void
    {
        $gatewayId = $this->gatewayIdBySlug('pagbank');
        $existingNsu = 'CHAR_EXISTING_NSU_' . uniqid();
        $ctx = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'QRCO_' . uniqid(),
            transactionNsu: $existingNsu,
            totalCents: 10000,
            amountCents: 10000
        );

        $charge = $this->loadCharge($ctx['charge_idx']);
        $this->assertSame($existingNsu, $charge['transaction_nsu']);

        $gateway = new PagBankGateway();
        // Corpo traz um id DIFERENTE do ja gravado — se o guard `empty()` nao
        // protegesse, este teste pegaria a sobrescrita.
        $rawBody = json_encode(['charges' => [['id' => 'CHAR_TEST_NSU_' . uniqid()]]]);

        $this->runNewNsuBlockAndSave($ctx['charge_idx'], $charge, null, $gateway, (string)$rawBody);

        $updated = $this->loadCharge($ctx['charge_idx']);
        $this->assertSame($existingNsu, $updated['transaction_nsu'], 'nunca deve sobrescrever transaction_nsu ja gravado');
        $this->assertSame('pago', $updated['status']);
    }

    /**
     * Regressao: quando $infinitepayTransactionNsu ja veio setado (fluxo
     * InfinitePay), o bloco novo nao deve rodar — mesmo que
     * extractTransactionNsu() do gateway devolvesse um valor diferente, ele
     * nunca e chamado. Usa PagBankGateway com um rawBody que teria um NSU
     * valido de proposito: se o guard `$infinitepayTransactionNsu === null`
     * nao existisse, este teste pegaria a sobrescrita indevida.
     */
    public function testNewNsuBlockNeverRunsWhenInfinitePayTransactionNsuAlreadySet(): void
    {
        $gatewayId = $this->gatewayIdBySlug('pagbank');
        $ctx = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'QRCO_' . uniqid(),
            transactionNsu: null,
            totalCents: 10000,
            amountCents: 10000
        );

        $charge = $this->loadCharge($ctx['charge_idx']);
        $this->assertNull($charge['transaction_nsu']);

        $gateway = new PagBankGateway();
        $shouldNotBeUsed = 'CHAR_SHOULD_NOT_BE_USED_' . uniqid();
        $rawBody = json_encode(['charges' => [['id' => $shouldNotBeUsed]]]);
        $infinitepayNsu = 'infinitepay-nsu-real-do-teste-' . uniqid();

        $chargeUpdateData = $this->runNewNsuBlockAndSave(
            $ctx['charge_idx'],
            $charge,
            $infinitepayNsu,
            $gateway,
            (string)$rawBody
        );

        $this->assertSame($infinitepayNsu, $chargeUpdateData['transaction_nsu']);

        $updated = $this->loadCharge($ctx['charge_idx']);
        $this->assertSame($infinitepayNsu, $updated['transaction_nsu']);
        $this->assertNotSame($shouldNotBeUsed, $updated['transaction_nsu']);
    }
}
