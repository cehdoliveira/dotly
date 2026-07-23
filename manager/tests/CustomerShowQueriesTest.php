<?php

declare(strict_types=1);

/**
 * Cobre as queries de historico/resumo de customers_controller::show()
 * (/clientes/{idx}): histStmt (pedidos do cliente, mais recente primeiro) e
 * sumStmt (contagem, total pago, primeira/ultima compra). show() nao pode ser
 * chamado diretamente (termina em include de view / basic_redir em erro),
 * entao esta suite reproduz exatamente as mesmas duas queries que o metodo
 * monta a partir do customer_mail resolvido pela ancora. CustomerDetailViewTest
 * ja cobre a RENDERIZACAO desses dados como fixture — esta suite cobre a
 * consulta em si.
 */
final class CustomerShowQueriesTest extends DBTestCase
{
    private function makeOrder(string $mail, string $status, ?string $paidAt, int $totalCents): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => $status,
            'customer_name'  => 'Cliente Historico',
            'customer_mail'  => $mail,
            'customer_phone' => '11999999999',
            'customer_cpf'   => '12345678909',
            'ship_zip'       => '01000000',
            'ship_street'    => 'Rua Teste',
            'ship_number'    => '100',
            'ship_district'  => 'Centro',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => $totalCents,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        if ($paidAt !== null) {
            $update = new orders_model();
            $update->set_filter(['idx = ?'], [$id]);
            $update->populate(['paid_at' => $paidAt]);
            $update->save();
        }

        return $id;
    }

    public function testHistoryOrdersOrderedNewestFirst(): void
    {
        $mail = 'hist_' . uniqid() . '@example.com';

        $first  = $this->makeOrder($mail, 'expirado', null, 1000);
        $second = $this->makeOrder($mail, 'pago', date('Y-m-d H:i:s'), 2000);

        // Mesma query de histStmt em customers_controller::show().
        $model = new orders_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT idx, token, status, total_cents, created_at, paid_at, shipped_at
               FROM orders
              WHERE active = 'yes' AND customer_mail = ?
              ORDER BY created_at DESC, idx DESC",
            [$mail]
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids  = array_map(static fn(array $r): int => (int) $r['idx'], $rows);

        $this->assertCount(2, $rows, 'historico deve trazer os 2 pedidos do cliente');
        $this->assertSame([$second, $first], $ids, 'pedido mais recente (idx maior/created_at mais novo) deve vir primeiro');
    }

    public function testSummaryAggregatesCountPaidCentsFirstLastPurchase(): void
    {
        $mail = 'sum_' . uniqid() . '@example.com';

        $this->makeOrder($mail, 'pago', date('Y-m-d H:i:s'), 5000);
        $this->makeOrder($mail, 'pago', date('Y-m-d H:i:s'), 3000);
        $this->makeOrder($mail, 'aguardando_pagamento', null, 9999);

        // Mesma query de sumStmt em customers_controller::show().
        $model = new orders_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT COUNT(*) AS orders_count,
                    COALESCE(SUM(CASE WHEN status = 'pago' THEN total_cents ELSE 0 END), 0) AS paid_cents,
                    MIN(created_at) AS first_purchase, MAX(created_at) AS last_purchase
               FROM orders
              WHERE active = 'yes' AND customer_mail = ?",
            [$mail]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(3, (int) $row['orders_count'], 'contagem deve incluir TODOS os pedidos do cliente, pagos ou nao');
        $this->assertSame(8000, (int) $row['paid_cents'], 'total pago deve somar so os pedidos com status pago, ignorando o aguardando (9999)');
        $this->assertNotNull($row['first_purchase']);
        $this->assertNotNull($row['last_purchase']);
    }
}
