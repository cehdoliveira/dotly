<?php

declare(strict_types=1);

/**
 * Cobre orders_controller::cancelOrder() (Plano 016): cancelamento
 * administrativo de pedido NAO pago — sai de 'aguardando_pagamento' para
 * 'cancelado' e devolve o estoque reservado, reusando
 * OrderExpirer::expireOne($id, $now, 'cancelado') — a mesma rotina do cron de
 * expiracao (plans/032), com guarda de corrida e pre-agregacao por produto.
 *
 * cancelOrder() e chamado diretamente (nao cancel()/action() via rota):
 * cancel() termina em basic_redir() -> exit(), mesmo motivo documentado em
 * OrderShipTest para markAsShipped()/ship().
 *
 * Diferente de OrderShipTest: cancelOrder() nao enfileira e-mail e por isso
 * nao precisa de commit explicito no meio do metodo (ver docblock de
 * cancelOrder() em orders_controller.php) — os testes aqui ficam dentro da
 * transacao do DBTestCase e sao revertidos normalmente no tearDown, sem
 * necessidade de limpeza manual na base compartilhada.
 */
final class OrderCancelTest extends DBTestCase
{
    private function gatewayIdBySlug(string $slug): int
    {
        $model = new payment_gateways_model();
        $model->set_field([" idx "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $idx = $model->data[0]['idx'] ?? null;
        $this->assertNotNull($idx, "Gateway seed '$slug' nao encontrado (migrations/007_create_table_payment_gateways.sql)");

        return (int)$idx;
    }

    private function createProduct(int $stock, int $boxQty = 10): int
    {
        $model = new products_model();
        $model->populate([
            'name'             => 'Produto Cancelamento ' . uniqid(),
            'slug'             => 'produto-cancelamento-' . uniqid(),
            'category'         => 'peptideos',
            'price_unit_cents' => 5000,
            'box_qty'          => $boxQty,
            'stock'            => $stock,
        ]);
        $id = $model->save();
        $this->assertIsInt($id);

        return $id;
    }

    private function makeOrder(string $status = 'aguardando_pagamento', bool $shipped = false): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => $status,
            'customer_name'  => 'Cliente Cancelamento Teste',
            'customer_mail'  => 'cancelamento_' . uniqid() . '@example.com',
            'customer_phone' => '11999999999',
            'customer_cpf'   => '12345678909',
            'ship_zip'       => '01000000',
            'ship_street'    => 'Rua Teste',
            'ship_number'    => '100',
            'ship_district'  => 'Centro',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => 5000,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'shipped_at'     => $shipped ? date('Y-m-d H:i:s') : null,
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de pedido deve retornar um ID valido');

        return $id;
    }

    private function createOrderItem(int $ordersId, int $productsId, string $variant, int $qty): int
    {
        $item = new order_items_model();
        $item->populate([
            'orders_id'        => $ordersId,
            'products_id'      => $productsId,
            'product_name'     => 'Produto Cancelamento',
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

    private function loadOrder(int $orderId): array
    {
        $model = new orders_model();
        $model->set_filter(['idx = ?'], [$orderId]);
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

    public function testCancelaPedidoAguardandoEDevolveEstoque(): void
    {
        $productId = $this->createProduct(stock: 20, boxQty: 10);
        $orderId = $this->makeOrder('aguardando_pagamento');
        $this->createOrderItem($orderId, $productId, 'unit', 3);
        $this->createOrderItem($orderId, $productId, 'box', 2);

        $controller = new orders_controller();
        $controller->cancelOrder($orderId);

        $order = $this->loadOrder($orderId);
        $this->assertSame('cancelado', $order['status']);
        // 20 + 3 (unit) + 2*10 (box, box_qty=10) = 43.
        $this->assertSame(43, $this->loadProductStock($productId));
    }

    public function testNaoCancelaPedidoPago(): void
    {
        $productId = $this->createProduct(stock: 15);
        $orderId = $this->makeOrder('pago');
        $this->createOrderItem($orderId, $productId, 'unit', 5);

        $controller = new orders_controller();
        $this->expectException(\RuntimeException::class);

        try {
            $controller->cancelOrder($orderId);
        } finally {
            $order = $this->loadOrder($orderId);
            $this->assertSame('pago', $order['status'], 'pedido pago nunca deve virar cancelado');
            $this->assertSame(15, $this->loadProductStock($productId), 'estoque de pedido pago nao pode ser tocado');
        }
    }

    public function testNaoCancelaPedidoJaEnviado(): void
    {
        $productId = $this->createProduct(stock: 10);
        $orderId = $this->makeOrder('aguardando_pagamento', shipped: true);
        $this->createOrderItem($orderId, $productId, 'unit', 2);

        $controller = new orders_controller();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pedido já enviado não pode ser cancelado.');

        try {
            $controller->cancelOrder($orderId);
        } finally {
            $this->assertSame(10, $this->loadProductStock($productId), 'estoque de pedido ja enviado nao pode ser tocado');
        }
    }

    public function testNaoCancelaDuasVezes(): void
    {
        $productId = $this->createProduct(stock: 10);
        $orderId = $this->makeOrder('aguardando_pagamento');
        $this->createOrderItem($orderId, $productId, 'unit', 4);

        $controller = new orders_controller();
        $controller->cancelOrder($orderId);

        $this->assertSame(14, $this->loadProductStock($productId), 'primeiro cancelamento devolve 4 unidades (10 + 4)');

        $this->expectException(\RuntimeException::class);

        try {
            $controller->cancelOrder($orderId);
        } finally {
            $this->assertSame(14, $this->loadProductStock($productId), 'segundo cancelamento nao pode devolver estoque em dobro');
            $order = $this->loadOrder($orderId);
            $this->assertSame('cancelado', $order['status']);
        }
    }

    public function testCobrancaPendenteVaiParaExpirado(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $productId = $this->createProduct(stock: 10);
        $orderId = $this->makeOrder('aguardando_pagamento');
        $this->createOrderItem($orderId, $productId, 'unit', 1);
        $chargeId = $this->createPendingCharge($orderId, $gatewayId);

        $controller = new orders_controller();
        $controller->cancelOrder($orderId);

        $charge = $this->loadCharge($chargeId);
        $this->assertSame('expirado', $charge['status'], 'cobranca pendente do pedido cancelado deve sair de pendente, reusando OrderExpirer');
    }
}
