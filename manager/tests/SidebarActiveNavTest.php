<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre ui/common/sidebar.php: a deteccao do item de menu ativo, derivada do
 * primeiro segmento da URL atual (REQUEST_URI relativo a cFrontend), sem
 * hardcode por pagina. Todo teste de view que renderiza uma pagina completa
 * (CustomersViewTest, ConfigViewTest, OrderDetailViewTest etc.) inclui a
 * sidebar de passagem, mas nenhum deles afirma qual item fica marcado
 * "active" para qual rota — e essa logica que esta suite fecha.
 */
final class SidebarActiveNavTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $globalsBackup = [];
    private ?string $requestUriBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestUriBackup = $_SERVER['REQUEST_URI'] ?? null;

        $urls = ['home_url' => '/', 'orders_url' => '/pedidos', 'products_url' => '/produtos', 'customers_url' => '/clientes'];
        foreach ($urls as $key => $value) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
            $GLOBALS[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        if ($this->requestUriBackup === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->requestUriBackup;
        }
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function basePath(): string
    {
        return rtrim((string) (parse_url((string) constant('cFrontend'), PHP_URL_PATH) ?? '/'), '/');
    }

    private function render(string $requestUri): string
    {
        $_SERVER['REQUEST_URI'] = $requestUri;

        ob_start();
        try {
            (function () {
                include dirname(__DIR__) . '/public_html/ui/common/sidebar.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean();
    }

    /** Isola o bloco <li> do item de menu cujo href bate exatamente. */
    private function itemBlock(string $html, string $href): string
    {
        foreach (explode('<li class="nav-item">', $html) as $item) {
            if (str_contains($item, 'href="' . $href . '"')) {
                return $item;
            }
        }
        $this->fail("item de menu com href=\"{$href}\" nao encontrado no HTML renderizado");
    }

    public function testCustomersSegmentMarksOnlyClientesNavActive(): void
    {
        $html = $this->render($this->basePath() . '/clientes/42');

        $this->assertStringContainsString('active', $this->itemBlock($html, '/clientes'), 'segmento clientes/{idx} deve marcar o item Clientes como ativo');
        $this->assertStringNotContainsString('active', $this->itemBlock($html, '/pedidos'));
        $this->assertStringNotContainsString('active', $this->itemBlock($html, '/produtos'));
        $this->assertStringNotContainsString('active', $this->itemBlock($html, '/'));
    }

    public function testRootSegmentMarksOnlyHomeNavActive(): void
    {
        $html = $this->render($this->basePath() . '/');

        $this->assertStringContainsString('active', $this->itemBlock($html, '/'), 'raiz deve marcar o item Início como ativo');
        $this->assertStringNotContainsString('active', $this->itemBlock($html, '/clientes'));
        $this->assertStringNotContainsString('active', $this->itemBlock($html, '/pedidos'));
        $this->assertStringNotContainsString('active', $this->itemBlock($html, '/produtos'));
    }

    public function testOrdersSubrouteStillMarksPedidosNavActive(): void
    {
        // /pedidos/42/etiqueta: o primeiro segmento ainda e "pedidos", mesmo em
        // rotas aninhadas duas camadas abaixo.
        $html = $this->render($this->basePath() . '/pedidos/42/etiqueta');

        $this->assertStringContainsString('active', $this->itemBlock($html, '/pedidos'));
        $this->assertStringNotContainsString('active', $this->itemBlock($html, '/clientes'));
    }
}
