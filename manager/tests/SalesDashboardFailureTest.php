<?php

declare(strict_types=1);

/**
 * Cobre os 4 blocos `catch (RuntimeException)` de site_controller (Plano
 * 011) — cada agregacao (salesKpis/ordersByStatus/topProducts/
 * recentOrders) precisa devolver o fallback documentado
 * (zeros/vazio) quando a query falha, em vez de deixar a excecao subir e
 * quebrar o dashboard inteiro.
 *
 * Simular uma falha real de banco (schema quebrado, conexao derrubada)
 * exigiria mexer no banco compartilhado de dev ou introduzir mocking de
 * PDO — nenhum dos dois e aceitavel aqui. Em vez disso, `site_controller`
 * ganhou 2 factories protegidas (`newOrdersModel()`/`newOrderItemsModel()`)
 * — o UNICO ponto de instanciacao das models dentro de
 * cada metodo — e este teste sobrescreve so a factory correspondente para
 * devolver uma model cujo `execute_raw_prepared()`/`load_data()` lanca
 * RuntimeException direto, reproduzindo exatamente a excecao que
 * `localPDO::executePrepared()` lanca numa falha real (ver
 * app/inc/lib/localPDO.php:193). Nao precisa de banco (TestCase puro).
 */

final class ThrowingOrdersModel extends orders_model
{
    public function execute_raw_prepared(string $sql, array $params = []): \PDOStatement
    {
        throw new RuntimeException('Simulated DB failure');
    }

    public function load_data(bool $withCount = true): void
    {
        throw new RuntimeException('Simulated DB failure');
    }
}

final class ThrowingOrderItemsModel extends order_items_model
{
    public function execute_raw_prepared(string $sql, array $params = []): \PDOStatement
    {
        throw new RuntimeException('Simulated DB failure');
    }
}

final class ThrowingPaymentGatewaysModel extends payment_gateways_model
{
    public function load_data(bool $withCount = true): void
    {
        throw new RuntimeException('Simulated DB failure');
    }
}

/**
 * Sobrescreve so a factory indicada no construtor; as outras seguem
 * instanciando a model real (sem uso neste arquivo, ja que cada teste
 * chama so 1 metodo, mas mantem a subclasse honesta sobre o que substitui).
 */
final class FailingModelSiteController extends site_controller
{
    public function __construct(private readonly string $failingFactory)
    {
    }

    protected function newOrdersModel(): orders_model
    {
        return $this->failingFactory === 'orders' ? new ThrowingOrdersModel() : parent::newOrdersModel();
    }

    protected function newOrderItemsModel(): order_items_model
    {
        return $this->failingFactory === 'order_items' ? new ThrowingOrderItemsModel() : parent::newOrderItemsModel();
    }

    protected function newPaymentGatewaysModel(): payment_gateways_model
    {
        return $this->failingFactory === 'payment_gateways' ? new ThrowingPaymentGatewaysModel() : parent::newPaymentGatewaysModel();
    }
}

final class SalesDashboardFailureTest extends \PHPUnit\Framework\TestCase
{
    public function testSalesKpisReturnsAllZerosWhenQueryFails(): void
    {
        $controller = new FailingModelSiteController('orders');

        $result = $controller->salesKpis();

        $this->assertSame([
            'revenue_cents'    => 0,
            'paid_orders'      => 0,
            'avg_ticket_cents' => 0,
            'awaiting'         => 0,
        ], $result, 'falha de query deve devolver todos os KPIs zerados, nunca lancar');
    }

    public function testOrdersByStatusReturnsZeroedMapWhenQueryFails(): void
    {
        $controller = new FailingModelSiteController('orders');

        $result = $controller->ordersByStatus();

        $this->assertSame([
            'aguardando_pagamento' => 0,
            'pago'                 => 0,
            'cancelado'            => 0,
            'expirado'             => 0,
        ], $result, 'falha de query deve devolver as 4 chaves zeradas, nunca lancar');
    }

    public function testTopProductsReturnsEmptyArrayWhenQueryFails(): void
    {
        $controller = new FailingModelSiteController('order_items');

        $result = $controller->topProducts();

        $this->assertSame([], $result, 'falha de query deve devolver array vazio, nunca lancar');
    }

    public function testRecentOrdersReturnsEmptyArrayWhenQueryFails(): void
    {
        $controller = new FailingModelSiteController('orders');

        $result = $controller->recentOrders();

        $this->assertSame([], $result, 'falha de query deve devolver array vazio, nunca lancar');
    }

    public function testPaymentGatewaysReturnsEmptyArrayWhenQueryFails(): void
    {
        $controller = new FailingModelSiteController('payment_gateways');

        $result = $controller->paymentGateways();

        $this->assertSame([], $result, 'falha de query deve devolver array vazio, nunca lancar');
    }
}
