<?php

declare(strict_types=1);

/**
 * Cobre OrderExpirer::expireDueOrders() (Plano 032): expira pedidos
 * `aguardando_pagamento` vencidos e devolve o estoque reservado no checkout.
 * Extraida de site/cgi-bin/expire_orders.php para ser testavel (o script
 * cron em si nao roda sob PHPUnit) — mesmo padrao de EmailQueueDispatcher
 * (plano 016).
 *
 * IMPORTANTE (mesma limitacao documentada em EmailQueueDispatcherTest e
 * WebhookIdempotencyTest): expireDueOrders() comita por pedido, explicitamente,
 * na conexao singleton compartilhada por todo o processo PHPUnit
 * (localPDO::getInstance()). Isso e intencional na producao (limita o blast
 * radius de uma falha a 1 pedido), mas em teste significa que os fixtures
 * criados aqui (e qualquer outro dado acumulado no processo ate aquele ponto)
 * sao commitados de verdade na base de dev compartilhada, sem rollback
 * possivel no tearDown() depois disso. Aceito conscientemente, seguindo o
 * mesmo precedente ja usado em EmailQueueDispatcherTest.
 */
final class OrderExpirerTest extends DBTestCase
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

    private function createProduct(int $stock, int $boxQty = 10): int
    {
        $model = new products_model();
        $model->populate([
            'name'             => 'Produto Teste ' . uniqid(),
            'slug'             => 'produto-teste-' . uniqid(),
            'category'         => 'peptideos',
            'price_unit_cents' => 5000,
            'box_qty'          => $boxQty,
            'stock'            => $stock,
        ]);
        $id = $model->save();
        $this->assertIsInt($id);

        return $id;
    }

    private function createOrder(string $status, string $expiresAt): int
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
            'expires_at'      => $expiresAt,
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        return $orderId;
    }

    private function createOrderItem(int $ordersId, int $productsId, string $variant, int $qty): int
    {
        $item = new order_items_model();
        $item->populate([
            'orders_id'        => $ordersId,
            'products_id'      => $productsId,
            'product_name'     => 'Produto Teste',
            'variant'          => $variant,
            'qty'              => $qty,
            'unit_price_cents' => 5000,
            'line_total_cents' => 5000 * $qty,
        ]);
        $id = $item->save();
        $this->assertIsInt($id);

        return $id;
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

    private function loadProductStock(int $idx): int
    {
        $model = new products_model();
        $model->set_field([' stock ']);
        $model->set_filter(['idx = ?'], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return (int)($model->data[0]['stock'] ?? -1);
    }

    private function loadCharge(int $idx): array
    {
        $model = new pix_charges_model();
        $model->set_filter(['idx = ?'], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    public function testDueOrderIsExpiredAndUnitStockRestocked(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $productId = $this->createProduct(stock: 20);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $orderId = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderId, $productId, 'unit', 3);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);

        $now = date('Y-m-d H:i:s');
        $summary = (new OrderExpirer())->expireDueOrders($now);

        $this->assertGreaterThanOrEqual(1, $summary['expired']);

        $order = $this->loadOrder($orderId);
        $this->assertSame('expirado', $order['status']);
        $this->assertSame(23, $this->loadProductStock($productId), '20 em estoque + 3 devolvidas (variant unit, qty=3)');

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('expirado', $charge['status']);
    }

    /**
     * Achado do red-team (/ship): a migracao pro update() do DOLModel fazia
     * expireOne() perder o carimbo explicito de modified_at em PHP (a coluna
     * passava a vir do now() do MySQL). OrderReconciler::alertRecentlyExpiredPaidCharges()
     * compara orders.modified_at contra uma janela calculada em PHP -- e o
     * container MySQL deste ambiente tem skew de fuso real (~3h) com o PHP
     * (America/Sao_Paulo), o mesmo skew ja documentado noutro lugar do repo.
     * Corrigido sobrescrevendo modified_at explicitamente no update() (ultima
     * atribuicao a uma coluna repetida no SET vence no MySQL). Este teste
     * prova, pelo caminho real de escrita (nao um bypass via execute_raw_prepared
     * como o resto da suite de OrderReconciler faz), que modified_at bate com
     * o relogio do PHP, nao do MySQL.
     */
    public function testExpiredOrderModifiedAtMatchesPhpClockNotMysqlClock(): void
    {
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $orderId = $this->createOrder('aguardando_pagamento', $past);

        $phpNow = date('Y-m-d H:i:s');
        (new OrderExpirer())->expireOne($orderId, $phpNow);

        // orders_model::$field nao inclui modified_at por padrao (loadOrder()
        // usaria o default e traria null) -- select() direto pra buscar so essa coluna.
        $stmt = (new orders_model())->select([' modified_at '], 'WHERE idx = ?', [$orderId]);
        $modifiedAt = $stmt->fetch(PDO::FETCH_ASSOC)['modified_at'] ?? null;
        $this->assertNotNull($modifiedAt);

        $diffSeconds = abs(strtotime($modifiedAt) - strtotime($phpNow));

        $this->assertLessThan(
            5,
            $diffSeconds,
            'modified_at deveria bater com o relogio do PHP (tolerancia de execucao), nao com now() do MySQL (que teria um offset de horas se os relogios divergirem)'
        );
    }

    public function testBoxVariantRestocksQtyTimesBoxQty(): void
    {
        $productId = $this->createProduct(stock: 50, boxQty: 10);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $orderId = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderId, $productId, 'box', 3);

        $now = date('Y-m-d H:i:s');
        (new OrderExpirer())->expireDueOrders($now);

        // 50 em estoque + 3 caixas * 10 unidades/caixa = 80.
        $this->assertSame(80, $this->loadProductStock($productId));

        $order = $this->loadOrder($orderId);
        $this->assertSame('expirado', $order['status']);
    }

    public function testNotYetDueOrderIsIgnored(): void
    {
        $productId = $this->createProduct(stock: 10);
        $future = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $orderId = $this->createOrder('aguardando_pagamento', $future);
        $this->createOrderItem($orderId, $productId, 'unit', 2);

        $now = date('Y-m-d H:i:s');
        (new OrderExpirer())->expireDueOrders($now);

        $order = $this->loadOrder($orderId);
        $this->assertSame('aguardando_pagamento', $order['status'], 'expires_at no futuro nao deve ser tocado');
        $this->assertSame(10, $this->loadProductStock($productId), 'estoque nao deve ser alterado para pedido nao vencido');
    }

    public function testAlreadyPaidOrderIsNeverRestocked(): void
    {
        // Prova a guarda de corrida: mesmo com expires_at no passado, um
        // pedido que ja saiu de 'aguardando_pagamento' (ex. webhook marcou
        // 'pago' entre o SELECT dos candidatos e a expiracao) nunca e tocado
        // — o UPDATE condicional (WHERE status = 'aguardando_pagamento') so
        // afeta a linha se ela AINDA estiver pendente.
        $productId = $this->createProduct(stock: 15);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $orderId = $this->createOrder('pago', $past);
        $this->createOrderItem($orderId, $productId, 'unit', 5);

        $now = date('Y-m-d H:i:s');
        (new OrderExpirer())->expireDueOrders($now);

        $order = $this->loadOrder($orderId);
        $this->assertSame('pago', $order['status'], 'pedido ja pago nunca deve virar expirado');
        $this->assertSame(15, $this->loadProductStock($productId), 'estoque de pedido pago nunca e estornado (overselling)');
    }

    public function testSecondRunIsIdempotentAndDoesNotDoubleRestock(): void
    {
        $productId = $this->createProduct(stock: 10);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $orderId = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderId, $productId, 'unit', 4);

        $now = date('Y-m-d H:i:s');
        $expirer = new OrderExpirer();
        $expirer->expireDueOrders($now);

        $this->assertSame(14, $this->loadProductStock($productId), 'primeira rodada devolve 4 unidades (10 + 4)');

        // Segunda rodada: o pedido ja esta 'expirado', entao nao aparece mais
        // como candidato ('aguardando_pagamento') — nao deve devolver de novo.
        $summaryTwo = $expirer->expireDueOrders($now);

        $this->assertSame(14, $this->loadProductStock($productId), 'segunda rodada nao pode devolver estoque em dobro');

        $order = $this->loadOrder($orderId);
        $this->assertSame('expirado', $order['status']);
    }

    public function testMultiOrderBatchProcessesAllCandidatesInOnePass(): void
    {
        $productId = $this->createProduct(stock: 100);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $orderIdA = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderIdA, $productId, 'unit', 2);

        $orderIdB = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderIdB, $productId, 'unit', 5);

        $now = date('Y-m-d H:i:s');
        $summary = (new OrderExpirer())->expireDueOrders($now);

        $this->assertGreaterThanOrEqual(2, $summary['expired'], 'as 2 candidatas do lote devem ser processadas na mesma chamada');
        $this->assertSame('expirado', $this->loadOrder($orderIdA)['status']);
        $this->assertSame('expirado', $this->loadOrder($orderIdB)['status']);
        $this->assertSame(107, $this->loadProductStock($productId), '100 + 2 (pedido A) + 5 (pedido B)');
    }

    public function testMultiItemOrderSumsRestockAcrossLines(): void
    {
        $productA = $this->createProduct(stock: 10);
        $productB = $this->createProduct(stock: 20, boxQty: 4);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $orderId = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderId, $productA, 'unit', 3);
        $this->createOrderItem($orderId, $productB, 'box', 2);

        $now = date('Y-m-d H:i:s');
        (new OrderExpirer())->expireDueOrders($now);

        $this->assertSame('expirado', $this->loadOrder($orderId)['status']);
        $this->assertSame(13, $this->loadProductStock($productA), '10 + 3 (variant unit)');
        $this->assertSame(28, $this->loadProductStock($productB), '20 + 2*4 (variant box, box_qty=4)');
    }

    /**
     * Achado do /ship (revisao adversarial, confirmado empiricamente contra
     * MySQL 8.0): um UPDATE multi-tabela aplica o SET uma unica vez por linha
     * alvo, mesmo quando ela casa com VARIAS linhas do JOIN — nao soma entre
     * os matches. Cart.php documenta que unidade solta e caixa do MESMO
     * produto sao linhas DISTINTAS do carrinho ("a mesma dosagem em unidade e
     * em caixa sao linhas distintas do carrinho"), entao um pedido comum pode
     * ter 2 order_items ativos com o MESMO products_id. Sem o pre-agregado
     * por products_id, so uma das duas linhas seria somada ao estoque —
     * diferente de testMultiItemOrderSumsRestockAcrossLines() (2 PRODUTOS
     * diferentes), que nao exercita esse caso.
     */
    public function testSameProductWithBothVariantsInOneOrderSumsBothLines(): void
    {
        $productId = $this->createProduct(stock: 100, boxQty: 10);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $orderId = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderId, $productId, 'unit', 3);
        $this->createOrderItem($orderId, $productId, 'box', 2);

        $now = date('Y-m-d H:i:s');
        $summary = (new OrderExpirer())->expireDueOrders($now);

        $this->assertSame('expirado', $this->loadOrder($orderId)['status']);
        // 100 + 3 (unit) + 2*10 (box, box_qty=10) = 123. Sem o fix, um UPDATE
        // multi-tabela sem agregacao aplicaria so 1 das 2 linhas ao estoque
        // (resultado arbitrario: 103 ou 120), nunca 123.
        $this->assertSame(123, $this->loadProductStock($productId));
        $this->assertSame(23, $summary['restocked_units']);
    }

    public function testSoftDeletedOrderItemExcludedFromRestock(): void
    {
        $productId = $this->createProduct(stock: 10);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $orderId = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderId, $productId, 'unit', 3);
        $removedItemId = $this->createOrderItem($orderId, $productId, 'unit', 100);
        (new order_items_model())->execute_raw_prepared(
            'UPDATE order_items SET active = ? WHERE idx = ?',
            ['no', $removedItemId]
        );

        $now = date('Y-m-d H:i:s');
        (new OrderExpirer())->expireDueOrders($now);

        $this->assertSame('expirado', $this->loadOrder($orderId)['status']);
        $this->assertSame(13, $this->loadProductStock($productId), '10 + 3 (item ativo); o item soft-deletado (qty=100) nao entra na conta');
    }

    public function testSoftDeletedOrderNeverPicksUpAsCandidate(): void
    {
        $productId = $this->createProduct(stock: 10);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $orderId = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderId, $productId, 'unit', 5);
        (new orders_model())->execute_raw_prepared(
            'UPDATE orders SET active = ? WHERE idx = ?',
            ['no', $orderId]
        );

        $now = date('Y-m-d H:i:s');
        (new OrderExpirer())->expireDueOrders($now);

        $order = $this->loadOrder($orderId);
        $this->assertSame('aguardando_pagamento', $order['status'], 'pedido soft-deletado nunca deve ser selecionado como candidato');
        $this->assertSame(10, $this->loadProductStock($productId), 'estoque nao deve ser tocado para pedido soft-deletado');
    }

    /**
     * Achado do /ship (especialista de testes): o catch(\Throwable) por pedido
     * dentro de expireDueOrders() (rollback + skip, sem derrubar o lote inteiro)
     * nao tinha cobertura. Testavel sem injecao de falha no banco: expireOne()
     * e publico e nao-final, entao uma subclasse pode forcar a excecao para um
     * pedido especifico do lote e provar que o commit do pedido anterior
     * sobrevive.
     */
    public function testOneFailingOrderDoesNotRollbackPreviouslyCommittedOrdersInBatch(): void
    {
        $productId = $this->createProduct(stock: 100);
        $past = date('Y-m-d H:i:s', strtotime('-1 minute'));

        $orderIdA = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderIdA, $productId, 'unit', 2);

        $orderIdB = $this->createOrder('aguardando_pagamento', $past);
        $this->createOrderItem($orderIdB, $productId, 'unit', 5);

        $expirer = new class extends OrderExpirer {
            public int $failOrderId = 0;
            public function expireOne(int $ordersId, string $now): ?int
            {
                if ($ordersId === $this->failOrderId) {
                    throw new \RuntimeException('forced failure for test');
                }
                return parent::expireOne($ordersId, $now);
            }
        };
        $expirer->failOrderId = $orderIdB;

        $now = date('Y-m-d H:i:s');
        $summary = $expirer->expireDueOrders($now);

        $this->assertSame(1, $summary['expired']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(1, $summary['errored'], 'falha forcada deve contar como errored, nao skipped (guarda de corrida)');
        $this->assertSame('expirado', $this->loadOrder($orderIdA)['status'], 'pedido A deve permanecer expirado mesmo com falha no pedido B');
        $this->assertSame(102, $this->loadProductStock($productId), 'estoque do pedido A deve permanecer devolvido (nao sofre rollback pela falha do pedido B)');
        $this->assertSame('aguardando_pagamento', $this->loadOrder($orderIdB)['status'], 'pedido que falhou deve permanecer intocado para retry no proximo ciclo');
    }
}
