<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a renderizacao de ui/page/customers.php (/clientes, plano 023): as colunas
 * pedidas (nome, e-mail, telefone, cidade/UF, ultima compra), as tres acoes
 * (Detalhes, Último pedido, Bloquear) e o escape de saida. Sem banco (TestCase
 * puro): a view so consome $customers ja agregado pelo controller.
 */
final class CustomersViewTest extends TestCase
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

    /** @param array<int,array<string,mixed>> $customers */
    private function render(array $customers): string
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        ob_start();
        try {
            (function () use ($customers) {
                $totalPages = 0;
                include dirname(__DIR__) . '/public_html/ui/page/customers.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            restore_error_handler();
            throw $e;
        }
        restore_error_handler();

        return ob_get_clean();
    }

    /** @return array<int,array<string,mixed>> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'last_order_idx' => 42, 'customer_name' => 'Fulano de Tal',
            'customer_mail' => 'fulano@example.com', 'customer_phone' => '11999998888',
            'customer_cpf' => '12345678909', 'ship_city' => 'São Paulo', 'ship_uf' => 'SP',
            'last_purchase' => '2026-07-10 14:30:00', 'orders_count' => 3, 'is_blocked' => 0,
            'blocked_idx' => null,
        ], $overrides);
    }

    public function testRendersColumnsAndActions(): void
    {
        $html = $this->render([$this->row()]);

        $this->assertStringContainsString('fulano@example.com', $html);
        $this->assertStringContainsString('(11) 99999-8888', $html, 'Telefone com máscara');
        $this->assertStringContainsString('São Paulo / SP', $html);
        $this->assertStringContainsString('10/07/2026', $html, 'Data da última compra');
        $this->assertStringContainsString('/clientes/42', $html, 'Ação Detalhes');
        $this->assertStringContainsString('/pedidos/42', $html, 'Ação Último pedido');
        $this->assertStringContainsString('confirmBlock', $html, 'Ação Bloquear');
    }

    public function testBlockedCustomerHidesBlockAction(): void
    {
        $html = $this->render([$this->row(['is_blocked' => 1])]);

        $this->assertStringContainsString('Bloqueado', $html);
        $this->assertStringNotContainsString('confirmBlock', $html);
    }

    /**
     * Plano 030 (redesenhado apos revisao adversarial): cliente bloqueado mostra
     * o form de Desbloquear com o idx EXATO da linha de blocked_customers
     * (blocked_idx, vindo de blockedIdxSql() no controller) no lugar do antigo
     * botão desabilitado — nunca mais o idx do pedido-ancora.
     */
    public function testBlockedCustomerShowsUnblockFormWithCorrectActionAndIdx(): void
    {
        $html = $this->render([$this->row(['is_blocked' => 1, 'blocked_idx' => 654])]);

        $this->assertStringContainsString('name="action" value="desbloquear"', $html);
        $this->assertStringContainsString('name="blocked_idx" value="654"', $html, 'blocked_idx do form de desbloquear deve ser a linha exata de blocked_customers');
        $this->assertStringContainsString('Desbloquear', $html);

        $active = $this->render([$this->row()]);
        $this->assertStringNotContainsString('value="desbloquear"', $active, 'Cliente ativo não mostra ação de desbloquear');
    }

    public function testEmptyState(): void
    {
        $html = $this->render([]);
        $this->assertStringContainsString('Nenhum cliente ainda', $html);
    }

    public function testRendersFilterBarAndSortableHeaders(): void
    {
        $html = $this->render([$this->row()]);

        // Campos de busca pedidos.
        $this->assertStringContainsString('name="nome"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="telefone"', $html);
        $this->assertStringContainsString('name="data_inicio"', $html);
        $this->assertStringContainsString('name="data_fim"', $html);

        // Cabeçalhos ordenáveis: links com sort/dir e default apontando p/ asc.
        $this->assertStringContainsString('sort=nome', $html);
        $this->assertStringContainsString('sort=ultima_compra', $html);
        $this->assertStringContainsString('orders-sort-link', $html);
    }

    public function testEscapesCustomerName(): void
    {
        $html = $this->render([$this->row(['customer_name' => '<b>x</b>'])]);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }
}
