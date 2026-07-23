<?php

declare(strict_types=1);

/**
 * Cobre OrderReconciler::reconcilePending() (Plano 034): fallback de
 * reconciliacao contra o PSP para quando o webhook de pagamento falha ou
 * atrasa. Mesma limitacao documentada em OrderExpirerTest/WebhookIdempotencyTest:
 * confirmOne() comita por cobranca, explicitamente, na conexao singleton
 * compartilhada por todo o processo PHPUnit (localPDO::getInstance()) — os
 * commits de teste sao reais na base de dev compartilhada, sem rollback
 * possivel no tearDown() depois disso. Aceito conscientemente, seguindo o
 * mesmo precedente ja usado em OrderExpirerTest.
 *
 * Por causa disso, cobrancas 'pendente' de execucoes anteriores (dentro do
 * mesmo processo PHPUnit ou de runs anteriores contra a mesma base de dev)
 * podem vazar para o SELECT de reconcilePending() — o resolvedor de status
 * injetado abaixo e sempre CONDICIONAL ao gateway_charge_id do fixture sob
 * teste (devolve 'pago' so para o alvo, 'pendente' para qualquer outro), para
 * nao confirmar cobrancas de outros testes como efeito colateral. Pelo mesmo
 * motivo, os asserts sobre os contadores agregados do resumo usam
 * assertGreaterThanOrEqual em vez de igualdade estrita quando outras
 * cobrancas vazadas podem inflar o total.
 */
