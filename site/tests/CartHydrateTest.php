<?php

declare(strict_types=1);

/**
 * Cobre Cart::hydrate(): preco vem sempre do banco (nunca da sessao), produto
 * inativo some da linha, variante `box` vale preco_unitario * box_qty, e o
 * total bate com a soma das linhas.
 */
final class CartHydrateTest extends DBTestCase
{
    /** @var mixed */
    private $sessionBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup ?? [];
        parent::tearDown();
    }

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

    public function testHydrateReadsPriceFromDatabase(): void
    {
        $id = $this->createProduct(['price_unit_cents' => 7000]);

        Cart::add($id, 'unit', 2);

        [$lines, $totalCents] = Cart::hydrate();

        $this->assertCount(1, $lines);
        $this->assertSame(7000, $lines[0]['unit_price_cents']);
        $this->assertSame(14000, $lines[0]['line_total_cents']);
        $this->assertSame(14000, $totalCents);
    }

    public function testInactiveProductDisappearsFromLine(): void
    {
        $id = $this->createProduct();

        Cart::add($id, 'unit', 1);

        $update = new products_model();
        $update->set_filter(['idx = ?'], [$id]);
        $update->remove();

        [$lines, $totalCents] = Cart::hydrate();

        $this->assertSame([], $lines);
        $this->assertSame(0, $totalCents);
        $this->assertArrayNotHasKey($id . ':unit', Cart::all());
    }

    public function testBoxVariantPricesAtUnitTimesBoxQty(): void
    {
        $id = $this->createProduct(['price_unit_cents' => 5000, 'box_qty' => 10]);

        Cart::add($id, 'box', 1);

        [$lines, $totalCents] = Cart::hydrate();

        $this->assertCount(1, $lines);
        $this->assertSame(50000, $lines[0]['unit_price_cents']);
        $this->assertSame(50000, $lines[0]['line_total_cents']);
        $this->assertSame(50000, $totalCents);
    }

    public function testTotalCentsSumsMultipleLines(): void
    {
        $id1 = $this->createProduct(['price_unit_cents' => 3000]);
        $id2 = $this->createProduct(['price_unit_cents' => 2000, 'box_qty' => 10]);

        Cart::add($id1, 'unit', 2); // 6000
        Cart::add($id2, 'box', 1);  // 2000 * 10 = 20000

        [$lines, $totalCents] = Cart::hydrate();

        $this->assertCount(2, $lines);
        $this->assertSame(26000, $totalCents);
    }

    public function testHydrateWithEmptyCartReturnsEmptyAndZero(): void
    {
        [$lines, $totalCents] = Cart::hydrate();

        $this->assertSame([], $lines);
        $this->assertSame(0, $totalCents);
    }
}
