<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre os filtros (buildFilter) e a ordenacao clicavel (resolveSort) de
 * customers_controller (/clientes). Ambos so mapeiam $info['get'] -> SQL, sem
 * tocar `orders` (TestCase puro). O ponto central de resolveSort e o mesmo de
 * orders_controller: SO chaves da whitelist SORTABLE viram ORDER BY — chave
 * forjada cai no default (ultima_compra DESC), nunca vira SQL.
 */
final class CustomersFilterSortTest extends TestCase
{
    /** @return array{0:string[],1:array<int,mixed>} */
    private function buildFilter(array $info): array
    {
        $controller = new customers_controller();
        $method     = new ReflectionMethod($controller, 'buildFilter');
        $method->setAccessible(true);

        return $method->invoke($controller, $info);
    }

    /** @return array{0:string,1:string,2:string} */
    private function resolveSort(array $info): array
    {
        $controller = new customers_controller();
        $method     = new ReflectionMethod($controller, 'resolveSort');
        $method->setAccessible(true);

        return $method->invoke($controller, $info);
    }

    public function testNoFiltersProduceNoConditions(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => []]);

        $this->assertSame([], $conds);
        $this->assertSame([], $params);
    }

    public function testNameEmailPhoneAndDateBuildBoundConditions(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => [
            'nome'        => 'Fulano',
            'email'       => 'fulano@x.com',
            'telefone'    => '(11) 99999-8888',
            'data_inicio' => '2026-07-01',
            'data_fim'    => '2026-07-31',
        ]]);

        $this->assertSame([
            ' o.customer_name LIKE ? ',
            ' o.customer_mail LIKE ? ',
            ' o.customer_phone LIKE ? ',
            ' o.created_at >= ? ',
            ' o.created_at <= ? ',
        ], $conds);

        $this->assertSame([
            '%Fulano%',
            '%fulano@x.com%',
            '%11999998888',          // telefone vira so digitos, casa por sufixo
            '2026-07-01 00:00:00',
            '2026-07-31 23:59:59',
        ], $params);
    }

    public function testShortPhoneIsIgnored(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['telefone' => '99']]);

        $this->assertSame([], $conds, 'telefone abaixo do minimo de digitos nao vira filtro');
        $this->assertSame([], $params);
    }

    public function testInvalidDateIsIgnored(): void
    {
        [$conds] = $this->buildFilter(['get' => ['data_inicio' => '2026-02-30']]);

        $this->assertSame([], $conds, 'data impossivel e descartada');
    }

    public function testDefaultsToLastPurchaseDescending(): void
    {
        [$key, $dir, $expr] = $this->resolveSort(['get' => []]);

        $this->assertSame('ultima_compra', $key);
        $this->assertSame('desc', $dir);
        $this->assertStringContainsString('o.created_at', $expr);
        $this->assertStringContainsString('DESC', $expr);
    }

    public function testValidColumnAscending(): void
    {
        [$key, $dir, $expr] = $this->resolveSort(['get' => ['sort' => 'nome', 'dir' => 'asc']]);

        $this->assertSame('nome', $key);
        $this->assertSame('asc', $dir);
        $this->assertStringContainsString('o.customer_name', $expr);
        $this->assertStringContainsString('ASC', $expr);
    }

    public function testForgedSortKeyFallsBackToDefault(): void
    {
        [$key, $dir, $expr] = $this->resolveSort(['get' => ['sort' => 'customer_mail; DROP TABLE orders', 'dir' => 'asc']]);

        $this->assertSame('ultima_compra', $key, 'chave fora da whitelist cai no default');
        $this->assertSame('desc', $dir);
        $this->assertStringNotContainsString('DROP', $expr, 'nenhum SQL forjado chega ao ORDER BY');
    }
}
