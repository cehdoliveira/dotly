<?php

declare(strict_types=1);

/**
 * Cobre checkout_controller::lockAndValidateCart(): a peca central de
 * "mexer com dinheiro" do checkout — trava estoque, confere quantidade
 * necessaria (unidade vs. caixa) e reconfere preco contra o banco, nunca
 * contra o que veio do carrinho/sessao.
 *
 * Testado diretamente (nao via finalize()) porque finalize() termina em
 * basic_redir() -> exit(), que nao pode ser exercitado em PHPUnit (mesmo
 * padrao documentado em AuthFunctionsTest para validate_csrf).
 */
final class CheckoutStockTest extends DBTestCase
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

    public function testInsufficientStockDoesNotCreateOrder(): void
    {
        $id = $this->createProduct(['stock' => 2]);

        $controller = new checkout_controller();
        $result = $controller->lockAndValidateCart([
            ['products_id' => $id, 'variant' => 'unit', 'qty' => 5, 'name' => 'Ipamorelin'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Ipamorelin', $result['message']);
        $this->assertStringContainsString('2 unidades', $result['message']);

        // lockAndValidateCart nao escreve nada — o estoque continua intacto.
        $this->assertSame(2, $this->currentStock($id));
    }

    public function testBoxVariantRequiresQtyTimesBoxQtyUnits(): void
    {
        $id = $this->createProduct(['stock' => 100, 'box_qty' => 10, 'price_unit_cents' => 5000]);

        $controller = new checkout_controller();
        $result = $controller->lockAndValidateCart([
            ['products_id' => $id, 'variant' => 'box', 'qty' => 3, 'name' => 'Produto Caixa'],
        ]);

        // Caixa = box_qty (10) unidades ao preco unitario (5000) = 50000/caixa.
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame(30, $result['lines'][0]['units_needed']);
        $this->assertSame(50000, $result['lines'][0]['unit_price_cents']);
        $this->assertSame(150000, $result['lines'][0]['line_total_cents']);
        $this->assertSame(150000, $result['total_cents']);
    }

    public function testBoxVariantInsufficientStockFailsOnTotalUnitsNotBoxCount(): void
    {
        // 25 unidades em estoque, box_qty=10: 2 caixas cabem (20), 3 nao (30).
        $id = $this->createProduct(['stock' => 25, 'box_qty' => 10]);

        $controller = new checkout_controller();
        $result = $controller->lockAndValidateCart([
            ['products_id' => $id, 'variant' => 'box', 'qty' => 3, 'name' => 'Produto Caixa'],
        ]);

        $this->assertFalse($result['ok']);
    }

    public function testTamperedPriceInInputLineIsIgnored(): void
    {
        $id = $this->createProduct(['price_unit_cents' => 5000]);

        $controller = new checkout_controller();
        // Simula uma linha de carrinho adulterada carregando um preco falso —
        // lockAndValidateCart nunca le esse campo do input, so recalcula do banco.
        $result = $controller->lockAndValidateCart([
            [
                'products_id'      => $id,
                'variant'          => 'unit',
                'qty'              => 2,
                'name'             => 'Produto Teste',
                'unit_price_cents' => 1,
                'line_total_cents' => 2,
            ],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(5000, $result['lines'][0]['unit_price_cents']);
        $this->assertSame(10000, $result['lines'][0]['line_total_cents']);
        $this->assertSame(10000, $result['total_cents']);
    }

    public function testInactiveProductFailsValidation(): void
    {
        $id = $this->createProduct();

        $remove = new products_model();
        $remove->set_filter(['idx = ?'], [$id]);
        $remove->remove();

        $controller = new checkout_controller();
        $result = $controller->lockAndValidateCart([
            ['products_id' => $id, 'variant' => 'unit', 'qty' => 1, 'name' => 'Produto Removido'],
        ]);

        $this->assertFalse($result['ok']);
    }
}