final class OrderReconcilerTest extends DBTestCase
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

    private function createOrder(string $status, string $createdAt): int
    {
        $order = new orders_model();
        $order->populate([
            'token'           => bin2hex(random_bytes(16)),
            'status'          => $status,
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
            'total_cents'     => 5000,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        // orders_model->save() nao expoe created_at no $field (setado por
        // default now() no INSERT) — para testar a janela de 24h, o
        // created_at precisa ser controlado explicitamente.
        $order->execute_raw_prepared(
            'UPDATE orders SET created_at = ? WHERE idx = ?',
            [$createdAt, $orderId]
        );

        return $orderId;
    }

    private function createPendingCharge(int $ordersId, int $gatewayId): int
    {
        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $ordersId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => 'chg-' . uniqid(),
            'status'              => 'pendente',
            'amount_cents'        => 5000,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = $charge->save();
        $this->assertIsInt($id);

        return $id;
    }

    private function loadOrder(int $idx): array
    {
        $model = new orders_model();
        $model->set_filter(['idx = ?'], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    private function loadCharge(int $idx): array
    {
        $model = new pix_charges_model();
        $model->set_filter(['idx = ?'], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    public function testPspConfirmsPaidMarksOrderAndChargeAsPaid(): void
    {
        $gatewayId = $this->gatewayIdBySlug('mercadopago');
        $now = date('Y-m-d H:i:s');
        $orderId = $this->createOrder('aguardando_pagamento', $now);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);
        $targetChargeId = $this->loadCharge($chargeId)['gateway_charge_id'];

        // Resolvedor condicional ao alvo: qualquer outra cobranca vazada de
        // testes anteriores continua 'pendente', nunca e confirmada como
        // efeito colateral deste teste.
        $reconciler = new OrderReconciler(
            fn (string $slug, string $gatewayChargeId): string =>
                $gatewayChargeId === $targetChargeId ? 'pago' : 'pendente'
        );
        $summary = $reconciler->reconcilePending($now);

        $this->assertGreaterThanOrEqual(1, $summary['checked']);
        $this->assertSame(1, $summary['confirmed'], 'so o alvo deste teste deveria ser confirmado (resolvedor condicional)');

        $order = $this->loadOrder($orderId);
        $this->assertSame('pago', $order['status']);
        $this->assertNotNull($order['paid_at']);

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('pago', $charge['status']);
        $this->assertNotNull($charge['paid_at']);

        // Achado do specialist de testing (/ship): confirmOne() enfileira o
        // e-mail 'order_paid' via enqueuePaidEmail() DEPOIS do commit real —
        // este teste ja paga o custo de um commit permanente (mesma limitacao
        // aceita em OrderExpirerTest/WebhookIdempotencyTest), entao a asserção
        // de que o e-mail realmente chegou em email_queue e quase gratis
        // (mesmo padrao de WebhookEnqueueTest para o caminho equivalente do
        // webhook).
        $mailModel = new email_queue_model();
        $mailModel->set_filter([" orders_id = ? ", " event_type = ? "], [$orderId, 'order_paid']);
        $mailModel->set_paginate([1]);
        $mailModel->load_data(false);
        $mail = $mailModel->data[0] ?? null;
        $this->assertNotNull($mail, 'confirmOne() deveria ter enfileirado o e-mail order_paid apos o commit');
        $this->assertSame($this->loadOrder($orderId)['customer_mail'] ?? null, $mail['to_mail'] ?? null);
    }

    /**
     * Achado do adversarial review (/ship): a migracao pro update() do
     * DOLModel fez confirmOne() perder o carimbo explicito de modified_at em
     * PHP nas duas escritas ('pago' em orders e em pix_charges) -- o codigo
     * raw anterior sempre bindava modified_at = $now junto com paid_at = $now.
     * Confirmado empiricamente que o container MySQL deste ambiente tem skew
     * de fuso real (~3h) com o PHP (America/Sao_Paulo), mesma classe de bug
     * ja pega pelo red-team em OrderExpirer::expireOne(). Prova pelo caminho
     * real de escrita que ambas as colunas batem com o relogio do PHP.
     */
    public function testConfirmOneStampsModifiedAtWithPhpClockNotMysqlClock(): void
    {
        $gatewayId = $this->gatewayIdBySlug('mercadopago');
        $now = date('Y-m-d H:i:s');
        $orderId = $this->createOrder('aguardando_pagamento', $now);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);

        $reconciler = new OrderReconciler(fn (string $slug, string $gatewayChargeId): string => 'pago');
        $confirmed = $reconciler->confirmOne($orderId, $chargeId, $now);
        $this->assertTrue($confirmed);

        $orderStmt = (new orders_model())->select([' modified_at '], 'WHERE idx = ?', [$orderId]);
        $orderModifiedAt = $orderStmt->fetch(PDO::FETCH_ASSOC)['modified_at'] ?? null;
        $this->assertNotNull($orderModifiedAt);
        $this->assertLessThan(5, abs(strtotime($orderModifiedAt) - strtotime($now)), 'orders.modified_at deveria bater com o relogio do PHP, nao com now() do MySQL');

        $chargeStmt = (new pix_charges_model())->select([' modified_at '], 'WHERE idx = ?', [$chargeId]);
        $chargeModifiedAt = $chargeStmt->fetch(PDO::FETCH_ASSOC)['modified_at'] ?? null;
        $this->assertNotNull($chargeModifiedAt);
        $this->assertLessThan(5, abs(strtotime($chargeModifiedAt) - strtotime($now)), 'pix_charges.modified_at deveria bater com o relogio do PHP, nao com now() do MySQL');
    }

    public function testPspStillPendingLeavesOrderUntouched(): void
    {
        $gatewayId = $this->gatewayIdBySlug('pagbank');
        $now = date('Y-m-d H:i:s');
        $orderId = $this->createOrder('aguardando_pagamento', $now);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);

        $reconciler = new OrderReconciler(fn (string $slug, string $gatewayChargeId): string => 'pendente');
        $reconciler->reconcilePending($now);

        $order = $this->loadOrder($orderId);
        $this->assertSame('aguardando_pagamento', $order['status']);

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('pendente', $charge['status']);
    }

    /**
     * Achado do adversarial review (/ship): fetchStatus() devolve 'erro'
     * (nao 'pendente') quando a chamada ao PSP falha (timeout, HTTP nao-2xx).
     * Antes isso caia no mesmo contador 'skipped' da corrida benigna,
     * escondendo uma falha sistemica do PSP atras do contador errado.
     */
    public function testPspErrorCountsAsErroredNotSkipped(): void
    {
        $gatewayId = $this->gatewayIdBySlug('mercadopago');
        $now = date('Y-m-d H:i:s');
        $orderId = $this->createOrder('aguardando_pagamento', $now);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);
        $targetChargeId = $this->loadCharge($chargeId)['gateway_charge_id'];

        $reconciler = new OrderReconciler(
            fn (string $slug, string $gatewayChargeId): string =>
                $gatewayChargeId === $targetChargeId ? 'erro' : 'pendente'
        );
        $summary = $reconciler->reconcilePending($now);

        $this->assertSame(1, $summary['errored'], 'falha do PSP deve contar como errored, distinto de skipped');

        $order = $this->loadOrder($orderId);
        $this->assertSame('aguardando_pagamento', $order['status']);

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('pendente', $charge['status']);
    }

    public function testInfinitePayIsExcludedFromSelection(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $now = date('Y-m-d H:i:s');
        $orderId = $this->createOrder('aguardando_pagamento', $now);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);
        $targetChargeId = $this->loadCharge($chargeId)['gateway_charge_id'];

        $calledWithOurCharge = false;
        $reconciler = new OrderReconciler(
            function (string $slug, string $gatewayChargeId) use ($targetChargeId, &$calledWithOurCharge): string {
                if ($gatewayChargeId === $targetChargeId) {
                    $calledWithOurCharge = true;
                }
                // Nunca confirma nada neste teste — o ponto e provar que o
                // resolvedor NUNCA e chamado para a cobranca infinitepay,
                // independente do que ele devolveria.
                return 'pendente';
            }
        );
        $reconciler->reconcilePending($now);

        $this->assertFalse($calledWithOurCharge, 'cobranca infinitepay nao deveria ter sido passada ao resolvedor de status (sem endpoint de consulta)');

        $order = $this->loadOrder($orderId);
        $this->assertSame('aguardando_pagamento', $order['status']);

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('pendente', $charge['status']);
    }

    /**
     * Simula o resultado de uma corrida com o webhook: o pedido ja foi
     * confirmado 'pago' por outro caminho antes deste job rodar. A defesa e
     * em 2 camadas — o SELECT ja exclui pedidos fora de 'aguardando_pagamento'
     * (nem entra no lote) e o UPDATE condicional em confirmOne() e a segunda
     * camada para o caso em que a transicao acontece ENTRE o SELECT e o
     * UPDATE (nao reproduzivel deterministicamente sem hooks de concorrencia
     * — mesma simplificacao ja aceita em OrderExpirerTest::
     * testAlreadyPaidOrderIsNeverRestocked()). O que importa provar aqui:
     * nenhuma das 2 camadas deixa a cobranca ser marcada paga nem duplica
     * e-mail.
     */
    public function testAlreadyPaidOrderIsNeverReConfirmed(): void
    {
        $gatewayId = $this->gatewayIdBySlug('mercadopago');
        $now = date('Y-m-d H:i:s');
        $orderId = $this->createOrder('pago', $now);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);
        $targetChargeId = $this->loadCharge($chargeId)['gateway_charge_id'];

        // Resolvedor condicional ao alvo: mesmo que devolvesse 'pago', o
        // pedido ja fora do lote (status <> aguardando_pagamento) nao deveria
        // sequer chegar a ser perguntado.
        $calledWithOurCharge = false;
        $reconciler = new OrderReconciler(
            function (string $slug, string $gatewayChargeId) use ($targetChargeId, &$calledWithOurCharge): string {
                if ($gatewayChargeId === $targetChargeId) {
                    $calledWithOurCharge = true;
                }
                return 'pago';
            }
        );
        $reconciler->reconcilePending($now);

        $this->assertFalse($calledWithOurCharge, 'cobranca de pedido ja pago nao deveria ser selecionada (o.status <> aguardando_pagamento)');

        $order = $this->loadOrder($orderId);
        $this->assertSame('pago', $order['status']);

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('pendente', $charge['status'], 'cobranca de pedido ja pago nunca deve ser marcada paga por este job');
    }

    public function testOutsideTwentyFourHourWindowIsNotSelected(): void
    {
        $gatewayId = $this->gatewayIdBySlug('mercadopago');
        $now = date('Y-m-d H:i:s');
        $old = date('Y-m-d H:i:s', strtotime($now) - 25 * 3600);
        $orderId = $this->createOrder('aguardando_pagamento', $old);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);
        $targetChargeId = $this->loadCharge($chargeId)['gateway_charge_id'];

        $calledWithOurCharge = false;
        $reconciler = new OrderReconciler(
            function (string $slug, string $gatewayChargeId) use ($targetChargeId, &$calledWithOurCharge): string {
                if ($gatewayChargeId === $targetChargeId) {
                    $calledWithOurCharge = true;
                }
                return 'pendente';
            }
        );
        $reconciler->reconcilePending($now);

        $this->assertFalse($calledWithOurCharge, 'pedido criado ha mais de 24h nao deve ser selecionado');

        $order = $this->loadOrder($orderId);
        $this->assertSame('aguardando_pagamento', $order['status']);

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('pendente', $charge['status']);
    }

    /**
     * Prova que rollback()+beginTransaction() no catch de reconcilePending()
     * (falha isolada em 1 cobranca) nao desfaz o commit ja durabilizado de
     * uma cobranca anterior no MESMO lote — mesma propriedade que
     * OrderExpirerTest::testOneFailingOrderDoesNotRollbackPreviouslyCommittedOrdersInBatch
     * ja cobre para o job irmao (plano 032). Testavel sem hooks de
     * concorrencia reais: confirmOne() e publico e nao-final, entao uma
     * subclasse anonima pode forcar a excecao para uma cobranca especifica
     * do lote e provar que o commit da cobranca anterior sobrevive.
     */
    public function testOneFailingChargeDoesNotRollbackPreviouslyCommittedChargesInBatch(): void
    {
        $gatewayId = $this->gatewayIdBySlug('mercadopago');
        $now = date('Y-m-d H:i:s');

        $orderIdA = $this->createOrder('aguardando_pagamento', $now);
        $chargeIdA = $this->createPendingCharge($orderIdA, $gatewayId);

        $orderIdB = $this->createOrder('aguardando_pagamento', $now);
        $chargeIdB = $this->createPendingCharge($orderIdB, $gatewayId);

        $targetChargeIdA = $this->loadCharge($chargeIdA)['gateway_charge_id'];
        $targetChargeIdB = $this->loadCharge($chargeIdB)['gateway_charge_id'];

        // Resolvedor condicional aos 2 alvos deste teste (mesmo motivo dos
        // outros testes: nao confirmar cobrancas vazadas de outros testes).
        $reconciler = new class(
            fn (string $slug, string $gatewayChargeId): string =>
                in_array($gatewayChargeId, [$targetChargeIdA, $targetChargeIdB], true) ? 'pago' : 'pendente'
        ) extends OrderReconciler {
            public int $failOrdersId = 0;

            public function confirmOne(int $ordersId, int $chargeIdx, string $now): bool
            {
                if ($ordersId === $this->failOrdersId) {
                    throw new \RuntimeException('forced failure for test');
                }
                return parent::confirmOne($ordersId, $chargeIdx, $now);
            }
        };
        $reconciler->failOrdersId = $orderIdB;

        $summary = $reconciler->reconcilePending($now);

        $this->assertSame(1, $summary['confirmed'], 'so a cobranca A deveria ser confirmada (resolvedor condicional aos 2 alvos)');
        $this->assertGreaterThanOrEqual(1, $summary['errored'], 'a cobranca B (falha forcada) deve contar como errored (distinto de skipped), sem derrubar o lote inteiro');

        $orderA = $this->loadOrder($orderIdA);
        $this->assertSame('pago', $orderA['status'], 'pedido A deve permanecer pago mesmo com falha no pedido B (sem lote inteiro)');
        $chargeA = $this->loadCharge($chargeIdA);
        $this->assertSame('pago', $chargeA['status']);

        $orderB = $this->loadOrder($orderIdB);
        $this->assertSame('aguardando_pagamento', $orderB['status'], 'pedido que falhou deve permanecer intocado para retry no proximo ciclo');
        $chargeB = $this->loadCharge($chargeIdB);
        $this->assertSame('pendente', $chargeB['status']);
    }

    private function createExpiredChargeFor(int $ordersId, int $gatewayId): int
    {
        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $ordersId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => 'chg-' . uniqid(),
            'status'              => 'expirado',
            'amount_cents'        => 5000,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('-1 minute')),
        ]);
        $id = $charge->save();
        $this->assertIsInt($id);

        return $id;
    }

    /**
     * Achado do red-team (/ship): OrderReconciler e OrderExpirer (plano 032)
     * rodam no mesmo cron sem locks mutuamente exclusivos — se o OrderExpirer
     * vencer a corrida, o SELECT principal (WHERE aguardando_pagamento)
     * exclui o pedido PARA SEMPRE, e um "pago" que o PSP confirme depois
     * ficaria invisivel. alertRecentlyExpiredPaidCharges() cobre essa janela:
     * aqui provamos que ela ALERTA (loga + marca a cobranca 'erro') sem
     * reverter o pedido pra 'pago' sozinho (reversao de estoque e outra
     * feature, fora de escopo deste job).
     */
    public function testRecentlyExpiredPaidChargeIsAlertedNotReverted(): void
    {
        $gatewayId = $this->gatewayIdBySlug('mercadopago');
        $now = date('Y-m-d H:i:s');
        $recentlyExpiredAt = date('Y-m-d H:i:s', strtotime($now) - 10 * 60);

        $orderId = $this->createOrder('expirado', $now);
        $order = new orders_model();
        $order->execute_raw_prepared('UPDATE orders SET modified_at = ? WHERE idx = ?', [$recentlyExpiredAt, $orderId]);

        $chargeId = $this->createExpiredChargeFor($orderId, $gatewayId);
        $charge = new pix_charges_model();
        $charge->execute_raw_prepared('UPDATE pix_charges SET modified_at = ? WHERE idx = ?', [$recentlyExpiredAt, $chargeId]);
        $targetChargeId = $this->loadCharge($chargeId)['gateway_charge_id'];

        // Resolvedor condicional ao alvo, mesmo motivo dos outros testes.
        $reconciler = new OrderReconciler(
            fn (string $slug, string $gatewayChargeId): string =>
                $gatewayChargeId === $targetChargeId ? 'pago' : 'pendente'
        );
        $summary = $reconciler->reconcilePending($now);

        $this->assertSame(1, $summary['alerted'], 'a cobranca expirada-mas-paga deveria ter sido alertada');

        $orderAfter = $this->loadOrder($orderId);
        $this->assertSame('expirado', $orderAfter['status'], 'o pedido nunca deve ser revertido pra pago sozinho (reversao de estoque fora de escopo)');

        $chargeAfter = $this->loadCharge($chargeId);
        $this->assertSame('erro', $chargeAfter['status'], 'a cobranca deve virar erro pra sinalizar reconciliacao manual e nao repetir o alerta no proximo tick');

        // Achado do adversarial review (/ship): esta transicao pra 'erro'
        // tambem perdeu o carimbo explicito de modified_at em PHP na
        // migracao -- mesma classe de bug do red-team, call site diferente.
        $chargeModifiedAtStmt = (new pix_charges_model())->select([' modified_at '], 'WHERE idx = ?', [$chargeId]);
        $chargeModifiedAt = $chargeModifiedAtStmt->fetch(PDO::FETCH_ASSOC)['modified_at'] ?? null;
        $this->assertNotNull($chargeModifiedAt);
        $this->assertLessThan(5, abs(strtotime($chargeModifiedAt) - strtotime($now)), 'pix_charges.modified_at (transicao erro) deveria bater com o relogio do PHP, nao com now() do MySQL');
    }

    public function testExpiredChargeOutsideAlertWindowIsNotAlerted(): void
    {
        $gatewayId = $this->gatewayIdBySlug('pagbank');
        $now = date('Y-m-d H:i:s');
        $longAgo = date('Y-m-d H:i:s', strtotime($now) - 61 * 60);

        $orderId = $this->createOrder('expirado', $now);
        $order = new orders_model();
        $order->execute_raw_prepared('UPDATE orders SET modified_at = ? WHERE idx = ?', [$longAgo, $orderId]);

        $chargeId = $this->createExpiredChargeFor($orderId, $gatewayId);
        $charge = new pix_charges_model();
        $charge->execute_raw_prepared('UPDATE pix_charges SET modified_at = ? WHERE idx = ?', [$longAgo, $chargeId]);
        $targetChargeId = $this->loadCharge($chargeId)['gateway_charge_id'];

        // Resolvedor CONDICIONAL ao alvo (mesmo motivo do resto do arquivo):
        // um resolvedor incondicional 'pago' aqui alertaria/marcaria 'erro'
        // em qualquer cobranca expirada+expirada vazada de OrderExpirerTest
        // que caia dentro da janela de 60min por coincidencia de horario.
        $calledWithOurCharge = false;
        $reconciler = new OrderReconciler(
            function (string $slug, string $gatewayChargeId) use ($targetChargeId, &$calledWithOurCharge): string {
                if ($gatewayChargeId === $targetChargeId) {
                    $calledWithOurCharge = true;
                    return 'pago';
                }
                return 'pendente';
            }
        );
        $reconciler->reconcilePending($now);

        $this->assertFalse($calledWithOurCharge, 'cobranca expirada ha mais de 60min nao deveria ser revisitada pelo alerta');

        $chargeAfter = $this->loadCharge($chargeId);
        $this->assertSame('expirado', $chargeAfter['status']);
    }
}
