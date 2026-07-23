<?php

declare(strict_types=1);

/**
 * Cobre checkout_controller::findLatestActiveCharge(): a leitura que a Tela 4
 * (plano 004) depende para saber qual PIX mostrar. Extraida de payment() para
 * ser testavel sem passar pelos includes de view (mesmo padrao de
 * lockAndValidateCart(), documentado em CheckoutStockTest).
 */
final class CheckoutPaymentChargeTest extends DBTestCase
{
    private function gatewayIdBySlug(string $slug): int
    {
        $model = new payment_gateways_model();
        $model->set_field([" idx "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $idx = $model->data[0]['idx'] ?? null;
        $this->assertNotNull($idx, "Gateway seed '$slug' nao encontrado (migration 011)");

        return (int)$idx;
    }

    private function createOrder(int $totalCents = 10000): int
    {
        $order = new orders_model();
        $order->populate([
            'token'           => bin2hex(random_bytes(16)),
            'status'          => 'aguardando_pagamento',
            'customer_name'   => 'Cliente Teste',
            'customer_mail'   => 'teste_' . uniqid() . '@example.com',
            'customer_phone'  => '11999999999',
            'customer_cpf'    => '12345678909',
            'ship_zip'        => '01310100',
            'ship_street'     => 'Av. Paulista',
            'ship_number'     => '1000',
            'ship_district'   => 'Bela Vista',
            'ship_city'       => 'São Paulo',
            'ship_uf'         => 'SP',
            'total_cents'     => $totalCents,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        return $orderId;
    }

    private function createCharge(int $ordersId, int $gatewayId, string $gatewayChargeId, int $amountCents): int
    {
        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $ordersId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => $gatewayChargeId,
            'status'              => 'pendente',
            'amount_cents'        => $amountCents,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $chargeIdx = $charge->save();
        $this->assertIsInt($chargeIdx);

        return $chargeIdx;
    }

    public function testNoChargeReturnsNull(): void
    {
        $orderId = $this->createOrder();

        $controller = new checkout_controller();
        $charge = $controller->findLatestActiveCharge($orderId);

        $this->assertNull($charge);
    }

    public function testReturnsTheOnlyCharge(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderId = $this->createOrder(5000);
        $chargeId = $this->createCharge($orderId, $gatewayId, 'chg-unico-' . uniqid(), 5000);

        $controller = new checkout_controller();
        $charge = $controller->findLatestActiveCharge($orderId);

        $this->assertNotNull($charge);
        $this->assertSame($chargeId, (int)$charge['idx']);
        $this->assertSame(5000, (int)$charge['amount_cents']);
    }

    public function testReturnsTheMostRecentChargeWhenMoreThanOneExists(): void
    {
        // Um pedido normalmente tem uma unica cobranca — mas se a rota de retry
        // um dia recriar uma, a mais recente (idx desc) e a que vale, nunca a
        // primeira. Regressao aqui seria trocar 'idx desc' por 'idx asc' e
        // mostrar ao comprador um QR/codigo PIX ja obsoleto.
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderId = $this->createOrder(5000);
        $this->createCharge($orderId, $gatewayId, 'chg-antiga-' . uniqid(), 5000);
        $newestChargeId = $this->createCharge($orderId, $gatewayId, 'chg-mais-recente-' . uniqid(), 5000);

        $controller = new checkout_controller();
        $charge = $controller->findLatestActiveCharge($orderId);

        $this->assertNotNull($charge);
        $this->assertSame($newestChargeId, (int)$charge['idx']);
    }

    public function testIgnoresChargesFromOtherOrders(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderA = $this->createOrder(5000);
        $orderB = $this->createOrder(7000);
        $this->createCharge($orderB, $gatewayId, 'chg-do-pedido-b-' . uniqid(), 7000);

        $controller = new checkout_controller();
        $charge = $controller->findLatestActiveCharge($orderA);

        $this->assertNull($charge);
    }

    public function testIgnoresSoftDeletedCharge(): void
    {
        // set_filter() substitui (nao mescla) o $filter base do model — a
        // clausula active='yes' de findLatestActiveCharge() e re-especificada
        // manualmente. Este teste protege essa clausula de ser removida por
        // engano numa edicao futura.
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderId = $this->createOrder(5000);
        $chargeId = $this->createCharge($orderId, $gatewayId, 'chg-removida-' . uniqid(), 5000);

        $remove = new pix_charges_model();
        $remove->set_filter(['idx = ?'], [$chargeId]);
        $remove->remove();

        $controller = new checkout_controller();
        $charge = $controller->findLatestActiveCharge($orderId);

        $this->assertNull($charge);
    }
}
