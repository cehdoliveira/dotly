<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre products_controller::resolveSort() — a ordenacao por coluna clicavel do
 * cabecalho de /produtos (nome, categoria, preco unitario, estoque). A expressao
 * de ORDER BY e injetada crua no SQL pelo DOLModel (set_order -> " order by "),
 * entao o ponto central e provar que SO chaves da whitelist viram ORDER BY:
 * qualquer chave forjada cai no default (ordem curada por sort_order), nunca SQL.
 *
 * Nao toca banco (TestCase puro): resolveSort() so mapeia $info['get'] -> tripla
 * [chave, direcao, expressao], sem consultar `products`.
 */
final class ProductsSortTest extends TestCase
{
    /**
     * @return array{0:string,1:string,2:string} [chave, direcao, expressao SQL]
     */
    private function resolveSort(array $info): array
    {
        $controller = new products_controller();
        $method     = new ReflectionMethod($controller, 'resolveSort');
        $method->setAccessible(true);

        return $method->invoke($controller, $info);
    }

    public function testDefaultsToCuratedOrderWhenNoSortParam(): void
    {
        [$key, $dir, $expr] = $this->resolveSort(['get' => []]);

        $this->assertSame('', $key, 'sem parametro, nenhuma coluna fica ativa');
        $this->assertSame('asc', $dir);
        $this->assertStringContainsString('sort_order', $expr, 'o default mantem a ordem curada do catalogo');
    }

    public function testValidColumnAndDirectionBuildMatchingOrderExpression(): void
    {
        [$key, $dir, $expr] = $this->resolveSort(['get' => ['sort' => 'preco', 'dir' => 'desc']]);

        $this->assertSame('preco', $key);
        $this->assertSame('desc', $dir);
        $this->assertStringContainsString('price_unit_cents', $expr);
        $this->assertStringContainsString('DESC', $expr);
    }

    public function testStockAndNameAndCategoryColumnsMapToTheirSqlColumns(): void
    {
        $this->assertStringContainsString('stock', $this->resolveSort(['get' => ['sort' => 'estoque']])[2]);
        $this->assertStringContainsString('name', $this->resolveSort(['get' => ['sort' => 'nome']])[2]);
        $this->assertStringContainsString('category', $this->resolveSort(['get' => ['sort' => 'categoria']])[2]);
    }

    public function testDirectionDefaultsToAscendingForAlphabeticalFirstClick(): void
    {
        [, $dir, $expr] = $this->resolveSort(['get' => ['sort' => 'nome', 'dir' => 'qualquer_coisa']]);

        $this->assertSame('asc', $dir, 'so "desc" vira DESC; qualquer outra coisa e ASC');
        $this->assertStringContainsString('ASC', $expr);
    }

    public function testInvalidColumnFallsBackToCuratedDefault(): void
    {
        [$key, , $expr] = $this->resolveSort(['get' => ['sort' => 'coluna_inexistente', 'dir' => 'asc']]);

        $this->assertSame('', $key, 'coluna fora da whitelist deve cair no default');
        $this->assertStringContainsString('sort_order', $expr);
    }

    public function testInjectionAttemptInSortParamNeverReachesOrderExpression(): void
    {
        $payload = 'stock; DROP TABLE products; --';
        [$key, , $expr] = $this->resolveSort(['get' => ['sort' => $payload]]);

        $this->assertSame('', $key, 'payload de injecao nao esta na whitelist, entao cai no default');
        $this->assertStringNotContainsString('DROP', $expr, 'o valor cru nunca pode virar parte do ORDER BY');
        $this->assertStringNotContainsString($payload, $expr);
    }

    public function testArraySortParamDoesNotCrashAndFallsBack(): void
    {
        // ?sort[]=x faz o PHP popular como array — o guard is_string deve tratar
        // como ausente e cair no default, nunca causar TypeError.
        [$key] = $this->resolveSort(['get' => ['sort' => ['preco']]]);

        $this->assertSame('', $key);
    }
}
