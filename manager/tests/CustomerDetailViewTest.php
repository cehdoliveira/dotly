<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a renderizacao de ui/page/customer_detail.php (/clientes/{idx}, plano 023).
 * customers_controller::show() so monta $customer/$orders/$summary/$isBlocked; esta
 * suite garante que a linha do tempo do historico de compras exibe cada pedido com
 * seu status e que nenhum valor cru (nome do cliente) escapa sem htmlspecialchars.
 *
 * Nao precisa de banco (TestCase puro): a view so consome os arrays ja carregados.
 */
final class CustomerDetailViewTest extends TestCase
{
    /** @var mixed */
    private $sessionBackup = null;
    /** @var array<string,mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [
            constant("cAppKey") => ["credential" => ["name" => "Admin Teste", "idx" => 1]],
            '_csrf_token'       => 'tok-csrf',
        ];

        $urls = [
            'home_url' => '/', 'customers_url' => '/clientes', 'customer_url' => '/clientes/%d',
            'products_url' => '/produtos', 'orders_url' => '/pedidos', 'order_url' => '/pedidos/%d',
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

    /** @return array<string,mixed> */
    private function fixture(array $overrides = []): array
    {
        return array_merge([
            'customer'  => [
                'customer_name' => 'Fulano de Tal', 'customer_mail' => 'fulano@example.com',
                'customer_phone' => '11999998888', 'customer_cpf' => '12345678909',
                'ship_city' => 'São Paulo', 'ship_uf' => 'SP',
            ],
            'orders'    => [
                ['idx' => 51, 'token' => 't2', 'status' => 'pago', 'total_cents' => 16800,
                 'created_at' => '2026-07-10 14:30:00', 'paid_at' => '2026-07-10 14:35:00', 'shipped_at' => null],
                ['idx' => 42, 'token' => 't1', 'status' => 'expirado', 'total_cents' => 5000,
                 'created_at' => '2026-06-01 10:00:00', 'paid_at' => null, 'shipped_at' => null],
            ],
            'summary'   => ['orders_count' => 2, 'paid_cents' => 16800,
                            'first_purchase' => '2026-06-01 10:00:00', 'last_purchase' => '2026-07-10 14:30:00'],
            'isBlocked'   => false,
            'blockedIdx'  => 0,
        ], $overrides);
    }

    private function render(array $data): string
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        ob_start();
        try {
            (function () use ($data) {
                $customer   = $data['customer'];
                $orders     = $data['orders'];
                $summary    = $data['summary'];
                $isBlocked  = $data['isBlocked'];
                $blockedIdx = $data['blockedIdx'];
                include dirname(__DIR__) . '/public_html/ui/page/customer_detail.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            restore_error_handler();
            throw $e;
        }
        restore_error_handler();

        return ob_get_clean();
    }

    public function testRendersPurchaseTimeline(): void
    {
        $html = $this->render($this->fixture());

        $this->assertStringContainsString('Histórico de Compras', $html);
        $this->assertStringContainsString('Pedido #51', $html);
        $this->assertStringContainsString('Pedido #42', $html);
        $this->assertStringContainsString('Pago', $html);
        $this->assertStringContainsString('Expirado', $html);
        $this->assertStringContainsString('/pedidos/51', $html, 'Nó do histórico deve linkar para o pedido');
    }

    public function testShowsSummaryTotals(): void
    {
        $html = $this->render($this->fixture());

        $this->assertStringContainsString('R$ 168,00', $html, 'Total pago do resumo');
        $this->assertStringContainsString('123.456.789-09', $html, 'CPF com máscara');
        $this->assertStringContainsString('(11) 99999-8888', $html, 'Telefone com máscara');
    }

    public function testBlockedBadgeAndNoBlockButton(): void
    {
        $blocked = $this->render($this->fixture(['isBlocked' => true]));
        $this->assertStringContainsString('Bloqueado', $blocked);
        $this->assertStringNotContainsString('confirmBlock', $blocked, 'Cliente já bloqueado não mostra ação de bloquear');

        $active = $this->render($this->fixture());
        $this->assertStringContainsString('confirmBlock', $active, 'Cliente ativo mostra ação de bloquear');
    }

    /**
     * Plano 030 (redesenhado apos revisao adversarial): cliente bloqueado mostra
     * o form de Desbloquear com o idx EXATO da linha de blocked_customers
     * (blockedIdx, calculado por blockedIdxSql() no controller) — nunca o idx do
     * pedido-ancora, que poderia levar o submit a casar a linha de outro cliente.
     * Cliente ativo nao mostra esse form.
     */
    public function testBlockedShowsUnblockFormWithCorrectActionAndIdx(): void
    {
        $blocked = $this->render($this->fixture(['isBlocked' => true, 'blockedIdx' => 987]));
        $this->assertStringContainsString('name="action" value="desbloquear"', $blocked);
        $this->assertStringContainsString('name="blocked_idx" value="987"', $blocked, 'blocked_idx do form de desbloquear deve ser a linha exata de blocked_customers');
        $this->assertStringContainsString('Desbloquear', $blocked);

        $active = $this->render($this->fixture());
        $this->assertStringNotContainsString('value="desbloquear"', $active, 'Cliente ativo não mostra ação de desbloquear');
    }

    /**
     * Se isBlocked vier true mas blockedIdx nao vier preenchido (0 — nao deveria
     * acontecer em producao, mas e defesa em profundidade), o form de Desbloquear
     * nao deve renderizar sem um alvo valido pra submeter.
     */
    public function testBlockedWithoutBlockedIdxHidesUnblockForm(): void
    {
        $html = $this->render($this->fixture(['isBlocked' => true, 'blockedIdx' => 0]));
        $this->assertStringNotContainsString('value="desbloquear"', $html);
    }

    public function testEscapesCustomerName(): void
    {
        $data = $this->fixture();
        $data['customer']['customer_name'] = '<script>alert(1)</script>';
        $html = $this->render($data);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
