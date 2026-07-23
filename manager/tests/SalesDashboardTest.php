<?php

declare(strict_types=1);

/**
 * Cobre as agregacoes de site_controller::salesDashboard() (Plano 011): faturamento
 * pago do mes, ticket medio, pedidos aguardando, contagem por status (30d), top
 * produtos (30d) e ultimos pedidos.
 *
 * Os metodos sao chamados diretamente (nao salesDashboard(), que faz include de
 * view) — mesmo padrao de checkout_controller::lockAndValidateCart(): extraidos
 * sem side-effect para serem testaveis.
 *
 * Todas as fixtures de pedido usam created_at = NOW() (DOLModel::save() forca
 * created_at no INSERT, sem forma de sobrescrever), o que as coloca sempre
 * dentro das janelas de "mes corrente" e "30 dias". Como a suite de testes
 * mantem uma unica transacao aberta durante todo o processo (localPDO::getInstance()
 * e singleton — ver DBTestCase), dados de outros testes podem estar visiveis;
 * por isso as asserções comparam o resultado ANTES/DEPOIS de inserir as fixtures
 * (delta), nunca um total absoluto.
 *
 * IMPORTANTE (achado durante a implementacao deste teste): o rollback de
 * `localPDO::getInstance()` roda no `__destruct()` do singleton, mas em
 * ambiente de dev local (phpunit executado via `docker run` por processo) isso
 * NAO se mostrou confiavel — fixtures ficaram commitadas no banco real entre
 * execucoes. `testTopProductsOrdersByQuantityDescending()` (LIMIT 5 global, sem
 * filtro por marker) e a unica sensivel a isso; por seguranca esta classe faz
 * limpeza explicita (soft-delete via remove()) de tudo que cria, no tearDown().
 */
final class SalesDashboardTest extends DBTestCase
{
    /** @var int[] */
    private array $createdOrderItemIds = [];

    /** @var int[] */
    private array $createdOrderIds = [];

    /** @var int[] */
    private array $createdProductIds = [];

