<?php

declare(strict_types=1);

/**
 * Cobre o Plano 008: commit do pedido ANTES da chamada HTTP ao PSP, e a
 * compensacao explicita quando createCharge() falha depois desse commit.
 *
 * Moldes: CheckoutStockTest (helpers de produto/estoque) e OrderExpirerTest
 * (montagem de pedido + verificacao de restock, reuso de OrderExpirer::expireOne()).
 *
 * IMPORTANTE (mesma limitacao documentada em OrderExpirerTest e
 * EmailQueueDispatcherTest): persistOrder() e compensateFailedCharge() comitam
 * explicitamente na conexao singleton compartilhada por todo o processo PHPUnit
 * (localPDO::getInstance()) — e exatamente o comportamento que este plano
 * introduz. Os fixtures criados aqui sao commitados de verdade na base de dev
 * compartilhada, sem rollback possivel no tearDown() depois desse ponto. Aceito
 * conscientemente, seguindo o mesmo precedente ja usado em OrderExpirerTest.
 */
final class CheckoutChargeCompensationTest extends DBTestCase
{
    private function createProduct(array $overrides = []): int
    {
        $model = new products_model();
        $model->populate(array_merge([
            'name'             => 'Produto Teste ' . uniqid(),
            'slug'             => 'produto-teste-' . uniqid(),
            'category'         => 'peptideos',
            'price_unit_cents' => 5000,
            'box_qty'          => 10,
            'stock'            => 100,
        ], $overrides));
        $id = $model->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function currentStock(int $productId): int
    {
        $model = new products_model();
        $model->set_field([" stock "]);
        $model->set_filter(["idx = ?"], [$productId]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return (int)($model->data[0]['stock'] ?? -1);
    }

    private function loadOrder(int $orderId): array
    {
        $model = new orders_model();
        $model->set_filter(['idx = ?'], [$orderId]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    private function countOrderItems(int $orderId): int
    {
        $model = new order_items_model();
        $model->set_filter([" active = 'yes' ", " orders_id = ? "], [$orderId]);
        $model->set_order([" idx asc "]);
        $model->load_data(false);

        return count($model->data);
    }

    /** @return array{name:string, mail:string, phone:string, cpf:string, zip:string,
     *   street:string, number:string, complement:string, district:string, city:string,
     *   uf:string} */
    private function customerFixture(): array
    {
        return [
            'name'       => 'Cliente Teste',
            'mail'       => 'teste_' . uniqid() . '@example.com',
            'phone'      => '11999999999',
            'cpf'        => '12345678909',
            'zip'        => '01310100',
            'street'     => 'Av. Paulista',
            'number'     => '1000',
            'complement' => '',
            'district'   => 'Bela Vista',
            'city'       => 'São Paulo',
            'uf'         => 'SP',
        ];
    }

    /** @return array{subtotal_cents:int, fee_percent_cents:int, fee_fixed_cents:int,
     *   fee_infinity_cents:int, total_cents:int} */
    private function pricingFixture(int $subtotalCents): array
    {
        return [
            'subtotal_cents'     => $subtotalCents,
            'fee_percent_cents'  => 0,
            'fee_fixed_cents'    => 0,
            'fee_infinity_cents' => 0,
            'total_cents'        => $subtotalCents,
        ];
    }

    public function testPersistOrderBaixaEstoqueEGravaItens(): void
    {
        // box_qty=10, preco unitario 5000: 1 unidade solta + 1 caixa do MESMO
        // produto (linhas distintas de carrinho, ver Cart.php) — mesmo caso
        // documentado em OrderExpirerTest::testSameProductWithBothVariantsInOneOrderSumsBothLines().
        $productId = $this->createProduct(['stock' => 100, 'box_qty' => 10, 'price_unit_cents' => 5000]);

        $finalLines = [
            [
                'products_id'      => $productId,
                'variant'          => 'unit',
                'qty'              => 3,
                'units_needed'     => 3,
                'name'             => 'Produto Teste',
                'unit_price_cents' => 5000,
                'line_total_cents' => 15000,
            ],
            [
                'products_id'      => $productId,
                'variant'          => 'box',
                'qty'              => 2,
                'units_needed'     => 20,
                'name'             => 'Produto Teste',
                'unit_price_cents' => 50000,
                'line_total_cents' => 100000,
            ],
        ];
        $pricing = $this->pricingFixture(115000);

        $controller = new checkout_controller();
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $orderId = $controller->persistOrder($finalLines, $pricing, $this->customerFixture(), $token, $expiresAt);

        $this->assertGreaterThan(0, $orderId);

        // 100 - 3 (unit) - 20 (box: qty 2 * box_qty 10) = 77.
        $this->assertSame(77, $this->currentStock($productId));

        $order = $this->loadOrder($orderId);
        $this->assertSame($token, $order['token']);
        $this->assertSame('aguardando_pagamento', $order['status']);

        $this->assertSame(2, $this->countOrderItems($orderId));
    }

    public function testCompensacaoDevolveEstoqueEExpiraPedido(): void
    {
        $productId = $this->createProduct(['stock' => 50, 'box_qty' => 10, 'price_unit_cents' => 5000]);

        $finalLines = [[
            'products_id'      => $productId,
            'variant'          => 'unit',
            'qty'              => 4,
            'units_needed'     => 4,
            'name'             => 'Produto Teste',
            'unit_price_cents' => 5000,
            'line_total_cents' => 20000,
        ]];
        $pricing = $this->pricingFixture(20000);

        $controller = new checkout_controller();
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $orderId = $controller->persistOrder($finalLines, $pricing, $this->customerFixture(), $token, $expiresAt);

        // persistOrder() nao comita — simula o commit explicito que finalize()
        // faz antes de chamar o PSP (Plano 008, Step 2).
        localPDO::getInstance()->commit();
        localPDO::getInstance()->beginTransaction();

        $this->assertSame(46, $this->currentStock($productId), '50 - 4 (reserva do pedido)');

        // Simula createCharge() falhando depois do commit do pedido.
        $controller->compensateFailedCharge($orderId);

        $this->assertSame(50, $this->currentStock($productId), 'estoque devolvido ao valor original apos a compensacao');
        $this->assertSame('expirado', $this->loadOrder($orderId)['status']);
    }

    public function testCompensacaoNaoDevolveEstoqueDuasVezes(): void
    {
        // Este e o caso que protege contra overselling: chamar a compensacao
        // duas vezes (ex. retry apos falha de rede na propria compensacao) nao
        // pode devolver o estoque em dobro. A guarda de corrida de
        // OrderExpirer::expireOne() (WHERE status = 'aguardando_pagamento')
        // garante que a segunda chamada nao encontra o pedido mais e retorna null.
        $productId = $this->createProduct(['stock' => 30, 'box_qty' => 10, 'price_unit_cents' => 5000]);

        $finalLines = [[
            'products_id'      => $productId,
            'variant'          => 'unit',
            'qty'              => 5,
            'units_needed'     => 5,
            'name'             => 'Produto Teste',
            'unit_price_cents' => 5000,
            'line_total_cents' => 25000,
        ]];
        $pricing = $this->pricingFixture(25000);

        $controller = new checkout_controller();
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $orderId = $controller->persistOrder($finalLines, $pricing, $this->customerFixture(), $token, $expiresAt);

        localPDO::getInstance()->commit();
        localPDO::getInstance()->beginTransaction();

        $controller->compensateFailedCharge($orderId);
        $this->assertSame(30, $this->currentStock($productId), 'primeira compensacao devolve as 5 unidades (25 + 5)');

        $controller->compensateFailedCharge($orderId);
        $this->assertSame(30, $this->currentStock($productId), 'segunda compensacao nao pode devolver estoque em dobro');

        $this->assertSame('expirado', $this->loadOrder($orderId)['status']);
    }
}
