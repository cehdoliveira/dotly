<?php

declare(strict_types=1);

/**
 * Cobre OrderPricing::compute(): o unico ponto que aplica as taxas
 * obrigatorias (10% + R$60 fixo + taxa Infinity parametrizavel) sobre o
 * subtotal ja reconferido por checkout_controller::lockAndValidateCart().
 *
 * Estende DBTestCase porque OrderPricing le `settings` (parametros de taxa)
 * e `products` (deteccao de produto Infinity no carrinho).
 */
final class OrderPricingTest extends DBTestCase
{
    private function createProduct(array $overrides = []): int
    {
        $model = new products_model();
        $model->populate(array_merge([
            'name'             => 'Produto Teste ' . uniqid(),
            'slug'             => 'produto-teste-' . uniqid(),
            'category'         => 'peptideos',
            'is_infinity'      => 'no',
            'price_unit_cents' => 5000,
            'box_qty'          => 10,
            'stock'            => 100,
        ], $overrides));
        $id = $model->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function setSetting(string $key, string $value): void
    {
        $model = new settings_model();
        $model->execute_raw_prepared(
            "UPDATE settings SET svalue = ? WHERE skey = ?",
            [$value, $key]
        );
    }

    protected function tearDown(): void
    {
        // Restaura os defaults seedados pela migration 018, independente da
        // ordem de execucao dos testes (mesma conexao/transacao global e
        // compartilhada entre todos os testes do processo).
        $this->setSetting('fee_percent_bps', '1000');
        $this->setSetting('fee_fixed_cents', '6000');
        $this->setSetting('fee_infinity_bps', '0');

        parent::tearDown();
    }

    public function testDefaultParamsWithoutInfinityProduct(): void
    {
        $id = $this->createProduct();

        $lines = [
            ['products_id' => $id, 'line_total_cents' => 10000],
        ];

        $result = OrderPricing::compute($lines, 10000);

        $this->assertSame(10000, $result['subtotal_cents']);
        $this->assertSame(1000, $result['fee_percent_cents']);
        $this->assertSame(6000, $result['fee_fixed_cents']);
        $this->assertSame(0, $result['fee_infinity_cents']);
        $this->assertSame(17000, $result['total_cents']);
    }

    public function testInfinityProductWithInfinityFeeConfigured(): void
    {
        $id = $this->createProduct(['is_infinity' => 'yes']);
        $this->setSetting('fee_infinity_bps', '500');

        $lines = [
            ['products_id' => $id, 'line_total_cents' => 10000],
        ];

        $result = OrderPricing::compute($lines, 10000);

        $this->assertSame(500, $result['fee_infinity_cents']);
        $this->assertSame(10000 + 1000 + 6000 + 500, $result['total_cents']);
    }

    public function testInfinityFeeZeroEvenWithInfinityProductInCart(): void
    {
        $id = $this->createProduct(['is_infinity' => 'yes']);
        // fee_infinity_bps continua '0' (default do tearDown/migration).

        $lines = [
            ['products_id' => $id, 'line_total_cents' => 10000],
        ];

        $result = OrderPricing::compute($lines, 10000);

        $this->assertSame(0, $result['fee_infinity_cents']);
        $this->assertSame(17000, $result['total_cents']);
    }

    public function testFractionalCentsAreTruncatedNotRounded(): void
    {
        $id = $this->createProduct();

        $lines = [
            ['products_id' => $id, 'line_total_cents' => 3333],
        ];

        // 3333 * 1000 / 10000 = 333,3 -> intdiv trunca para 333.
        $result = OrderPricing::compute($lines, 3333);

        $this->assertSame(333, $result['fee_percent_cents']);
    }

    public function testInfinityFeeNotAppliedWhenNoLineProductIsInfinity(): void
    {
        // fee_infinity_bps > 0, mas nenhum produto do carrinho e Infinity —
        // cobre o retorno "false" real de cartHasInfinity() via query no banco
        // (diferente do caso com fee_infinity_bps=0, que nem chega a consultar).
        $this->setSetting('fee_infinity_bps', '500');
        $id = $this->createProduct(['is_infinity' => 'no']);

        $lines = [
            ['products_id' => $id, 'line_total_cents' => 10000],
        ];

        $result = OrderPricing::compute($lines, 10000);

        $this->assertSame(0, $result['fee_infinity_cents']);
        $this->assertSame(17000, $result['total_cents']);
    }

    public function testMixedCartAppliesInfinityFeeOnceWhenAnyLineIsInfinity(): void
    {
        // Carrinho com dois produtos distintos, so um deles Infinity — cobre a
        // deteccao real via IN(...) sobre products_id distintos (nao so o caso
        // de 1 linha) e confirma que a taxa incide 1x sobre o subtotal, nao por
        // produto.
        $this->setSetting('fee_infinity_bps', '500');
        $normalId = $this->createProduct(['is_infinity' => 'no']);
        $infinityId = $this->createProduct(['is_infinity' => 'yes']);

        $lines = [
            ['products_id' => $normalId, 'line_total_cents' => 6000],
            ['products_id' => $infinityId, 'line_total_cents' => 4000],
        ];

        $result = OrderPricing::compute($lines, 10000);

        $this->assertSame(500, $result['fee_infinity_cents']);
        $this->assertSame(10000 + 1000 + 6000 + 500, $result['total_cents']);
    }
}
