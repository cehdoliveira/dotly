<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a renderizacao de ui/page/sales_dashboard.php (Plano 011): esta view
 * virou a tela pos-login em `/` e `/admin`, mas SalesDashboardTest.php so
 * cobre as agregacoes SQL, nunca o render em si. Sem isso, um array key
 * indefinido ou um sprintf() quebrado na view so apareceria em producao.
 *
 * Nao precisa de banco (TestCase puro, nao DBTestCase) — a view so consome
 * os arrays ja agregados pelo controller, entao os dados sao passados como
 * fixture PHP direta, sem tocar `orders`/`products`.
 */
final class SalesDashboardViewTest extends TestCase
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
        ];

        $urls = [
            'home_url' => '/', 'customers_url' => '/clientes',
            'profiles_url' => '/perfis', 'products_url' => '/produtos',
            'orders_url' => '/pedidos', 'gateways_url' => '/gateways',
            'logout_url' => '/sair', 'order_url' => '/pedidos/%d',
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

    /**
     * Renderiza a view com as mesmas variaveis que
     * site_controller::salesDashboard() monta, sobrescrevendo so as passadas
     * em $overrides.
     *
     * @param array<string,mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $kpis = array_merge([
            'revenue_cents'    => 123456,
            'paid_orders'      => 7,
            'avg_ticket_cents' => 17636,
            'awaiting'         => 3,
        ], $overrides['kpis'] ?? []);

        $byStatus = $overrides['byStatus'] ?? [
            'aguardando_pagamento' => 3,
            'pago'                 => 7,
            'cancelado'            => 1,
            'expirado'             => 2,
        ];

        $topProd = $overrides['topProd'] ?? [
            ['products_id' => 10, 'product_name' => 'Peptídeo <script>alert(1)</script>', 'total_qty' => 50],
            ['products_id' => 11, 'product_name' => 'Produto B', 'total_qty' => 30],
        ];

        $recent = $overrides['recent'] ?? [
            ['idx' => 501, 'customer_name' => 'Cliente Teste', 'total_cents' => 5000, 'status' => 'pago', 'created_at' => date('Y-m-d H:i:s')],
            ['idx' => 502, 'customer_name' => null, 'total_cents' => 3000, 'status' => 'desconhecido', 'created_at' => date('Y-m-d H:i:s')],
        ];

        $gateways = $overrides['gateways'] ?? [
            ['name' => 'Mercado Pago', 'enabled' => 'yes', 'mtd_cents' => 80000],
            ['name' => 'PagBank <script>alert(1)</script>', 'enabled' => 'no', 'mtd_cents' => 0],
        ];

        ob_start();
        try {
            (function () use ($kpis, $byStatus, $topProd, $recent, $gateways) {
                include dirname(__DIR__) . '/public_html/ui/page/sales_dashboard.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    public function testRendersWithoutFatalErrorOrWarningGivenPopulatedData(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        try {
            $html = $this->render();
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('Dashboard de Vendas', $html);
        $this->assertNotSame('', trim($html), 'a view deve produzir output');
    }

    public function testFormatsCentsAsBrazilianCurrency(): void
    {
        $html = $this->render(['kpis' => [
            'revenue_cents' => 123456, 'paid_orders' => 7, 'avg_ticket_cents' => 17636, 'awaiting' => 3,
        ]]);

        $this->assertStringContainsString('R$ 1.234,56', $html, 'faturamento deve formatar centavos como moeda BRL');
        $this->assertStringContainsString('R$ 176,36', $html, 'ticket medio deve formatar centavos como moeda BRL');
    }

    public function testEscapesProductNameToPreventXss(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'nome de produto deve ser escapado, nunca renderizado cru');
        $this->assertStringContainsString('&lt;script&gt;', $html, 'htmlspecialchars deve converter os caracteres perigosos');
    }

    public function testUnknownStatusFallsBackToRawKeyInsteadOfCrashing(): void
    {
        $html = $this->render(['recent' => [
            ['idx' => 999, 'customer_name' => 'Cliente X', 'total_cents' => 1000, 'status' => 'status_nao_mapeado', 'created_at' => date('Y-m-d H:i:s')],
        ]]);

        $this->assertStringContainsString('status_nao_mapeado', $html, 'status sem label mapeado deve cair no fallback ?? $statusKey/$status, nunca quebrar');
    }

    public function testEmptyTopProductsAndRecentOrdersShowEmptyStateWithoutCrash(): void
    {
        $html = $this->render(['topProd' => [], 'recent' => []]);

        $this->assertStringContainsString('Nenhuma venda no período.', $html);
        $this->assertStringContainsString('Nenhum pedido registrado.', $html);
    }

    public function testGatewaysCardShowsActiveInactiveAndPaidRevenue(): void
    {
        $html = $this->render(['gateways' => [
            ['name' => 'Mercado Pago', 'enabled' => 'yes', 'mtd_cents' => 80000],
            ['name' => 'PagBank',      'enabled' => 'no',  'mtd_cents' => 20000],
        ]]);

        $this->assertStringContainsString('Gateways de pagamento', $html);
        $this->assertStringContainsString('Mercado Pago', $html);
        $this->assertStringContainsString('>Ativo<', $html, 'gateway habilitado deve exibir pill Ativo');
        $this->assertStringContainsString('>Inativo<', $html, 'gateway desabilitado deve exibir pill Inativo');
        $this->assertStringContainsString('R$ 800,00', $html, 'faturamento pago do gateway deve formatar centavos como BRL');
        $this->assertStringContainsString('R$ 1.000,00', $html, 'total do rodape deve somar o faturamento de todos os gateways');
    }

    public function testGatewaysEmptyShowsEmptyStateWithoutCrash(): void
    {
        $html = $this->render(['gateways' => []]);

        $this->assertStringContainsString('Nenhum gateway cadastrado.', $html);
    }

    public function testGatewaysZeroTotalDoesNotTriggerDivisionByZero(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED | E_ERROR);

        try {
            $html = $this->render(['gateways' => [
                ['name' => 'Mercado Pago', 'enabled' => 'yes', 'mtd_cents' => 0],
            ]]);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('width:0%', $html, 'total zerado deve gerar barra 0%, nunca divisao por zero');
    }

    public function testAllZeroStatusCountsDoNotTriggerDivisionByZero(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED | E_ERROR);

        try {
            $html = $this->render(['byStatus' => [
                'aguardando_pagamento' => 0, 'pago' => 0, 'cancelado' => 0, 'expirado' => 0,
            ]]);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('width:0%', $html, 'max(1, ...) deve evitar divisao por zero quando todos os status estao zerados');
    }
}
