<?php

declare(strict_types=1);

/**
 * Cobre orders_controller::attachGatewayNames() — o metodo privado que anexa
 * gateway_name a cada pedido da listagem (/pedidos), consultando pix_charges.
 * OrdersSortTest ja cobre a EXPRESSAO SQL usada para ordenar pela coluna
 * Gateway (mesma regra: cobranca mais recente vence), mas nenhum teste
 * exercitava attachGatewayNames() em si contra dados reais — esta suite
 * fecha essa lacuna.
 */
final class OrdersGatewayAttachTest extends DBTestCase
{
    /** @return array<int,array<string,mixed>> */
    private function attachGatewayNames(array $orders): array
    {
        $controller = new orders_controller();
        $method     = new ReflectionMethod($controller, 'attachGatewayNames');
        $method->setAccessible(true);

        return $method->invoke($controller, $orders);
    }

    private function makeOrder(): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => 'pago',
            'customer_name'  => 'Cliente Gateway Attach',
            'customer_mail'  => 'gwattach_' . uniqid() . '@example.com',
            'customer_phone' => '11999999999',
            'customer_cpf'   => '12345678909',
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

    /** @return int[] */
    private function gatewayIds(int $limit): array
    {
        $model = new payment_gateways_model();
        $model->set_field([' idx ']);
        $model->set_filter([" active = 'yes' "]);
        $model->set_order([' idx ASC ']);
        $model->set_paginate([0, $limit]);
        $model->load_data(false);

        return array_map(static fn(array $r): int => (int) $r['idx'], $model->data);
    }

    private function makeCharge(int $orderId, int $gatewayId, string $createdAt): int
    {
        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $orderId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => 'chg_' . uniqid(),
            'status'              => 'pendente',
            'amount_cents'        => 1000,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = (int) $charge->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de cobranca deve retornar um ID valido');

        if ($createdAt !== '') {
            $update = new pix_charges_model();
            $update->set_filter(['idx = ?'], [$id]);
            $update->populate(['created_at' => $createdAt]);
            $update->save();
        }

        return $id;
    }

    public function testMostRecentChargeGatewayWins(): void
    {
        $gatewayIds = $this->gatewayIds(2);
        if (count($gatewayIds) < 2) {
            $this->markTestSkipped('Precisa de ao menos 2 gateways semeados para exercitar o teste.');
        }
        [$older, $newer] = $gatewayIds;

        $orderId = $this->makeOrder();
        $this->makeCharge($orderId, $older, '2026-01-01 10:00:00');
        $this->makeCharge($orderId, $newer, '2026-01-02 10:00:00');

        $result = $this->attachGatewayNames([['idx' => $orderId]]);

        $newerName = null;
        $nameModel = new payment_gateways_model();
        $nameModel->set_field([' name ']);
        $nameModel->set_filter(['idx = ?'], [$newer]);
        $nameModel->set_paginate([1]);
        $nameModel->load_data(false);
        $newerName = $nameModel->data[0]['name'] ?? null;

        $this->assertSame($newerName, $result[0]['gateway_name'], 'a cobranca mais recente deve vencer, nunca a mais antiga');
    }

    public function testOrderWithoutChargeGetsNullGatewayName(): void
    {
        $orderId = $this->makeOrder();

        $result = $this->attachGatewayNames([['idx' => $orderId]]);

        $this->assertNull($result[0]['gateway_name'], 'pedido sem nenhuma cobranca deve vir com gateway_name null, nunca quebrar');
    }
}
