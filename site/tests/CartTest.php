<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a API pura de Cart (sem DB): add/setQty/remove/count, clamps de
 * qty/variant e a regra inegociavel de que a sessao nunca guarda preco.
 * hydrate() (le `products` do banco) e coberto por CartHydrateTest.
 */
final class CartTest extends TestCase
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

    public function testAddCreatesLineWithProductAndVariant(): void
    {
        Cart::add(12, 'unit', 2);

        $rows = Cart::all();

        $this->assertArrayHasKey('12:unit', $rows);
        $this->assertSame(12, $rows['12:unit']['products_id']);
        $this->assertSame('unit', $rows['12:unit']['variant']);
        $this->assertSame(2, $rows['12:unit']['qty']);
    }

    public function testAddAccumulatesQtyOnSameLine(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::add(12, 'unit', 3);

        $rows = Cart::all();

        $this->assertSame(5, $rows['12:unit']['qty']);
    }

    public function testUnitAndBoxAreDistinctLines(): void
    {
        Cart::add(12, 'unit', 1);
        Cart::add(12, 'box', 1);

        $rows = Cart::all();

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('12:unit', $rows);
        $this->assertArrayHasKey('12:box', $rows);
    }

    public function testAddRejectsInvalidVariant(): void
    {
        Cart::add(12, 'hack', 1);

        $this->assertSame([], Cart::all());
    }

    public function testAddRejectsNonPositiveProductId(): void
    {
        Cart::add(0, 'unit', 1);
        Cart::add(-5, 'unit', 1);

        $this->assertSame([], Cart::all());
    }

    public function testAddClampsQtyToMax99(): void
    {
        Cart::add(12, 'unit', 500);

        $rows = Cart::all();

        $this->assertSame(99, $rows['12:unit']['qty']);
    }

    public function testAddClampsQtyToMin1(): void
    {
        Cart::add(12, 'unit', -10);

        $rows = Cart::all();

        $this->assertSame(1, $rows['12:unit']['qty']);
    }

    public function testSetQtyReplacesQuantity(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::setQty(12, 'unit', 7);

        $rows = Cart::all();

        $this->assertSame(7, $rows['12:unit']['qty']);
    }

    public function testSetQtyZeroRemovesLine(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::setQty(12, 'unit', 0);

        $this->assertSame([], Cart::all());
    }

    public function testSetQtyNegativeRemovesLine(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::setQty(12, 'unit', -3);

        $this->assertSame([], Cart::all());
    }

    public function testSetQtyRejectsInvalidVariant(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::setQty(12, 'hack', 5);

        $rows = Cart::all();
        $this->assertSame(2, $rows['12:unit']['qty']);
        $this->assertArrayNotHasKey('12:hack', $rows);
    }

    public function testRemoveDropsOnlyTargetLine(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::add(13, 'unit', 1);

        Cart::remove(12, 'unit');

        $rows = Cart::all();
        $this->assertArrayNotHasKey('12:unit', $rows);
        $this->assertArrayHasKey('13:unit', $rows);
    }

    public function testClearEmptiesCart(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::add(13, 'box', 1);

        Cart::clear();

        $this->assertSame([], Cart::all());
    }

    public function testCountSumsQtyAcrossLines(): void
    {
        Cart::add(12, 'unit', 2);
        Cart::add(13, 'box', 3);

        $this->assertSame(5, Cart::count());
    }

    public function testCountIsZeroForEmptyCart(): void
    {
        $this->assertSame(0, Cart::count());
    }

    /**
     * Regra de seguranca inegociavel: a sessao nunca guarda preco.
     */
    public function testSessionNeverStoresPrice(): void
    {
        Cart::add(12, 'unit', 2);

        $raw = $_SESSION[constant("cAppKey")]["cart"] ?? [];

        foreach ($raw as $row) {
            $this->assertSame(['products_id', 'variant', 'qty'], array_keys($row));
        }
    }
}
