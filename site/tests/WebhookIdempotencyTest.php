<?php

declare(strict_types=1);

/**
 * Cobre webhook_controller::processEvent(): assinatura invalida -> 401,
 * reentrega de um evento ja processado -> nao repete a transicao (idempotencia),
 * e valor pago menor que o total -> nao marca como pago.
 *
 * Testado via processEvent() (nao receive()) porque receive() termina em
 * json_response() -> exit(), que nao pode ser exercitado em PHPUnit (mesmo
 * padrao documentado em AuthFunctionsTest para validate_csrf).
 *
 * IMPORTANTE: nenhum destes testes alcanca o caminho de sucesso que chama
 * `$orderUpdate->commit()` (ver 002 Passo 11) — esse commit e explicito na
 * conexao singleton compartilhada por todo o processo PHPUnit (localPDO::
 * getInstance()), entao chama-lo aqui commitaria de verdade todos os dados de
 * teste acumulados no processo, sem rollback possivel depois. Os testes de
 * "idempotencia" pre-semeiam a cobranca ja como 'pago' (sem passar por
 * processEvent() para chegar la) e verificam que uma reentrega nao dispara
 * nova escrita — mesma garantia funcional, sem tocar o commit real.
 */
final class WebhookIdempotencyTest extends DBTestCase
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
     * @return array{order_id:int, token:string, charge_idx:int}
     */
    private function createOrderWithCharge(
        int $gatewayId,
        string $gatewayChargeId,
        string $chargeStatus,
        int $totalCents,
        int $amountCents,
        ?string $paidAt = null
    ): array {
        $token = bin2hex(random_bytes(16));

        $order = new orders_model();
        $order->populate([
            'token'           => $token,
            'status'          => $chargeStatus === 'pago' ? 'pago' : 'aguardando_pagamento',
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
            'paid_at'         => $chargeStatus === 'pago' ? $paidAt : null,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $orderId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => $gatewayChargeId,
            'status'              => $chargeStatus,
            'amount_cents'        => $amountCents,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'paid_at'             => $chargeStatus === 'pago' ? $paidAt : null,
        ]);
        $chargeIdx = $charge->save();
        $this->assertIsInt($chargeIdx);

        return ['order_id' => $orderId, 'token' => $token, 'charge_idx' => $chargeIdx];
    }

    private function loadCharge(int $idx): array
    {
        $model = new pix_charges_model();
        $model->set_filter(["idx = ?"], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    private function loadOrder(int $idx): array
    {
        $model = new orders_model();
        $model->set_filter(["idx = ?"], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    public function testUnknownGatewaySlugReturns404(): void
    {
        $controller = new webhook_controller();
        $result = $controller->processEvent('gateway-que-nao-existe', '{}', []);

        $this->assertSame(404, $result['code']);
    }

    public function testInvalidSignatureReturns401(): void
    {
        // PagBankGateway::verifyWebhook exige x-authenticity-token = sha256(token
        // + '-' + rawBody). Um header arbitrario nunca bate — falha fechada,
        // configurado ou nao.
        $controller = new webhook_controller();
        $result = $controller->processEvent(
            'pagbank',
            '{"qr_codes":[{"id":"QRCO_teste"}]}',
            ['x-authenticity-token' => 'assinatura-forjada-e-invalida']
        );

        $this->assertSame(401, $result['code']);
        $this->assertSame('invalid signature', $result['body']['error'] ?? null);
    }

    public function testValidPagBankSignaturePassesAuthCheck(): void
    {
        if (!defined('PAGBANK_TOKEN') || (string)constant('PAGBANK_TOKEN') === '') {
            $this->markTestSkipped('PAGBANK_TOKEN nao configurado neste ambiente.');
        }

        // HMAC calculado no proprio teste (mesma formula do adapter) — nao bate
        // na rede, so confirma que a assinatura correta passa no verifyWebhook().
        $rawBody = '{"qr_codes":[{"id":"QRCO_inexistente_no_banco"}]}';
        $signature = hash('sha256', (string)constant('PAGBANK_TOKEN') . '-' . $rawBody);

        $controller = new webhook_controller();
        $result = $controller->processEvent('pagbank', $rawBody, ['x-authenticity-token' => $signature]);

        // Assinatura valida passa a checagem; a cobranca nao existe no banco ->
        // ignorado (200), nunca 401.
        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['body']['ignored'] ?? false);
    }

    /**
     * Fecha o gap apontado na revisao do plano 026: MercadoPagoGatewayTest.php
     * testa MercadoPagoGateway::verifyWebhook() diretamente, mas nenhum teste
     * exercitava webhook_controller::processEvent() repassando o 4o argumento
     * $query ate o gateway — exatamente o fio que o fix deste plano corrigiu
     * (verifyWebhook($rawBody, $headers, []) sempre, antes da correcao). Aqui o
     * data.id chega SO na query (chave "data_id", forma real do $_GET — ver
     * PixGateway::verifyWebhook), nunca no body, provando que o controller
     * repassa a query recebida ate o adapter.
     */
    public function testMercadoPagoValidSignatureWithDataIdOnlyInQueryPassesAuthCheck(): void
    {
        if (!defined('MP_WEBHOOK_SECRET') || (string)constant('MP_WEBHOOK_SECRET') === '') {
            $this->markTestSkipped('MP_WEBHOOK_SECRET nao configurado neste ambiente.');
        }

        $secret = (string)constant('MP_WEBHOOK_SECRET');
        $rawBody = '{"type":"payment"}'; // sem data.id no body de proposito
        $ts = '1700000000';
        $requestId = 'req-ship-026';
        $dataId = 'charge-inexistente-no-banco';
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, $secret);

        $controller = new webhook_controller();
        $result = $controller->processEvent(
            'mercadopago',
            $rawBody,
            [
                'x-signature'  => "ts={$ts},v1={$v1}",
                'x-request-id' => $requestId,
            ],
            ['data_id' => $dataId]
        );

        // Assinatura valida passa a checagem; a cobranca nao existe no banco ->
        // ignorado (200), nunca 401 (mesmo padrao de testValidPagBankSignaturePassesAuthCheck).
        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['body']['ignored'] ?? false);
    }

    public function testChargeNotFoundIsIgnoredWith200(): void
    {
        $controller = new webhook_controller();
        // InfinitePay: sem assinatura real (verifyWebhook sempre true), isola o
        // teste no comportamento de "cobranca desconhecida".
        $result = $controller->processEvent(
            'infinitepay',
            json_encode(['order_nsu' => 'token-que-nunca-existiu-' . uniqid(), 'paid_amount' => 100]),
            []
        );

        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['body']['ignored'] ?? false);
    }

    public function testAmountLessThanOrderTotalDoesNotMarkAsPaid(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');

        $ctx = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'ignored-below', // sobrescrito abaixo pelo token real
            chargeStatus: 'pendente',
            totalCents: 10000,
            amountCents: 10000
        );

        // gateway_charge_id do InfinitePay e o token do pedido — recria a cobranca
        // com o valor correto ja que o token so existe depois do insert do pedido.
        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(["idx = ?"], [$ctx['charge_idx']]);
        $chargeUpdate->populate(['gateway_charge_id' => $ctx['token']]);
        $chargeUpdate->save();

        $rawBody = json_encode(['order_nsu' => $ctx['token'], 'paid_amount' => 5000]); // < 10000

        // NOTA (plano 031): este corpo nao tem transaction_nsu/slug, entao
        // confirmPayment() falha fechado no gate de reconfirmacao (achado como
        // "nao reconfirmavel") ANTES de chegar na comparacao de valor abaixo — a
        // razao de nao marcar pago mudou de "valor menor" para "nao reconfirmado",
        // mas a garantia observavel do teste (webhook insuficiente nunca marca
        // pago) continua valendo e testada de ponta a ponta.
        $controller = new webhook_controller();
        $result = $controller->processEvent('infinitepay', (string)$rawBody, []);

        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['body']['ok'] ?? false);

        $charge = $this->loadCharge($ctx['charge_idx']);
        $order  = $this->loadOrder($ctx['order_id']);

        $this->assertSame('pendente', $charge['status']);
        $this->assertNull($charge['paid_at']);
        $this->assertSame('aguardando_pagamento', $order['status']);
        $this->assertNull($order['paid_at']);
    }

    /**
     * Achado da revisao adversarial do /ship (red team + Codex, convergentes):
     * uma falha transitoria ao chamar o PSP (rede, config ausente, HTTP != 2xx)
     * precisa responder nao-2xx pra InfinitePay tentar de novo depois — nunca
     * 200, que faria o PSP achar que o webhook foi tratado e nao reenviar (sem
     * endpoint de reconciliacao, o pagamento ficaria perdido pra sempre).
     *
     * So roda quando INFINITEPAY_HANDLE nao esta configurado (kernel.php.example,
     * caso comum em CI) — mesmo padrao de skip ja usado acima para
     * MP_WEBHOOK_SECRET/PAGBANK_TOKEN. Um corpo com transaction_nsu/slug
     * presentes bate primeiro nesse gate (a ordem das checagens em
     * confirmPayment() foi corrigida de proposito para isso — ver o metodo).
     */
    public function testInfinitePayMissingHandleReturnsRetriable502(): void
    {
        if (!defined('INFINITEPAY_HANDLE') || (string)constant('INFINITEPAY_HANDLE') !== '') {
            $this->markTestSkipped('INFINITEPAY_HANDLE configurado neste ambiente — nada a testar aqui.');
        }

        $ctx = $this->createOrderWithCharge(
            gatewayId: $this->gatewayIdBySlug('infinitepay'),
            gatewayChargeId: 'ignored-below',
            chargeStatus: 'pendente',
            totalCents: 10000,
            amountCents: 10000
        );
        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(["idx = ?"], [$ctx['charge_idx']]);
        $chargeUpdate->populate(['gateway_charge_id' => $ctx['token']]);
        $chargeUpdate->save();

        $rawBody = json_encode([
            'order_nsu'       => $ctx['token'],
            'transaction_nsu' => 'uuid-' . uniqid(),
            'slug'            => 'invoice-' . uniqid(),
            'paid_amount'     => 10000,
        ]);

        $controller = new webhook_controller();
        $result = $controller->processEvent('infinitepay', (string)$rawBody, []);

        $this->assertSame(502, $result['code']);
    }

    // NAO existe um testAmountMeetingOrderTotalIsAcceptedByValueCheck() aqui de
    // proposito. paid_amount >= total_cents e a UNICA condicao que faz
    // processEvent() atravessar save()+save()+`$orderUpdate->commit()` de
    // verdade (ver docblock da classe) — no singleton compartilhado por todo o
    // processo PHPUnit, esse commit flush permanentemente TODOS os dados de
    // teste acumulados ate aquele ponto (nao so as 2 linhas deste teste), e
    // nada reabre a transacao depois (localPDO::getInstance() so chama
    // beginTransaction() na primeira construcao). Um teste que force esse
    // caminho contaminaria o MySQL compartilhado a cada execucao da suite,
    // mesmo limpando suas proprias linhas depois. A idempotencia abaixo cobre
    // a mesma logica de "valor aceito -> status pago" pre-semeando o estado
    // final em vez de deriva-lo por um commit real.

    public function testAlreadyPaidChargeIsIdempotentOnRedelivery(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $firstPaidAt = '2026-01-01 10:00:00';

        // gateway_charge_id do InfinitePay e o token do pedido — cria a cobranca
        // ja 'pago' direto (sem passar por processEvent()) e depois aponta
        // gateway_charge_id para o token real do pedido recem-criado.
        $ctx = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'ignored-below',
            chargeStatus: 'pago',
            totalCents: 10000,
            amountCents: 10000,
            paidAt: $firstPaidAt
        );

        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(["idx = ?"], [$ctx['charge_idx']]);
        $chargeUpdate->populate(['gateway_charge_id' => $ctx['token']]);
        $chargeUpdate->save();

        // Reentrega do mesmo evento: InfinitePay nao assina webhooks
        // (verifyWebhook sempre true), entao a chamada alcanca de fato o
        // branch de idempotencia ("ja pago -> ok, sem nova escrita").
        $rawBody = json_encode(['order_nsu' => $ctx['token'], 'paid_amount' => 10000]);

        $controller = new webhook_controller();
        $result = $controller->processEvent('infinitepay', (string)$rawBody, []);

        $this->assertSame(200, $result['code']);
        $this->assertTrue($result['body']['ok'] ?? false);

        $charge = $this->loadCharge($ctx['charge_idx']);
        $order  = $this->loadOrder($ctx['order_id']);

        // "1 transicao": o paid_at continua exatamente o valor semeado — nenhuma
        // nova escrita ocorreu por causa desta reentrega.
        $this->assertSame($firstPaidAt, (string)$charge['paid_at']);
        $this->assertSame($firstPaidAt, (string)$order['paid_at']);
        $this->assertSame('pago', $charge['status']);
        $this->assertSame('pago', $order['status']);
    }

    /**
     * Garante em nivel de banco (migration 042) que o mesmo transaction_nsu real
     * da InfinitePay nao pode ficar gravado em duas cobrancas diferentes — a
     * defesa contra reenviar um pagamento legitimo pra confirmar um pedido
     * diferente. O webhook_controller ja faz uma checagem previa (achado do
     * /ship, especialista de seguranca), mas essa checagem sozinha tem uma
     * janela de corrida (TOCTOU); quem fecha de verdade e a UNIQUE key.
     */
    public function testTransactionNsuUniqueConstraintBlocksReuseAcrossCharges(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $sharedTransactionNsu = 'txn-' . uniqid();

        $ctxA = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'chg-a-' . uniqid(),
            chargeStatus: 'pago',
            totalCents: 1000,
            amountCents: 1000,
            paidAt: '2026-01-01 10:00:00'
        );
        $chargeA = new pix_charges_model();
        $chargeA->set_filter(["idx = ?"], [$ctxA['charge_idx']]);
        $chargeA->populate(['transaction_nsu' => $sharedTransactionNsu]);
        $chargeA->save();

        $ctxB = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'chg-b-' . uniqid(),
            chargeStatus: 'pendente',
            totalCents: 5000,
            amountCents: 5000
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database error');

        $chargeB = new pix_charges_model();
        $chargeB->set_filter(["idx = ?"], [$ctxB['charge_idx']]);
        $chargeB->populate(['transaction_nsu' => $sharedTransactionNsu]);
        $chargeB->save();
    }

    /**
     * Achado do /ship (red team): verifyWebhook() do InfinitePay sempre retorna
     * true, entao sem rate limit o dono de um pedido pendente poderia martelar
     * o proprio token e segurar workers do PHP-FPM na chamada de rede de
     * confirmPayment() (ate 10s cada) ate esgotar o pool. O rate limit roda
     * ANTES de confirmPayment(), entao nenhuma das chamadas aqui bate rede —
     * todas fecham no fail-closed de "sem transaction_nsu/slug", exceto a que
     * estoura o limite, que fecha antes disso, com 429.
     */
    public function testInfinitePayWebhookRateLimitBlocksExcessiveAttempts(): void
    {
        $ctx = $this->createOrderWithCharge(
            gatewayId: $this->gatewayIdBySlug('infinitepay'),
            gatewayChargeId: 'ignored-below',
            chargeStatus: 'pendente',
            totalCents: 10000,
            amountCents: 10000
        );
        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(["idx = ?"], [$ctx['charge_idx']]);
        $chargeUpdate->populate(['gateway_charge_id' => $ctx['token']]);
        $chargeUpdate->save();

        $rawBody = json_encode(['order_nsu' => $ctx['token'], 'paid_amount' => 10000]);
        $controller = new webhook_controller();

        for ($i = 0; $i < 10; $i++) {
            $result = $controller->processEvent('infinitepay', (string)$rawBody, []);
            $this->assertSame(200, $result['code'], "tentativa " . ($i + 1) . " deveria passar do rate limit");
        }

        $result = $controller->processEvent('infinitepay', (string)$rawBody, []);
        $this->assertSame(429, $result['code']);
    }

    /**
     * Achado da revisao adversarial do /ship: gateway_charge_id usa collation
     * utf8mb4_unicode_ci (case-insensitive) — sem normalizar a chave do rate
     * limit, reenviar o MESMO order_nsu com letras hex em caixa alta bateria
     * na mesma cobranca no banco mas ganharia um balde de rate limit novo a
     * cada variacao de caixa, driblando o limite indefinidamente.
     */
    public function testInfinitePayWebhookRateLimitIsCaseInsensitive(): void
    {
        $ctx = $this->createOrderWithCharge(
            gatewayId: $this->gatewayIdBySlug('infinitepay'),
            gatewayChargeId: 'ignored-below',
            chargeStatus: 'pendente',
            totalCents: 10000,
            amountCents: 10000
        );
        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(["idx = ?"], [$ctx['charge_idx']]);
        $chargeUpdate->populate(['gateway_charge_id' => $ctx['token']]);
        $chargeUpdate->save();

        $controller = new webhook_controller();
        $lowerBody = json_encode(['order_nsu' => $ctx['token'], 'paid_amount' => 10000]);
        $upperBody = json_encode(['order_nsu' => strtoupper($ctx['token']), 'paid_amount' => 10000]);

        for ($i = 0; $i < 10; $i++) {
            $body = $i % 2 === 0 ? $lowerBody : $upperBody;
            $result = $controller->processEvent('infinitepay', (string)$body, []);
            $this->assertSame(200, $result['code'], "tentativa " . ($i + 1) . " deveria passar do rate limit");
        }

        // 11a tentativa, alternando de novo o caso — deve continuar contando no
        // MESMO balde das anteriores, nao um balde novo.
        $result = $controller->processEvent('infinitepay', $upperBody, []);
        $this->assertSame(429, $result['code']);
    }

    /**
     * Achado do /ship (red team, plano 032): guarda de corrida entre o webhook
     * e o job de expiracao (OrderExpirer). Antes do plano 032, orders.status
     * nunca chegava a 'expirado', entao a escrita incondicional de 'pago' era
     * inofensiva. Agora que o job escreve 'expirado' de verdade (e devolve o
     * estoque), um webhook atrasado que chegasse depois da expiracao reviveria
     * o pedido como 'pago' sem re-reservar estoque — overselling. A correcao
     * (mesmo commit) trocou o UPDATE incondicional por
     * `WHERE idx = ? AND status <> 'expirado'`.
     *
     * NAO e possivel provar isto via processEvent() de ponta a ponta neste
     * ambiente: alcancar a escrita real de 'pago' exige confirmacao positiva
     * de um PSP real (MP_WEBHOOK_SECRET/PAGBANK_TOKEN nao configurados aqui —
     * mesma limitacao ja documentada no docblock desta classe — e o InfinitePay
     * falha fechado sem rede quando INFINITEPAY_HANDLE nao esta configurado,
     * nunca chegando a `paid=true`). Este teste isola a garantia que importa —
     * a query condicional em si — replicando exatamente as duas escritas de
     * `webhook_controller::processEvent()` (linhas do guard de corrida) contra
     * um pedido/cobranca ja 'expirado' e confirmando 0 linhas afetadas e nenhum
     * dado sobrescrito. Mesma tecnica de "reproduzir a sequencia sem chamar o
     * metodo que termina em exit()/rede" ja usada em
     * OrderFeeBreakdownPersistenceTest para finalize().
     */
    public function testLatePaymentGuardNeverOverwritesAlreadyExpiredOrder(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $ctx = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'guard-test-' . uniqid(),
            chargeStatus: 'pendente',
            totalCents: 10000,
            amountCents: 10000
        );

        // Simula o que o OrderExpirer ja teria feito: pedido e cobranca
        // marcados 'expirado' antes do webhook atrasado chegar.
        $expireOrder = new orders_model();
        $expireOrder->set_filter(['idx = ?'], [$ctx['order_id']]);
        $expireOrder->populate(['status' => 'expirado']);
        $expireOrder->save();

        $expireCharge = new pix_charges_model();
        $expireCharge->set_filter(['idx = ?'], [$ctx['charge_idx']]);
        $expireCharge->populate(['status' => 'expirado']);
        $expireCharge->save();

        $paidAt = date('Y-m-d H:i:s');

        // Replica exatamente o guard de webhook_controller::processEvent().
        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(['idx = ?', "status <> 'expirado'"], [$ctx['charge_idx']]);
        $chargeUpdate->populate(['status' => 'pago', 'paid_at' => $paidAt]);
        $chargeResult = $chargeUpdate->save();

        $orderUpdate = new orders_model();
        $orderUpdate->set_filter(['idx = ?', "status <> 'expirado'"], [$ctx['order_id']]);
        $orderUpdate->populate(['status' => 'pago', 'paid_at' => $paidAt]);
        $orderResult = $orderUpdate->save();

        $this->assertInstanceOf(\PDOStatement::class, $chargeResult);
        $this->assertInstanceOf(\PDOStatement::class, $orderResult);
        $this->assertSame(0, $chargeResult->rowCount(), 'guard deve impedir a escrita na cobranca ja expirada');
        $this->assertSame(0, $orderResult->rowCount(), 'guard deve impedir a escrita no pedido ja expirado');

        $charge = $this->loadCharge($ctx['charge_idx']);
        $order  = $this->loadOrder($ctx['order_id']);
        $this->assertSame('expirado', $charge['status'], 'pagamento atrasado nunca deve reviver cobranca ja expirada');
        $this->assertSame('expirado', $order['status'], 'pagamento atrasado nunca deve reviver pedido ja expirado (overselling)');
        $this->assertNull($charge['paid_at']);
        $this->assertNull($order['paid_at']);
    }

    /**
     * Contraprova do teste acima: para um pedido AINDA 'aguardando_pagamento',
     * o mesmo guard (`status <> 'expirado'`) nao bloqueia a escrita legitima —
     * confirma que a correcao so afeta o caso de corrida, nao o fluxo normal.
     */
    public function testLatePaymentGuardAllowsWriteWhenOrderNotExpired(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $ctx = $this->createOrderWithCharge(
            gatewayId: $gatewayId,
            gatewayChargeId: 'guard-test-' . uniqid(),
            chargeStatus: 'pendente',
            totalCents: 10000,
            amountCents: 10000
        );

        $paidAt = date('Y-m-d H:i:s');

        $chargeUpdate = new pix_charges_model();
        $chargeUpdate->set_filter(['idx = ?', "status <> 'expirado'"], [$ctx['charge_idx']]);
        $chargeUpdate->populate(['status' => 'pago', 'paid_at' => $paidAt]);
        $chargeResult = $chargeUpdate->save();

        $orderUpdate = new orders_model();
        $orderUpdate->set_filter(['idx = ?', "status <> 'expirado'"], [$ctx['order_id']]);
        $orderUpdate->populate(['status' => 'pago', 'paid_at' => $paidAt]);
        $orderResult = $orderUpdate->save();

        $this->assertSame(1, $chargeResult->rowCount(), 'guard nao deve bloquear cobranca ainda pendente');
        $this->assertSame(1, $orderResult->rowCount(), 'guard nao deve bloquear pedido ainda aguardando pagamento');

        $charge = $this->loadCharge($ctx['charge_idx']);
        $order  = $this->loadOrder($ctx['order_id']);
        $this->assertSame('pago', $charge['status']);
        $this->assertSame('pago', $order['status']);
    }
}
