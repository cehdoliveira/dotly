<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a renderizacao de ui/page/products.php: a coluna "Preço caixa" sai da
 * listagem, os cabecalhos viram ordenacao clicavel, a busca reflete o valor
 * filtrado (escapado) e a linha inteira sinaliza estoque baixo/esgotado.
 *
 * Nao precisa de banco (TestCase puro, nao DBTestCase) — a view so consome os
 * arrays/escalares ja montados pelo controller ($products, $currentQ,
 * $currentSort, $currentDir, $lowStockThreshold, $page, $totalPages).
 */
final class ProductsViewTest extends TestCase
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
            '_csrf_token'       => 'tok',
        ];

        $urls = [
            'home_url' => '/', 'customers_url' => '/clientes', 'profiles_url' => '/perfis',
            'products_url' => '/produtos', 'orders_url' => '/pedidos',
            'gateways_url' => '/gateways', 'logout_url' => '/sair',
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
     * Uma linha de produto com defaults saudaveis; $overrides sobrescreve campos.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function product(array $overrides = []): array
    {
        return $overrides + [
            'idx'              => 1,
            'name'             => 'Camisa Polo',
            'slug'             => 'camisa-polo',
            'category'         => 'Vestuário',
            'price_unit_cents' => 7000,
            'stock'            => 50,
            'cover_path'       => null,
        ];
    }

    /**
     * Renderiza a view com as mesmas variaveis que products_controller::index()
     * monta, sobrescrevendo so as passadas em $overrides.
     *
     * @param array<string,mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $products          = $overrides['products'] ?? [$this->product()];
        $totalPages        = $overrides['totalPages'] ?? 0;
        $page              = $overrides['page'] ?? 1;
        $currentQ          = $overrides['currentQ'] ?? '';
        $currentCategory   = $overrides['currentCategory'] ?? '';
        $categories        = $overrides['categories'] ?? ['Vestuário', 'Calçados'];
        $currentStock      = $overrides['currentStock'] ?? '';
        $currentSort       = $overrides['currentSort'] ?? '';
        $currentDir        = $overrides['currentDir'] ?? 'asc';
        $lowStockThreshold = $overrides['lowStockThreshold'] ?? 10;

        ob_start();
        try {
            (function () use ($products, $totalPages, $page, $currentQ, $currentCategory, $categories, $currentStock, $currentSort, $currentDir, $lowStockThreshold) {
                include dirname(__DIR__) . '/public_html/ui/page/products.php';
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

    public function testPrecoCaixaColumnIsRemovedFromListing(): void
    {
        $html = $this->renderStrict();

        $this->assertStringNotContainsString('Preço caixa</th>', $html, 'a coluna Preço caixa nao deve aparecer na listagem');
        $this->assertStringContainsString('Preço unid.', $html, 'o preco unitario continua sendo a coluna que prevalece');
    }

    public function testSortableHeadersRenderForEachColumn(): void
    {
        $html = $this->renderStrict();

        foreach (['sort=nome', 'sort=categoria', 'sort=preco', 'sort=estoque'] as $needle) {
            $this->assertStringContainsString($needle, $html, "cabecalho deve gerar link de ordenacao: {$needle}");
        }
    }

    public function testActiveSortHeaderTogglesDirectionAndMarksAriaSort(): void
    {
        $html = $this->renderStrict(['currentSort' => 'nome', 'currentDir' => 'asc']);

        $this->assertStringContainsString('aria-sort="ascending"', $html, 'a coluna ativa expoe a direcao atual');
        $this->assertStringContainsString('sort=nome&amp;dir=desc', $html, 'clicar de novo na coluna ativa inverte a direcao');
    }

    public function testOutOfStockRowIsFlaggedAsEsgotado(): void
    {
        $html = $this->renderStrict(['products' => [$this->product(['stock' => 0])]]);

        $this->assertStringContainsString('product-row--out', $html, 'linha esgotada recebe a classe de estado');
        $this->assertStringContainsString('Esgotado', $html);
    }

    public function testLowStockRowIsFlaggedAsBaixo(): void
    {
        $html = $this->renderStrict(['products' => [$this->product(['stock' => 3])], 'lowStockThreshold' => 10]);

        $this->assertStringContainsString('product-row--low', $html, 'linha com estoque no/abaixo do limite recebe a classe de estado');
        $this->assertStringContainsString('Baixo', $html);
    }

    public function testHealthyStockRowHasNoStateClass(): void
    {
        $html = $this->renderStrict(['products' => [$this->product(['stock' => 50])], 'lowStockThreshold' => 10]);

        $this->assertStringNotContainsString('product-row--low', $html);
        $this->assertStringNotContainsString('product-row--out', $html);
    }

    public function testNameSearchInputIsEscapedAndPrefilled(): void
    {
        $html = $this->renderStrict(['currentQ' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'valor da busca deve ser escapado, nunca renderizado cru');
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testCategoryDropdownRendersOptionsFromList(): void
    {
        $html = $this->renderStrict(['categories' => ['Vestuário', 'Calçados']]);

        $this->assertStringContainsString('name="categoria"', $html, 'deve haver um campo select de categoria');
        $this->assertStringContainsString('Todas as categorias', $html, 'opcao neutra para nao filtrar');
        $this->assertStringContainsString('value="Vestuário"', $html);
        $this->assertStringContainsString('value="Calçados"', $html);
    }

    public function testSelectedCategoryIsMarkedSelected(): void
    {
        $html = $this->renderStrict(['currentCategory' => 'Vestuário', 'categories' => ['Vestuário', 'Calçados']]);

        $this->assertMatchesRegularExpression('/value="Vestuário"\s+selected/', $html, 'a categoria filtrada deve ficar selecionada apos o submit');
    }

    public function testCategoryOptionsAreEscaped(): void
    {
        $html = $this->renderStrict(['categories' => ['"><script>alert(3)</script>']]);

        $this->assertStringNotContainsString('<script>alert(3)</script>', $html, 'valores de categoria vindos do banco devem ser escapados');
    }

    public function testStockFilterRendersAllStates(): void
    {
        $html = $this->renderStrict();

        $this->assertStringContainsString('name="estoque"', $html, 'deve haver um campo select de estoque');
        $this->assertStringContainsString('Todos os estoques', $html);
        $this->assertStringContainsString('value="baixo"', $html);
        $this->assertStringContainsString('value="esgotado"', $html);
        $this->assertStringContainsString('value="critico"', $html);
    }

    public function testSelectedStockStateIsMarkedSelected(): void
    {
        $html = $this->renderStrict(['currentStock' => 'esgotado']);

        $this->assertMatchesRegularExpression('/value="esgotado"\s+selected/', $html, 'o estado de estoque filtrado deve ficar selecionado apos o submit');
    }

    public function testPaginationPreservesStockFilter(): void
    {
        $html = $this->renderStrict(['totalPages' => 3, 'page' => 1, 'currentStock' => 'baixo']);

        $this->assertStringContainsString('estoque=baixo', $html, 'paginacao deve preservar o filtro de estoque');
    }

    public function testPaginationPreservesSearchAndSort(): void
    {
        $html = $this->renderStrict([
            'totalPages'      => 3,
            'page'            => 1,
            'currentQ'        => 'camisa',
            'currentCategory' => 'Vestuário',
            'currentSort'     => 'preco',
            'currentDir'      => 'desc',
        ]);

        $this->assertStringContainsString('q=camisa', $html, 'paginacao deve preservar a busca por nome');
        $this->assertStringContainsString('categoria=Vestu', $html, 'paginacao deve preservar a categoria filtrada');
        $this->assertStringContainsString('sort=preco', $html, 'paginacao deve preservar a ordenacao ativa');
    }
}
