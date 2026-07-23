<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre orders_controller::resolveSort() — a ordenacao por coluna clicavel do
 * cabecalho de /pedidos. A expressao de ORDER BY e injetada crua no SQL pelo
 * DOLModel (set_order -> " order by " . implode), entao o ponto central destes
 * testes e provar que SO chaves da whitelist viram ORDER BY: qualquer chave
 * forjada cai no default (criado DESC), nunca vira SQL.
 *
 * Nao toca banco (TestCase puro): resolveSort() so mapeia $info['get'] -> tripla
 * [chave, direcao, expressao], sem consultar `orders`.
 */
final class OrdersSortTest extends TestCase
{
    /**
     * @return array{0:string,1:string,2:string} [chave, direcao, expressao SQL]
     */
    private function resolveSort(array $info): array
    {
        $controller = new orders_controller();
        $method     = new ReflectionMethod($controller, 'resolveSort');
        $method->setAccessible(true);

        return $method->invoke($controller, $info);
    }

    public function testDefaultsToCreatedDescendingWhenNoSortParam(): void
    {
        [$key, $dir, $expr] = $this->resolveSort(['get' => []]);

        $this->assertSame('criado', $key, 'sem parametro, a ordenacao padrao e por data de criacao');
        $this->assertSame('desc', $dir, 'o padrao e decrescente (mais recentes primeiro)');
        $this->assertStringContainsString('created_at', $expr);
        $this->assertStringContainsString('DESC', $expr);
    }

    public function testValidColumnAndDirectionBuildMatchingOrderExpression(): void
    {
        [$key, $dir, $expr] = $this->resolveSort(['get' => ['sort' => 'total', 'dir' => 'asc']]);

        $this->assertSame('total', $key);
        $this->assertSame('asc', $dir);
        $this->assertStringContainsString('total_cents', $expr);
        $this->assertStringContainsString('ASC', $expr);
    }

    public function testInvalidColumnFallsBackToDefaultSort(): void
    {
        [$key, $dir] = $this->resolveSort(['get' => ['sort' => 'coluna_inexistente', 'dir' => 'asc']]);

        $this->assertSame('criado', $key, 'coluna fora da whitelist deve cair no default');
        $this->assertSame('desc', $dir, 'ao cair no default, a direcao tambem volta ao padrao');
    }

    public function testInjectionAttemptInSortParamNeverReachesOrderExpression(): void
    {
        $payload = 'idx; DROP TABLE orders; --';
        [$key, , $expr] = $this->resolveSort(['get' => ['sort' => $payload]]);

        $this->assertSame('criado', $key, 'payload de injecao nao esta na whitelist, entao cai no default');
        $this->assertStringNotContainsString('DROP', $expr, 'o valor cru nunca pode virar parte do ORDER BY');
        $this->assertStringNotContainsString($payload, $expr);
    }

    public function testInvalidDirectionDefaultsToDescending(): void
    {
        [, $dir, $expr] = $this->resolveSort(['get' => ['sort' => 'cliente', 'dir' => '1 OR 1=1']]);

        $this->assertSame('desc', $dir, 'qualquer direcao diferente de asc vira desc, nunca e bindada crua');
        $this->assertStringContainsString('customer_name', $expr);
        $this->assertStringContainsString('DESC', $expr);
        $this->assertStringNotContainsString('1 OR 1=1', $expr);
    }

    public function testArraySortParamDoesNotCrashAndFallsBack(): void
    {
        // ?sort[]=x faz o PHP popular como array — o guard is_string deve tratar
        // como ausente e cair no default, nunca causar TypeError.
        [$key] = $this->resolveSort(['get' => ['sort' => ['total']]]);

        $this->assertSame('criado', $key);
    }

    public function testGatewayColumnSortsByMostRecentChargeGateway(): void
    {
        [$key, , $expr] = $this->resolveSort(['get' => ['sort' => 'gateway', 'dir' => 'asc']]);

        $this->assertSame('gateway', $key);
        // A ordenacao de Gateway espelha attachGatewayNames (cobranca mais recente
        // vence): subquery correlacionada por orders.idx, ordenada por created_at DESC.
        $this->assertStringContainsString('pix_charges', $expr);
        $this->assertStringContainsString('orders.idx', $expr);
        $this->assertStringContainsString('ASC', $expr);
    }
}
