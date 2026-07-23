<?php

declare(strict_types=1);

/**
 * Cobre SalesWindow::status(): precedencia override > janela de datas >
 * estoque, exatamente como especificado no plano 037. Estende DBTestCase
 * porque SalesWindow le `settings` e `products`.
 */
final class SalesWindowTest extends DBTestCase
{
    private function setSetting(string $key, string $value): void
    {
        $model = new settings_model();
        $model->execute_raw_prepared(
            "INSERT IGNORE INTO settings (created_at, created_by, active, skey, svalue) VALUES (?, 0, 'yes', ?, '')",
            [date('Y-m-d H:i:s'), $key]
        );
        $model->execute_raw_prepared(
            "UPDATE settings SET svalue = ?, active = 'yes' WHERE skey = ?",
            [$value, $key]
        );
    }

    /** Zera o estoque de TODOS os produtos (dentro da transacao do teste, com rollback automatico). */
    private function drainAllStock(): void
    {
        $model = new products_model();
        $model->execute_raw_prepared("UPDATE products SET stock = 0", []);
    }

    /** Garante que existe ao menos um produto vendivel. */
    private function ensureSellableProduct(): void
    {
        $model = new products_model();
        $model->populate([
            'name'             => 'Produto Janela ' . uniqid(),
            'slug'             => 'produto-janela-' . uniqid(),
            'category'         => 'peptideos',
            'is_infinity'      => 'no',
            'price_unit_cents' => 5000,
            'box_qty'          => 10,
            'stock'            => 10,
        ]);
        $model->save();
    }

    public function testDefaultsWithSellableProductAreOpen(): void
    {
        $this->setSetting('sales_override', '');
        $this->setSetting('sales_window_start_at', '');
        $this->setSetting('sales_window_end_at', '');
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertTrue($status['open']);
        $this->assertNull($status['reason']);
    }

    public function testOverrideClosedForcesClosed(): void
    {
        $this->setSetting('sales_override', 'closed');
        $this->setSetting('sales_window_start_at', '');
        $this->setSetting('sales_window_end_at', '');
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertFalse($status['open']);
        $this->assertSame('override', $status['reason']);
        $this->assertNull($status['reopens_at']);
    }

    public function testOverrideClosedWinsOverOpenWindowAndStock(): void
    {
        $this->setSetting('sales_override', 'closed');
        $this->setSetting('sales_window_start_at', date('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->setSetting('sales_window_end_at', date('Y-m-d H:i:s', strtotime('+1 hour')));
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertFalse($status['open']);
        $this->assertSame('override', $status['reason']);
    }

    public function testOverrideOpenWinsOverClosedWindowAndEmptyStock(): void
    {
        $this->setSetting('sales_override', 'open');
        $this->setSetting('sales_window_start_at', '');
        $this->setSetting('sales_window_end_at', date('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->drainAllStock();

        $status = SalesWindow::status();

        $this->assertTrue($status['open']);
        $this->assertNull($status['reason']);
    }

    public function testCurrentWindowWithStockIsOpen(): void
    {
        $this->setSetting('sales_override', '');
        $this->setSetting('sales_window_start_at', date('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->setSetting('sales_window_end_at', date('Y-m-d H:i:s', strtotime('+1 hour')));
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertTrue($status['open']);
        $this->assertNull($status['reason']);
    }

    public function testFutureWindowIsClosedWithReopensAt(): void
    {
        $this->setSetting('sales_override', '');
        $start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $this->setSetting('sales_window_start_at', $start);
        $this->setSetting('sales_window_end_at', '');
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertFalse($status['open']);
        $this->assertSame('window', $status['reason']);
        $this->assertSame($start, $status['reopens_at']);
    }

    public function testEndedWindowIsClosedWithoutReopensAt(): void
    {
        $this->setSetting('sales_override', '');
        $this->setSetting('sales_window_start_at', '');
        $this->setSetting('sales_window_end_at', date('Y-m-d H:i:s', strtotime('-1 hour')));
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertFalse($status['open']);
        $this->assertSame('window', $status['reason']);
        $this->assertNull($status['reopens_at']);
    }

    public function testOnlyFutureEndWithStockIsOpen(): void
    {
        $this->setSetting('sales_override', '');
        $this->setSetting('sales_window_start_at', '');
        $this->setSetting('sales_window_end_at', date('Y-m-d H:i:s', strtotime('+1 hour')));
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertTrue($status['open']);
        $this->assertNull($status['reason']);
    }

    public function testEmptyStockWithinWindowIsClosedForStock(): void
    {
        $this->setSetting('sales_override', '');
        $this->setSetting('sales_window_start_at', '');
        $this->setSetting('sales_window_end_at', '');
        $this->drainAllStock();

        $status = SalesWindow::status();

        $this->assertFalse($status['open']);
        $this->assertSame('stock', $status['reason']);
        $this->assertNull($status['reopens_at']);
    }

    public function testCorruptedValuesFailOpen(): void
    {
        $this->setSetting('sales_override', 'talvez');
        $this->setSetting('sales_window_start_at', 'banana');
        $this->setSetting('sales_window_end_at', '');
        $this->ensureSellableProduct();

        $status = SalesWindow::status();

        $this->assertTrue($status['open']);
        $this->assertNull($status['reason']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function postSaleRouteProvider(): array
    {
        return [
            'webhook pix mercadopago' => ['/webhook/pix/mercadopago', true],
            'webhook pix pagbank'     => ['/webhook/pix/pagbank', true],
            'pagamento payment'       => ['/pagamento/abc123', true],
            'pagamento status'       => ['/pagamento/abc123/status', true],
            'pedido done'             => ['/pedido/abc123', true],
            'acompanhar-pedido exato' => ['/acompanhar-pedido', true],
            'acompanhar-pedido barra' => ['/acompanhar-pedido/', true],
            'home'                    => ['/', false],
            'carrinho'                => ['/carrinho', false],
            'produto'                 => ['/produto/peptideo-x', false],
            'checkout'                => ['/checkout', false],
            'acompanhar-pedido prefixo parecido (regressao)' => ['/acompanhar-pedidoXXX', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postSaleRouteProvider')]
    public function testIsPostSaleRouteAllowlist(string $path, bool $expected): void
    {
        $this->assertSame($expected, SalesWindow::isPostSaleRoute($path));
    }
}
