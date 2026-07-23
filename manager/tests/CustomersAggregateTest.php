<?php

declare(strict_types=1);

/**
 * Cobre a agregacao de clientes de customers_controller::index() (plano 023).
 * "Cliente" nao e uma entidade no banco — e um agregado de `orders` por
 * customer_mail, onde o pedido mais recente (MAX(idx)) fornece os campos
 * denormalizados e a contagem total. O metodo termina em include (render), entao
 * reproduz-se aqui a MESMA query de agrupamento/join, escopada aos e-mails da
 * fixture para isolar das demais linhas do banco de teste.
 */
final class CustomersAggregateTest extends DBTestCase
{
    private function makeOrder(string $mail, string $cpf = '12345678909', string $phone = '11999999999'): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => 'pago',
            'customer_name'  => 'Cliente Agg',
            'customer_mail'  => $mail,
            'customer_phone' => $phone,
            'customer_cpf'   => $cpf,
            'ship_zip'       => '01000000',
            'ship_street'    => 'Rua Teste',
            'ship_number'    => '100',
            'ship_district'  => 'Centro',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => 1000,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    public function testGroupsOrdersByMailAndResolvesLatestOrder(): void
    {
        $mailA = 'agg_a_' . uniqid() . '@example.com';
        $mailB = 'agg_b_' . uniqid() . '@example.com';

        $this->makeOrder($mailA);
        $this->makeOrder($mailA);
        $lastA = $this->makeOrder($mailA); // MAX(idx) do cliente A
        $lastB = $this->makeOrder($mailB);

        $model = new orders_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT o.idx AS last_order_idx, o.customer_mail, g.orders_count
               FROM orders o
               INNER JOIN (
                    SELECT customer_mail, MAX(idx) AS max_idx, COUNT(*) AS orders_count
                      FROM orders
                     WHERE active = 'yes' AND customer_mail IN (?, ?)
                     GROUP BY customer_mail
               ) g ON g.max_idx = o.idx
              ORDER BY g.orders_count DESC",
            [$mailA, $mailB]
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows, 'Um agregado por customer_mail');

        $byMail = [];
        foreach ($rows as $r) {
            $byMail[$r['customer_mail']] = $r;
        }

        $this->assertSame(3, (int) $byMail[$mailA]['orders_count'], 'Cliente A tem 3 pedidos');
        $this->assertSame($lastA, (int) $byMail[$mailA]['last_order_idx'], 'Ancora = pedido mais recente (MAX idx) de A');
        $this->assertSame(1, (int) $byMail[$mailB]['orders_count'], 'Cliente B tem 1 pedido');
        $this->assertSame($lastB, (int) $byMail[$mailB]['last_order_idx'], 'Ancora de B = seu unico pedido');
    }
}
