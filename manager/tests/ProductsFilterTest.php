<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre products_controller::buildFilter() — os dois eixos de filtro de /produtos:
 * busca por nome (LIKE) e categoria exata (dropdown), unidos por AND. buildFilter()
 * so monta [conditions, params] a partir de $info['get'] (sem tocar `products`),
 * entao roda como TestCase puro.
 *
 * O foco e provar que os valores sempre vao bindados (nunca concatenados no SQL) e
 * que os curingas LIKE digitados no nome sao escapados — um `%` cru alargaria a
 * busca silenciosamente.
 */
final class ProductsFilterTest extends TestCase
{
    /**
     * @return array{0: string[], 1: array<int,mixed>} [conditions, params]
     */
    private function buildFilter(array $info): array
    {
        $controller = new products_controller();
        $method     = new ReflectionMethod($controller, 'buildFilter');
        $method->setAccessible(true);

        return $method->invoke($controller, $info);
    }

    public function testWithoutSearchOnlyTheActiveScopeIsApplied(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => []]);

        $this->assertCount(1, $conds);
        $this->assertStringContainsString("active = 'yes'", $conds[0]);
        $this->assertSame([], $params, 'sem busca, nenhum valor e bindado');
    }

    public function testNameSearchMatchesOnlyNameWithBoundParam(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['q' => 'camisa']]);

        $joined = implode(' ', $conds);
        $this->assertStringContainsString('name LIKE ?', $joined);
        $this->assertStringNotContainsString('category', $joined, 'a busca por nome nao deve mais casar categoria');
        $this->assertSame(['%camisa%'], $params);
    }

    public function testCategoryFilterIsExactMatchWithBoundParam(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['categoria' => 'Vestuário']]);

        $joined = implode(' ', $conds);
        $this->assertStringContainsString('category = ?', $joined, 'categoria e correspondencia exata, nao LIKE');
        $this->assertSame(['Vestuário'], $params);
    }

    public function testNameAndCategoryCombineWithAnd(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['q' => 'polo', 'categoria' => 'Vestuário']]);

        $joined = implode(' ', $conds);
        $this->assertStringContainsString('name LIKE ?', $joined);
        $this->assertStringContainsString('category = ?', $joined);
        // Ordem: nome antes de categoria (mesma ordem do bind).
        $this->assertSame(['%polo%', 'Vestuário'], $params);
    }

    public function testBlankCategoryIsIgnored(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['categoria' => '  ']]);

        $this->assertCount(1, $conds, 'categoria vazia nao adiciona condicao');
        $this->assertSame([], $params);
    }

    private function lowStockThreshold(): int
    {
        return (new ReflectionClass('products_controller'))->getConstant('LOW_STOCK_THRESHOLD');
    }

    public function testOutOfStockFilterMatchesOnlyZeroStock(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['estoque' => 'esgotado']]);

        $this->assertStringContainsString('stock <= 0', implode(' ', $conds));
        $this->assertSame([], $params, 'esgotado nao depende do limiar, entao nao binda nada');
    }

    public function testLowStockFilterUsesThresholdAndExcludesZero(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['estoque' => 'baixo']]);

        $joined = implode(' ', $conds);
        $this->assertStringContainsString('stock > 0', $joined, 'baixo exclui os esgotados');
        $this->assertStringContainsString('stock <= ?', $joined);
        $this->assertSame([$this->lowStockThreshold()], $params, 'o limiar vai bindado, nunca concatenado');
    }

    public function testCriticalStockFilterCoversLowAndOutTogether(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['estoque' => 'critico']]);

        $joined = implode(' ', $conds);
        $this->assertStringContainsString('stock <= ?', $joined);
        $this->assertStringNotContainsString('stock > 0', $joined, 'critico inclui os esgotados (stock 0)');
        $this->assertSame([$this->lowStockThreshold()], $params);
    }

    public function testUnknownStockStateIsIgnored(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['estoque' => 'qualquer; DROP TABLE products']]);

        $this->assertCount(1, $conds, 'valor fora da whitelist nao adiciona condicao');
        $this->assertSame([], $params);
    }

    public function testBlankSearchIsIgnored(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['q' => '   ']]);

        $this->assertCount(1, $conds, 'busca so com espacos nao adiciona condicao');
        $this->assertSame([], $params);
    }

    public function testLikeWildcardsInTermAreEscaped(): void
    {
        [, $params] = $this->buildFilter(['get' => ['q' => '50%_off']]);

        // % e _ do usuario viram literais (\% \_), senao a busca alargaria sozinha.
        $this->assertSame(['%50\%\_off%'], $params);
    }

    public function testArraySearchParamDoesNotCrashAndIsIgnored(): void
    {
        // ?q[]=x popula como array — o guard is_string trata como ausente.
        [$conds, $params] = $this->buildFilter(['get' => ['q' => ['camisa']]]);

        $this->assertCount(1, $conds);
        $this->assertSame([], $params);
    }
}
