<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a renderizacao de ui/page/orders.php (plano 019): OrdersFilterTest so cobre
 * a logica de buildFilter(), nunca o render em si — mesma lacuna que
 * CustomersViewTest/SalesDashboardViewTest existem para cobrir nas views delas.
 * Sem isso, um htmlspecialchars() esquecido nos inputs cpf/telefone so apareceria
 * em producao.
 *
 * Nao precisa de banco (TestCase puro, nao DBTestCase) — a view so consome os
 * arrays/escalares ja montados pelo controller ($orders, $currentStatuses,
 * $currentCpf, $currentPhone, $currentDateStart, $currentDateEnd, $page,
 * $totalPages), entao os dados sao passados como fixture PHP direta, sem tocar
 * `orders`.
 */
final class OrdersViewTest extends TestCase
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
            'home_url' => '/', 'customers_url' => '/clientes', 'profiles_url' => '/perfis',
            'products_url' => '/produtos', 'orders_url' => '/pedidos',
            'gateways_url' => '/gateways', 'logout_url' => '/sair',
            'order_url' => '/pedidos/%d', 'orders_export_url' => '/pedidos/exportar',
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
     * Renderiza a view com as mesmas variaveis que orders_controller::index()
     * monta, sobrescrevendo so as passadas em $overrides.
     *
     * @param array<string,mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $orders           = $overrides['orders'] ?? [];
        $totalPages       = $overrides['totalPages'] ?? 0;
        $page             = $overrides['page'] ?? 1;
        $currentStatuses  = $overrides['currentStatuses'] ?? [];
        $currentCpf       = $overrides['currentCpf'] ?? '';
        $currentPhone     = $overrides['currentPhone'] ?? '';
        $currentDateStart = $overrides['currentDateStart'] ?? '';
        $currentDateEnd   = $overrides['currentDateEnd'] ?? '';
        $currentGateway   = $overrides['currentGateway'] ?? 0;
        $gateways         = $overrides['gateways'] ?? [];
        $currentSort      = $overrides['currentSort'] ?? 'criado';
        $currentDir       = $overrides['currentDir'] ?? 'desc';

        ob_start();
        try {
            (function () use ($orders, $totalPages, $page, $currentStatuses, $currentCpf, $currentPhone, $currentDateStart, $currentDateEnd, $currentGateway, $gateways, $currentSort, $currentDir) {
                include dirname(__DIR__) . '/public_html/ui/page/orders.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    private function renderStrict(array $overrides = []): string
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        try {
            return $this->render($overrides);
        } finally {
            restore_error_handler();
        }
    }

    public function testCpfAndTelefoneInputsAreEscapedToPreventXss(): void
    {
        $html = $this->renderStrict([
            'currentCpf'   => '<script>alert(1)</script>',
            'currentPhone' => '"><script>alert(2)</script>',
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'valor do campo cpf deve ser escapado, nunca renderizado cru');
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html, 'valor do campo telefone deve ser escapado, nunca renderizado cru');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;', $html);
    }

    public function testCpfAndTelefoneInputsArePrefilledWithCurrentValues(): void
    {
        $html = $this->renderStrict(['currentCpf' => '12345678909', 'currentPhone' => '9999']);

        $this->assertStringContainsString('value="12345678909"', $html, 'campo cpf deve manter o valor filtrado apos o submit');
        $this->assertStringContainsString('value="9999"', $html, 'campo telefone deve manter o valor filtrado apos o submit');
    }

    public function testExportLinkPropagatesAllFilters(): void
    {
        $html = $this->renderStrict([
            'currentStatuses'  => ['pago', 'cancelado'],
            'currentCpf'       => '12345678909',
            'currentPhone'     => '9999',
            'currentDateStart' => '2026-07-01',
            'currentDateEnd'   => '2026-07-31',
            'currentGateway'   => 3,
        ]);

        // Multi-status agora serializa como status[]=a&status[]=b.
        $this->assertStringContainsString('status[]=pago', $html, 'link de exportar CSV deve propagar cada status');
        $this->assertStringContainsString('status[]=cancelado', $html, 'link de exportar CSV deve propagar cada status');
        $this->assertStringContainsString('cpf=12345678909', $html, 'link de exportar CSV deve propagar cpf');
        $this->assertStringContainsString('telefone=9999', $html, 'link de exportar CSV deve propagar telefone');
        $this->assertStringContainsString('data_inicio=2026-07-01', $html, 'link de exportar CSV deve propagar inicio do intervalo');
        $this->assertStringContainsString('data_fim=2026-07-31', $html, 'link de exportar CSV deve propagar fim do intervalo');
        $this->assertStringContainsString('gateway=3', $html, 'link de exportar CSV deve propagar o gateway');
    }

    public function testGatewaySelectListsOptionsAndMarksCurrentSelection(): void
    {
        $html = $this->renderStrict([
            'gateways'       => [['idx' => 3, 'name' => 'Mercado Pago'], ['idx' => 5, 'name' => 'PagBank']],
            'currentGateway' => 5,
        ]);

        $this->assertStringContainsString('name="gateway"', $html, 'deve existir o select de gateway');
        $this->assertStringContainsString('Todos os gateways', $html, 'deve haver a opcao vazia padrao');
        $this->assertStringContainsString('Mercado Pago', $html);
        $this->assertMatchesRegularExpression('/<option value="5"\s+selected>\s*PagBank/', $html, 'o gateway atual deve vir selecionado');
    }

    public function testGatewayOptionNameIsEscapedToPreventXss(): void
    {
        $html = $this->renderStrict([
            'gateways' => [['idx' => 1, 'name' => '<script>alert(4)</script>']],
        ]);

        $this->assertStringNotContainsString('<script>alert(4)</script>', $html, 'nome do gateway na option deve ser escapado');
        $this->assertStringContainsString('&lt;script&gt;alert(4)&lt;/script&gt;', $html);
    }

    public function testPaginationLinksPropagateAllFilters(): void
    {
        $html = $this->renderStrict([
            'orders'           => [['idx' => 1, 'token' => 'abc123def456', 'customer_name' => 'Fulano', 'status' => 'pago', 'gateway_name' => 'Mercado Pago', 'total_cents' => 1000, 'created_at' => '2026-07-01 10:00:00', 'paid_at' => null]],
            'totalPages'       => 3,
            'page'             => 2,
            'currentStatuses'  => ['pago'],
            'currentCpf'       => '12345678909',
            'currentPhone'     => '9999',
            'currentDateStart' => '2026-07-01',
        ]);

        $this->assertStringContainsString('status[]=pago', $html, 'links de paginacao devem propagar status');
        $this->assertStringContainsString('cpf=12345678909', $html, 'links de paginacao devem propagar cpf');
        $this->assertStringContainsString('telefone=9999', $html, 'links de paginacao devem propagar telefone');
        $this->assertStringContainsString('data_inicio=2026-07-01', $html, 'links de paginacao devem propagar o intervalo de data');
    }

    public function testStatusCheckboxesReflectCurrentSelection(): void
    {
        $html = $this->renderStrict(['currentStatuses' => ['pago', 'expirado']]);

        // Os status marcados vem com `checked`; os nao marcados, sem.
        $this->assertMatchesRegularExpression('/name="status\[\]"\s+value="pago"\s+checked/', $html, 'status selecionado deve vir marcado');
        $this->assertMatchesRegularExpression('/name="status\[\]"\s+value="expirado"\s+checked/', $html, 'status selecionado deve vir marcado');
        $this->assertMatchesRegularExpression('/name="status\[\]"\s+value="cancelado"\s*>/', $html, 'status nao selecionado nao deve vir marcado');
        $this->assertStringContainsString('2 status selecionados', $html, 'o botao deve resumir a contagem de status escolhidos');
    }

    public function testGatewayColumnRendersGatewayNameAndDashWhenAbsent(): void
    {
        $html = $this->renderStrict([
            'orders' => [
                ['idx' => 1, 'token' => 'abc123def456', 'customer_name' => 'Com Gateway', 'status' => 'pago', 'gateway_name' => 'Mercado Pago', 'total_cents' => 1000, 'created_at' => '2026-07-01 10:00:00', 'paid_at' => '2026-07-01 11:00:00'],
                ['idx' => 2, 'token' => 'def456abc123', 'customer_name' => 'Sem Gateway', 'status' => 'aguardando_pagamento', 'gateway_name' => null, 'total_cents' => 2000, 'created_at' => '2026-07-02 10:00:00', 'paid_at' => null],
            ],
        ]);

        $this->assertMatchesRegularExpression('/sort=gateway[^"]*"[^>]*>\s*<span>Gateway<\/span>/', $html, 'a tabela deve ter a coluna Gateway como cabecalho ordenavel');
        $this->assertMatchesRegularExpression('/gateway-tag">\s*Mercado Pago\s*</', $html, 'pedido com gateway deve exibir o nome do gateway');
    }

    public function testGatewayNameIsEscapedToPreventXss(): void
    {
        $html = $this->renderStrict([
            'orders' => [
                ['idx' => 1, 'token' => 'abc123def456', 'customer_name' => 'Fulano', 'status' => 'pago', 'gateway_name' => '<script>alert(3)</script>', 'total_cents' => 1000, 'created_at' => '2026-07-01 10:00:00', 'paid_at' => null],
            ],
        ]);

        $this->assertStringNotContainsString('<script>alert(3)</script>', $html, 'nome do gateway deve ser escapado, nunca renderizado cru');
        $this->assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
    }

    /**
     * Uma linha minima de pedido — o cabecalho ordenavel so e renderizado quando
     * ha pedidos (senao a view mostra o empty state, sem tabela).
     *
     * @return array<int,array<string,mixed>>
     */
    private function oneOrder(): array
    {
        return [
            ['idx' => 1, 'token' => 'abc123def456', 'customer_name' => 'Fulano', 'status' => 'pago', 'gateway_name' => 'Mercado Pago', 'total_cents' => 1000, 'created_at' => '2026-07-01 10:00:00', 'paid_at' => null],
        ];
    }

    public function testEveryColumnHeaderIsASortableLink(): void
    {
        $html = $this->renderStrict(['orders' => $this->oneOrder()]);

        foreach (['id', 'token', 'cliente', 'status', 'gateway', 'total', 'criado', 'pago'] as $col) {
            $this->assertStringContainsString('sort=' . $col, $html, "a coluna {$col} deve ter link de ordenacao");
        }
    }

    public function testActiveSortColumnCarriesAriaSortAndActiveClass(): void
    {
        $html = $this->renderStrict(['orders' => $this->oneOrder(), 'currentSort' => 'total', 'currentDir' => 'asc']);

        // O <th> ativo anuncia a direcao para leitores de tela; o link acende (is-active).
        $this->assertMatchesRegularExpression('/<th[^>]*aria-sort="ascending"[^>]*class="[^"]*is-active/', $html, 'a coluna ordenada deve declarar aria-sort e a classe ativa');
        $this->assertStringContainsString('bi-caret-up-fill', $html, 'ordenacao ascendente deve mostrar o caret para cima');
    }

    public function testActiveAscendingHeaderLinkTogglesToDescending(): void
    {
        $html = $this->renderStrict(['orders' => $this->oneOrder(), 'currentSort' => 'total', 'currentDir' => 'asc']);

        // Clicar na coluna ja ordenada asc deve inverter para desc (& escapado no HTML).
        $this->assertMatchesRegularExpression('/sort=total&amp;dir=desc/', $html, 'clicar na coluna ativa em asc deve alternar para desc');
    }

    public function testInactiveColumnDefaultsToAscendingOnFirstClick(): void
    {
        $html = $this->renderStrict(['orders' => $this->oneOrder(), 'currentSort' => 'criado', 'currentDir' => 'desc']);

        // Uma coluna nao ordenada aponta para asc no primeiro clique.
        $this->assertMatchesRegularExpression('/sort=cliente&amp;dir=asc/', $html, 'coluna inativa deve ordenar asc no primeiro clique');
    }

    public function testSortLinksPropagateActiveFilters(): void
    {
        $html = $this->renderStrict([
            'orders'          => $this->oneOrder(),
            'currentStatuses' => ['pago'],
            'currentCpf'      => '12345678909',
        ]);

        $this->assertMatchesRegularExpression('/status\[\]=pago[^"]*sort=/', $html, 'o link de ordenacao deve preservar o filtro de status');
        $this->assertMatchesRegularExpression('/cpf=12345678909[^"]*sort=/', $html, 'o link de ordenacao deve preservar o filtro de cpf');
    }

    public function testDetailsActionShowsVisibleLabelNextToIcon(): void
    {
        $html = $this->renderStrict([
            'orders' => [
                ['idx' => 1, 'token' => 'abc123def456', 'customer_name' => 'Fulano', 'status' => 'pago', 'gateway_name' => null, 'total_cents' => 1000, 'created_at' => '2026-07-01 10:00:00', 'paid_at' => null],
            ],
        ]);

        // O icone de olho ganha o rotulo "Detalhes" — acessivel e autoexplicativo.
        $this->assertMatchesRegularExpression('/bi-eye[^<]*<\/i>\s*<span[^>]*>Detalhes<\/span>/', $html, 'a acao de ver deve exibir o rotulo "Detalhes" ao lado do icone');
    }

    /** Recorta so o corpo da tabela, fora do dropdown de filtro (que tambem usa os badges). */
    private function tbody(string $html): string
    {
        $start = strpos($html, '<tbody>');
        $end   = strpos($html, '</tbody>');
        if ($start === false || $end === false) {
            return '';
        }

        return substr($html, $start, $end - $start);
    }

    public function testShippedOrderShowsOnlyShippedBadgeReplacingPaymentStatus(): void
    {
        $shipped    = ['idx' => 1, 'token' => 'abc123def456', 'customer_name' => 'Enviado', 'status' => 'pago', 'gateway_name' => 'Mercado Pago', 'total_cents' => 1000, 'created_at' => '2026-07-01 10:00:00', 'paid_at' => '2026-07-01 11:00:00', 'shipped_at' => '2026-07-02 09:00:00'];
        $notShipped = ['idx' => 2, 'token' => 'def456abc123', 'customer_name' => 'Nao enviado', 'status' => 'pago', 'gateway_name' => 'Mercado Pago', 'total_cents' => 2000, 'created_at' => '2026-07-02 10:00:00', 'paid_at' => '2026-07-02 11:00:00', 'shipped_at' => null];

        $tbody = $this->tbody($this->renderStrict(['orders' => [$shipped, $notShipped]]));

        // A linha enviada mostra so "Enviado" (sem o badge de pagamento, para nao
        // confundir); a nao enviada mantem o badge de pagamento dela.
        $this->assertSame(1, substr_count($tbody, 'badge-shipped'), 'so a linha enviada mostra o badge Enviado');
        $this->assertSame(1, substr_count($tbody, 'badge-active'), 'o badge de pagamento (Pago) so aparece na linha nao enviada — a enviada o substitui por Enviado');
        $this->assertMatchesRegularExpression('/badge-shipped">\s*Enviado\s*</', $tbody);
    }

    public function testShippedOptionAppearsInStatusFilterDropdown(): void
    {
        $html = $this->renderStrict();

        $this->assertMatchesRegularExpression('/name="status\[\]"\s+value="enviado"/', $html, 'o multi-select de status deve oferecer a opcao "Enviado"');
    }

    public function testShippedFilterSelectionIsCheckedAndPropagates(): void
    {
        $html = $this->renderStrict([
            'currentStatuses' => ['pago', 'enviado'],
        ]);

        $this->assertMatchesRegularExpression('/name="status\[\]"\s+value="enviado"\s+checked/', $html, '"Enviado" selecionado deve vir marcado');
        // Propaga para exportar/paginar como mais um status[].
        $this->assertStringContainsString('status[]=enviado', $html, 'a selecao "Enviado" deve propagar nos links de exportar/paginar');
    }

    public function testEmptyOrdersShowsEmptyStateWithoutCrashing(): void
    {
        $html = $this->renderStrict(['orders' => []]);

        $this->assertStringContainsString('Nenhum pedido encontrado.', $html);
    }
}