    /** @var int[] */
    private array $createdPixChargeIds = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPixChargeIds as $id) {
            $model = new pix_charges_model();
            $model->set_filter(['idx = ?'], [$id]);
            $model->remove();
        }
        foreach ($this->createdOrderItemIds as $id) {
            $model = new order_items_model();
            $model->set_filter(['idx = ?'], [$id]);
            $model->remove();
        }
        foreach ($this->createdOrderIds as $id) {
            $model = new orders_model();
            $model->set_filter(['idx = ?'], [$id]);
            $model->remove();
        }
        foreach ($this->createdProductIds as $id) {
            $model = new products_model();
            $model->set_filter(['idx = ?'], [$id]);
            $model->remove();
        }

        parent::tearDown();
    }

    private function makeOrder(
        string $status,
        ?string $paidAt,
        int $totalCents,
        int $expiresInMinutes = 30
    ): int {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => $status,
            'customer_name'  => 'Cliente Dashboard ' . uniqid(),
            'customer_mail'  => 'dashboard_' . uniqid() . '@example.com',
            'customer_phone' => '11999999999',
            'customer_cpf'   => '12345678909',
            'ship_zip'       => '01000000',
            'ship_street'    => 'Rua Teste',
            'ship_number'    => '100',
            'ship_district'  => 'Centro',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => $totalCents,
            'expires_at'     => date('Y-m-d H:i:s', strtotime(sprintf('%+d minutes', $expiresInMinutes))),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de pedido deve retornar um ID valido');
        $this->createdOrderIds[] = $id;

        if ($paidAt !== null) {
            $update = new orders_model();
            $update->set_filter(['idx = ?'], [$id]);
            $update->populate(['paid_at' => $paidAt]);
            $update->save();
        }

        return $id;
    }

    private function makeProduct(int $stock = 100): int
    {
        $product = new products_model();
        $product->populate([
            'name'             => 'Produto Dashboard ' . uniqid(),
            'slug'             => 'produto-dashboard-' . uniqid(),
            'category'         => 'placeholder',
            'price_unit_cents' => 100,
            'box_qty'          => 1,
            'stock'            => $stock,
        ]);
        $id = (int) $product->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de produto deve retornar um ID valido');
        $this->createdProductIds[] = $id;

        return $id;
    }

    private function makeOrderItem(int $orderId, int $productId, string $productName, int $qty): void
    {
        $item = new order_items_model();
        $item->populate([
            'orders_id'        => $orderId,
            'products_id'      => $productId,
            'product_name'     => $productName,
            'variant'          => 'unit',
            'qty'              => $qty,
            'unit_price_cents' => 1000,
            'line_total_cents' => $qty * 1000,
        ]);
        $id = (int) $item->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de item deve retornar um ID valido');
        $this->createdOrderItemIds[] = $id;
    }

    public function testSalesKpisSumsOnlyPaidOrdersOfCurrentMonth(): void
    {
        $controller = new site_controller();
        $before = $controller->salesKpis();

        $this->makeOrder('pago', date('Y-m-d H:i:s'), 10000);
        $this->makeOrder('pago', date('Y-m-d H:i:s', strtotime('-2 months')), 5000);
        $this->makeOrder('aguardando_pagamento', null, 3000);

        $after = $controller->salesKpis();

        $this->assertSame($before['revenue_cents'] + 10000, $after['revenue_cents'], 'faturamento deve somar so o pedido pago dentro do mes corrente');
        $this->assertSame($before['paid_orders'] + 1, $after['paid_orders'], 'contagem de pedidos pagos deve ignorar o pago fora do mes e o nao pago');
        $this->assertSame(
            (int) round($after['revenue_cents'] / max(1, $after['paid_orders'])),
            $after['avg_ticket_cents'],
            'ticket medio deve ser faturamento / pedidos pagos'
        );
    }

    public function testSalesKpisAwaitingCountsOnlyFutureExpiresAt(): void
    {
        $controller = new site_controller();
        $before = $controller->salesKpis();

        // salesKpis() compara expires_at contra um "agora" calculado pelo PHP
        // (bind), nao SQL NOW() — evita o skew de fuso entre o clock do container
        // MySQL (UTC) e o PHP (America/Sao_Paulo, UTC-3). Buffer de 30min (a
        // mesma janela real de expiracao do checkout) ja e suficiente.
        $this->makeOrder('aguardando_pagamento', null, 1000, expiresInMinutes: 30);
        $this->makeOrder('aguardando_pagamento', null, 1000, expiresInMinutes: -30);

        $after = $controller->salesKpis();

        $this->assertSame($before['awaiting'] + 1, $after['awaiting'], 'so o pedido com expires_at futuro deve contar como aguardando');
    }

    public function testOrdersByStatusCountsRecentOrdersByStatus(): void
    {
        $controller = new site_controller();
        $before = $controller->ordersByStatus();

        $this->makeOrder('pago', date('Y-m-d H:i:s'), 1000);
        $this->makeOrder('pago', date('Y-m-d H:i:s'), 1000);
        $this->makeOrder('aguardando_pagamento', null, 1000);
        $this->makeOrder('cancelado', null, 1000);

        $after = $controller->ordersByStatus();

        $this->assertSame($before['pago'] + 2, $after['pago']);
        $this->assertSame($before['aguardando_pagamento'] + 1, $after['aguardando_pagamento']);
        $this->assertSame($before['cancelado'] + 1, $after['cancelado']);
        $this->assertSame($before['expirado'], $after['expirado'], 'status sem fixture nova nao deve mudar');
    }

    public function testOrdersByStatusCountsExpiradoOrders(): void
    {
        $controller = new site_controller();
        $before = $controller->ordersByStatus();

        $this->makeOrder('expirado', null, 1000);

        $after = $controller->ordersByStatus();

        $this->assertSame($before['expirado'] + 1, $after['expirado'], 'pedido expirado deve contar na chave expirado');
        $this->assertSame($before['pago'], $after['pago'], 'nao deve afetar a contagem de outros status');
    }

    public function testTopProductsExcludesItemsFromUnpaidOrders(): void
    {
        $paidOrderId    = $this->makeOrder('pago', date('Y-m-d H:i:s'), 30000);
        $unpaidOrderId  = $this->makeOrder('aguardando_pagamento', null, 30000);

        $paidProduct   = $this->makeProduct();
        $unpaidProduct = $this->makeProduct();

        // Quantidade bem acima de qualquer fixture (inclusive de outros
        // metodos de teste desta classe) para garantir presenca no top 5
        // mesmo com residuo pre-existente no banco (ver nota sobre
        // isolamento de transacao acima). A exclusao do produto nao pago,
        // por outro lado, e estrutural (filtro `o.status = 'pago'` na
        // query) — nao depende de ranking, entao a quantidade dele e
        // irrelevante para a asserção. Limitado a 1_000_000: qty * 1000
        // (unit_price_cents fixo em makeOrderItem) precisa caber em
        // order_items.line_total_cents (INT UNSIGNED, max ~4.29 bilhoes).
        $this->makeOrderItem($paidOrderId, $paidProduct, 'Produto Pago ' . uniqid(), 1000000);
        $this->makeOrderItem($unpaidOrderId, $unpaidProduct, 'Produto Nao Pago ' . uniqid(), 700000);

        $controller = new site_controller();
        $result = $controller->topProducts();

        $productIds = array_column($result, 'products_id');

        $this->assertContains($paidProduct, $productIds, 'item de pedido pago deve aparecer no top produtos');
        $this->assertNotContains($unpaidProduct, $productIds, 'item de pedido nao pago (aguardando_pagamento) nunca deve aparecer no top produtos');
    }

    public function testTopProductsOrdersByQuantityDescending(): void
    {
        $orderId = $this->makeOrder('pago', date('Y-m-d H:i:s'), 30000);

        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $p3 = $this->makeProduct();

        // Quantidades bem acima do que qualquer pedido real ou outra fixture
        // poderia somar no periodo de 30 dias: topProducts() e LIMIT 5 sobre
        // TODOS os pedidos pagos da tabela (sem filtro por marker), entao o
        // teste precisa garantir que a fixture domine o ranking mesmo com
        // dados pre-existentes no banco (ex.: `localPDO` so faz rollback no
        // __destruct() do singleton — nao ha isolamento por teste garantido
        // neste ambiente, dados de execucoes anteriores podem persistir).
        $this->makeOrderItem($orderId, $p1, 'Produto A ' . uniqid(), 500000);
        $this->makeOrderItem($orderId, $p2, 'Produto B ' . uniqid(), 300000);
        $this->makeOrderItem($orderId, $p3, 'Produto C ' . uniqid(), 100000);

        $controller = new site_controller();
        $result = $controller->topProducts();

        $mine = array_values(array_filter(
            $result,
            static fn(array $r): bool => in_array($r['products_id'], [$p1, $p2, $p3], true)
        ));

        $this->assertCount(3, $mine, 'os 3 produtos da fixture devem aparecer no top 5 (qty alta o suficiente para nao ser deslocada)');
        $this->assertSame($p1, $mine[0]['products_id']);
        $this->assertSame(500000, $mine[0]['total_qty']);
        $this->assertSame($p2, $mine[1]['products_id']);
        $this->assertSame(300000, $mine[1]['total_qty']);
        $this->assertSame($p3, $mine[2]['products_id']);
        $this->assertSame(100000, $mine[2]['total_qty']);
    }

    private function firstGatewayId(): int
    {
        $model = new payment_gateways_model();
        $model->set_field([' idx ']);
        $model->set_filter([" active = 'yes' "]);
        $model->set_order([' idx ASC ']);
        $model->set_paginate([1]);
        $model->load_data(false);

        return (int)($model->data[0]['idx'] ?? 0);
    }

    private function makePixCharge(int $orderId, int $gatewayId, int $amountCents): void
    {
        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $orderId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => 'test_' . uniqid(),
            'status'              => 'pago',
            'amount_cents'        => $amountCents,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'paid_at'             => date('Y-m-d H:i:s'),
        ]);
        $id = (int)$charge->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de cobranca pix deve retornar um ID valido');
        $this->createdPixChargeIds[] = $id;
    }

    public function testPaymentGatewaysSumsOnlyPaidOrdersOfCurrentMonthPerGateway(): void
    {
        $gatewayId = $this->firstGatewayId();
        if ($gatewayId <= 0) {
            $this->markTestSkipped('Nenhum gateway ativo cadastrado no banco de testes.');
        }

        $controller = new site_controller();

        // paymentGateways() nao expoe idx por linha, entao a asserção compara o
        // delta do faturamento agregado (soma de mtd_cents de todos os gateways)
        // antes/depois das fixtures — mesmo padrao delta do resto desta suite.
        $totalBefore = array_sum(array_column($controller->paymentGateways(), 'mtd_cents'));

        $paidOrderId   = $this->makeOrder('pago', date('Y-m-d H:i:s'), 12345);
        $unpaidOrderId = $this->makeOrder('aguardando_pagamento', null, 99999);
        $this->makePixCharge($paidOrderId, $gatewayId, 12345);
        $this->makePixCharge($unpaidOrderId, $gatewayId, 99999);

        $totalAfter = array_sum(array_column($controller->paymentGateways(), 'mtd_cents'));

        $this->assertSame(
            $totalBefore + 12345,
            $totalAfter,
            'faturamento por gateway deve somar so o pedido pago (via pix_charges), ignorando o aguardando'
        );
    }

    public function testRecentOrdersReturnsAtMostTenMostRecentFirst(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->makeOrder('pago', date('Y-m-d H:i:s'), 1000 + $i);
        }

        $controller = new site_controller();
        $result = $controller->recentOrders();

        $this->assertLessThanOrEqual(10, count($result), 'recentOrders() nunca deve devolver mais de 10 linhas');
        $this->assertCount(10, $result, 'com 12+ pedidos no banco, deve devolver exatamente 10');

        for ($i = 0; $i < count($result) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                strtotime($result[$i + 1]['created_at']),
                strtotime($result[$i]['created_at']),
                'deve vir ordenado do mais recente para o mais antigo'
            );
        }
    }
}
