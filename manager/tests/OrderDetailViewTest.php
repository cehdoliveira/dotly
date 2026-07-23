<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a renderizacao de ui/page/order_detail.php (/pedidos/{idx}). O
 * orders_controller::show() so monta o array $order (+ $gatewayName); esta
 * suite garante que a view exibe todas as informacoes pertinentes (cliente,
 * endereco, itens, taxas, pagamento, envio) e que nenhum valor cru escapa sem
 * htmlspecialchars — mesma lacuna que OrdersViewTest cobre para a listagem.
 *
 * Nao precisa de banco (TestCase puro): a view so consome o array ja carregado
 * pelo controller, entao os dados vao como fixture PHP direta.
 */
final class OrderDetailViewTest extends TestCase
{
    /** @var mixed backup do $_SESSION original, para restaurar no tearDown */
    private $sessionBackup = null;

    /** @var array<string,mixed> backup das entradas de $GLOBALS usadas pela view */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [
            constant("cAppKey") => ["credential" => ["name" => "Admin Teste", "idx" => 1]],
            '_csrf_token'       => 'tok-csrf',
        ];

        // A view inclui a sidebar compartilhada, que le home/orders/products/users.
        $urls = [
            'home_url' => '/', 'customers_url' => '/clientes', 'products_url' => '/produtos',
            'orders_url' => '/pedidos', 'order_url' => '/pedidos/%d',
            'order_ship_url' => '/pedidos/%d/enviar', 'order_label_url' => '/pedidos/%d/etiqueta',
        ];
        foreach ($urls as $key => $value) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
            $GLOBALS[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup ?? [];
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> pedido completo, campo a campo sobrescrevivel */
    private function order(array $overrides = []): array
    {
        return array_merge([
            'idx' => 42, 'token' => 'abc123def456', 'status' => 'pago',
            'customer_name' => 'Fulano de Tal', 'customer_mail' => 'fulano@example.com',
            'customer_phone' => '11999998888', 'customer_cpf' => '12345678909',
            'ship_zip' => '01310100', 'ship_street' => 'Av. Paulista', 'ship_number' => '1000',
            'ship_complement' => 'Sala 5', 'ship_district' => 'Bela Vista',
            'ship_city' => 'São Paulo', 'ship_uf' => 'SP',
            'subtotal_cents' => 10000, 'fee_percent_cents' => 800, 'fee_fixed_cents' => 6000,
            'fee_infinity_cents' => 0, 'total_cents' => 16800,
            'created_at' => '2026-07-10 14:30:00', 'paid_at' => '2026-07-10 14:35:00',
            'expires_at' => '2026-07-10 15:30:00', 'tracking_code' => null, 'shipped_at' => null,
            'items_attach' => [
                ['product_name' => 'Peptídeo X', 'variant' => 'box', 'qty' => 2, 'unit_price_cents' => 5000, 'line_total_cents' => 10000],
            ],
            'charges_attach' => [
                ['status' => 'pago', 'amount_cents' => 16800, 'gateway_charge_id' => 'CHG-99', 'expires_at' => '2026-07-10 15:30:00', 'paid_at' => '2026-07-10 14:35:00'],
            ],
        ], $overrides);
    }

    private function render(array $order, ?string $gatewayName = 'Mercado Pago'): string
    {
        ob_start();
        try {
            (function () use ($order, $gatewayName) {
                include dirname(__DIR__) . '/public_html/ui/page/order_detail.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    private function renderStrict(array $order, ?string $gatewayName = 'Mercado Pago'): string
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            return $this->render($order, $gatewayName);
        } finally {
            restore_error_handler();
        }
    }

    public function testRendersAllSectionsWithoutWarning(): void
    {
        $html = $this->renderStrict($this->order());

        $this->assertStringContainsString('Pedido #42', $html);
        $this->assertStringContainsString('Dados do Comprador', $html);
        $this->assertStringContainsString('Endereço de Entrega', $html);
        $this->assertStringContainsString('Itens do Pedido', $html);
        $this->assertStringContainsString('Pagamento', $html);
        $this->assertStringContainsString('Envio', $html);
    }

    public function testFormatsCpfAndCep(): void
    {
        $html = $this->renderStrict($this->order());

        $this->assertStringContainsString('123.456.789-09', $html, 'CPF deve ser exibido com máscara');
        $this->assertStringContainsString('01310-100', $html, 'CEP deve ser exibido com máscara');
    }

    public function testShowsFeeBreakdownAndTotal(): void
    {
        $html = $this->renderStrict($this->order());

        $this->assertStringContainsString('Subtotal dos itens', $html);
        $this->assertStringContainsString('Taxa de serviço (8%)', $html);
        $this->assertStringContainsString('Taxa fixa', $html);
        $this->assertStringNotContainsString('Taxa Infinity', $html, 'taxa Infinity zerada não deve aparecer');
        $this->assertStringContainsString('R$ 168,00', $html, 'total do pedido');
    }

    public function testEscapesCustomerNameAndToken(): void
    {
        $html = $this->render($this->order([
            'customer_name' => '<script>alert(1)</script>',
            'token'         => '"><script>alert(2)</script>',
        ]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testShowsLabelButtonWithLinkOnlyWhenNotShipped(): void
    {
        $pending = $this->renderStrict($this->order());
        $this->assertStringContainsString('Gerar etiqueta de envio', $pending, 'pedido não enviado deve oferecer a etiqueta');
        $this->assertStringContainsString('/pedidos/42/etiqueta', $pending, 'botão deve apontar para a rota da etiqueta');

        $shipped = $this->renderStrict($this->order([
            'shipped_at' => '2026-07-11 09:00:00', 'tracking_code' => 'BR123456789',
        ]));
        $this->assertStringNotContainsString('Gerar etiqueta de envio', $shipped, 'pedido já enviado não mostra o botão de etiqueta');
    }

    public function testShowsEmptyStateWhenNoCharge(): void
    {
        $html = $this->renderStrict($this->order(['charges_attach' => []]));

        $this->assertStringContainsString('Nenhuma cobrança PIX gerada para este pedido.', $html);
    }

    public function testShowsTrackingFormWhenNotShippedAndCodeWhenShipped(): void
    {
        $pending = $this->renderStrict($this->order());
        $this->assertStringContainsString('name="tracking_code"', $pending, 'pedido não enviado deve ter o form de envio');

        $shipped = $this->renderStrict($this->order([
            'shipped_at' => '2026-07-11 09:00:00', 'tracking_code' => 'BR123456789',
        ]));
        $this->assertStringNotContainsString('name="tracking_code"', $shipped, 'pedido enviado esconde o form');
        $this->assertStringContainsString('BR123456789', $shipped, 'código de rastreio exibido');
        $this->assertStringContainsString('Enviado', $shipped);
    }
}
