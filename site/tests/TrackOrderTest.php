<?php

declare(strict_types=1);

/**
 * Cobre track_order_controller::findOrders(): a busca publica "Acompanhar meu
 * pedido" (plano 017) que exige e-mail + 4 ultimos digitos do telefone
 * baterem JUNTOS — nunca deve vazar dado com apenas 1 campo correto.
 * Extraida de search() para ser testavel sem passar pelos includes de view
 * (mesmo padrao de findLatestActiveCharge(), ver CheckoutPaymentChargeTest).
 */
final class TrackOrderTest extends DBTestCase
{
    private function createOrder(array $overrides = []): int
    {
        $order = new orders_model();
        $order->populate(array_merge([
            'token'           => bin2hex(random_bytes(16)),
            'status'          => 'aguardando_pagamento',
            'customer_name'   => 'Cliente Teste',
            'customer_mail'   => 'teste_' . uniqid() . '@example.com',
            'customer_phone'  => '11988887777',
            'customer_cpf'    => '12345678909',
            'ship_zip'        => '01310100',
            'ship_street'     => 'Av. Paulista',
            'ship_number'     => '1000',
            'ship_district'   => 'Bela Vista',
            'ship_city'       => 'São Paulo',
            'ship_uf'         => 'SP',
            'total_cents'     => 10000,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ], $overrides));
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        return $orderId;
    }

    public function testBothFieldsMatchReturnsOrder(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $orderId = $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988887777',
        ]);

        $controller = new track_order_controller();
        $orders = $controller->findOrders($mail, '7777');

        $this->assertCount(1, $orders);
        $this->assertSame($orderId, (int)$orders[0]['idx']);
    }

    public function testOnlyMailMatchesReturnsEmpty(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988887777',
        ]);

        $controller = new track_order_controller();
        // e-mail certo, telefone errado
        $orders = $controller->findOrders($mail, '0000');

        $this->assertSame([], $orders);
    }

    public function testOnlyPhoneMatchesReturnsEmpty(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988887777',
        ]);

        $controller = new track_order_controller();
        // telefone certo, e-mail errado
        $orders = $controller->findOrders('errado_' . uniqid() . '@b.com', '7777');

        $this->assertSame([], $orders);
    }

    public function testPhone4NormalizationMatchesDigitsOnlyPhone(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $orderId = $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11999995555',
        ]);

        $controller = new track_order_controller();
        $orders = $controller->findOrders($mail, '5555');

        $this->assertCount(1, $orders);
        $this->assertSame($orderId, (int)$orders[0]['idx']);
    }

    public function testReturnsTrackingCodeAndShippedAtWhenShipped(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $order = new orders_model();
        $order->populate([
            'token'           => bin2hex(random_bytes(16)),
            'status'          => 'pago',
            'customer_name'   => 'Cliente Teste',
            'customer_mail'   => $mail,
            'customer_phone'  => '11988886666',
            'customer_cpf'    => '12345678909',
            'ship_zip'        => '01310100',
            'ship_street'     => 'Av. Paulista',
            'ship_number'     => '1000',
            'ship_district'   => 'Bela Vista',
            'ship_city'       => 'São Paulo',
            'ship_uf'         => 'SP',
            'total_cents'     => 10000,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'tracking_code'   => 'BR123456789XY',
            'shipped_at'      => date('Y-m-d H:i:s'),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        $controller = new track_order_controller();
        $orders = $controller->findOrders($mail, '6666');

        $this->assertCount(1, $orders);
        $this->assertSame('BR123456789XY', $orders[0]['tracking_code']);
        $this->assertNotNull($orders[0]['shipped_at']);
    }

    public function testReturnsNullShippedAtWhenNotShipped(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988884444',
        ]);

        $controller = new track_order_controller();
        $orders = $controller->findOrders($mail, '4444');

        $this->assertCount(1, $orders);
        $this->assertNull($orders[0]['shipped_at']);
    }

    public function testFailOpenRateLimitDoesNotBlockWhenRedisNull(): void
    {
        // Fail-open: check_and_increment_rate_limit() com $redis = null cai no
        // fallback de arquivo; se o diretorio estiver disponivel (caso normal
        // em dev/CI), a primeira tentativa nao bloqueia.
        $blocked = check_and_increment_rate_limit(null, 'track_order_test:' . uniqid(), 5, 300);

        $this->assertFalse($blocked);
    }

    public function testExcludesSoftDeletedOrder(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $orderId = $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988883333',
        ]);

        $remove = new orders_model();
        $remove->set_filter(['idx = ?'], [$orderId]);
        $remove->remove();

        $controller = new track_order_controller();
        $orders = $controller->findOrders($mail, '3333');

        $this->assertSame([], $orders);
    }

    public function testNeverSelectsCpfOrAddressFields(): void
    {
        $mail = 'a_' . uniqid() . '@b.com';
        $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988882222',
        ]);

        $controller = new track_order_controller();
        $orders = $controller->findOrders($mail, '2222');

        $this->assertCount(1, $orders);
        $forbidden = ['customer_cpf', 'ship_zip', 'ship_street', 'ship_number', 'ship_complement', 'ship_district', 'ship_city', 'ship_uf', 'customer_mail', 'customer_phone'];
        foreach ($forbidden as $field) {
            $this->assertArrayNotHasKey($field, $orders[0], "findOrders() nunca pode selecionar '$field'");
        }
    }

    public function testReturnsAllMatchingOrdersMostRecentFirst(): void
    {
        // save() forca created_at = now() no INSERT; para testar a ordenacao
        // sem depender de sleep(), rebaixamos o created_at do pedido mais
        // antigo via UPDATE direto apos o insert.
        $mail = 'a_' . uniqid() . '@b.com';
        $olderId = $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988881111',
        ]);
        $backdate = new orders_model();
        $backdate->execute_raw_prepared(
            "UPDATE orders SET created_at = ? WHERE idx = ?",
            [date('Y-m-d H:i:s', strtotime('-1 day')), $olderId]
        );
        $newerId = $this->createOrder([
            'customer_mail'  => $mail,
            'customer_phone' => '11988881111',
        ]);

        $controller = new track_order_controller();
        $orders = $controller->findOrders($mail, '1111');

        $this->assertCount(2, $orders);
        $this->assertSame($newerId, (int)$orders[0]['idx']);
        $this->assertSame($olderId, (int)$orders[1]['idx']);
    }

    public function testFindOrdersDegradesSafelyOnMalformedInput(): void
    {
        $controller = new track_order_controller();

        $this->assertSame([], $controller->findOrders('', '1234'));
        $this->assertSame([], $controller->findOrders('a@b.com', '12'));
        $this->assertSame([], $controller->findOrders('a@b.com', 'abcd'));
        $this->assertSame([], $controller->findOrders("o'brien+tag@example.com", '1234'));
    }
}
