<?php

declare(strict_types=1);

/**
 * Cobre checkout_controller::captureGatewayReturnParams() (Plano 022): captura
 * passiva do transaction_nsu/slug que a InfinitePay manda como query param na URL
 * de retorno do comprador — nunca pelo webhook.
 *
 * Testado diretamente (nao via done()) porque done() inclui views e depende de
 * cRootServer/DOCUMENT_ROOT de producao, nao chamavel direto na suite — mesmo
 * precedente ja aceito em CheckoutStockTest/CheckoutChargeCompensationTest, que
 * testam metodos extraidos do controller em vez do controller inteiro.
 */
final class CheckoutDoneNsuCaptureTest extends DBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    private function gatewayIdBySlug(string $slug): int
    {
        $model = new payment_gateways_model();
        $model->set_field([" idx "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $idx = $model->data[0]['idx'] ?? null;
        $this->assertNotNull($idx, "Gateway seed '$slug' nao encontrado (migrations/007_create_table_payment_gateways.sql)");

        return (int)$idx;
    }

    private function createOrder(): int
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
            'total_cents'     => 5000,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        return $orderId;
    }

    private function createChargeWithNsu(int $ordersId, int $gatewayId, ?string $transactionNsu = null): int
    {
        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $ordersId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => 'chg-' . uniqid(),
            'status'              => 'pendente',
            'amount_cents'        => 5000,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = $charge->save();
        $this->assertIsInt($id);

        if ($transactionNsu !== null) {
            $charge->execute_raw_prepared(
                'UPDATE pix_charges SET transaction_nsu = ? WHERE idx = ?',
                [$transactionNsu, $id]
            );
        }

        return $id;
    }

    private function loadOrder(int $idx): array
    {
        $model = new orders_model();
        $model->set_filter(['idx = ?'], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    /**
     * select() com lista explicita de colunas (nao load_data()): o $field
     * padrao de pix_charges_model nao inclui gateway_invoice_slug (coluna nova
     * desta migration), entao load_data() nao a devolveria.
     */
    private function loadCharge(int $idx): array
    {
        $stmt = (new pix_charges_model())->select(
            [' idx ', ' transaction_nsu ', ' gateway_invoice_slug ', ' gateway_charge_id '],
            'WHERE idx = ?',
            [$idx]
        );

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testHappyPathCapturesNsuAndInvoiceSlug(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderId = $this->createOrder();
        $chargeId = $this->createChargeWithNsu($orderId, $gatewayId);
        $charge = $this->loadCharge($chargeId);

        $transactionNsu = 'nsu-' . uniqid();
        $invoiceSlug = 'slug-' . uniqid();
        $_GET = [
            'transaction_nsu' => $transactionNsu,
            'slug'            => $invoiceSlug,
            'order_nsu'       => $charge['gateway_charge_id'],
        ];

        $controller = new checkout_controller();
        $controller->captureGatewayReturnParams(['idx' => $orderId]);

        $chargeAfter = $this->loadCharge($chargeId);
        $this->assertSame($transactionNsu, $chargeAfter['transaction_nsu']);
        $this->assertSame($invoiceSlug, $chargeAfter['gateway_invoice_slug']);
    }

    public function testDoesNotOverwriteExistingNsu(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderId = $this->createOrder();
        // Valor unico por teste: transaction_nsu tem UNIQUE em pix_charges
        // (migrations/010) e fixtures de execucoes anteriores no mesmo
        // processo permanecem visiveis (ver docblock de DBTestCase).
        $existingNsu = 'nsu-antigo-' . uniqid();
        $chargeId = $this->createChargeWithNsu($orderId, $gatewayId, $existingNsu);
        $charge = $this->loadCharge($chargeId);

        $_GET = [
            'transaction_nsu' => 'nsu-novo-' . uniqid(),
            'slug'            => 'slug-' . uniqid(),
            'order_nsu'       => $charge['gateway_charge_id'],
        ];

        $controller = new checkout_controller();
        $controller->captureGatewayReturnParams(['idx' => $orderId]);

        $chargeAfter = $this->loadCharge($chargeId);
        $this->assertSame($existingNsu, $chargeAfter['transaction_nsu']);
    }

    public function testDivergentOrderNsuIsRejected(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderId = $this->createOrder();
        $chargeId = $this->createChargeWithNsu($orderId, $gatewayId);

        $_GET = [
            'transaction_nsu' => 'nsu-' . uniqid(),
            'slug'            => 'slug-' . uniqid(),
            'order_nsu'       => 'order-nsu-de-outro-pedido-' . uniqid(),
        ];

        $controller = new checkout_controller();
        $controller->captureGatewayReturnParams(['idx' => $orderId]);

        $chargeAfter = $this->loadCharge($chargeId);
        $this->assertNull($chargeAfter['transaction_nsu']);
    }

    public function testEmptyGetDoesNothing(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $orderId = $this->createOrder();
        $chargeId = $this->createChargeWithNsu($orderId, $gatewayId);

        $_GET = [];

        $controller = new checkout_controller();
        $controller->captureGatewayReturnParams(['idx' => $orderId]);

        $chargeAfter = $this->loadCharge($chargeId);
        $this->assertNull($chargeAfter['transaction_nsu']);
    }

    public function testOrderStatusNeverChangesAcrossAllCases(): void
    {
        $gatewayId = $this->gatewayIdBySlug('infinitepay');

        // Caso feliz
        $orderId1 = $this->createOrder();
        $chargeId1 = $this->createChargeWithNsu($orderId1, $gatewayId);
        $charge1 = $this->loadCharge($chargeId1);
        $_GET = [
            'transaction_nsu' => 'nsu-' . uniqid(),
            'slug'            => 'slug-' . uniqid(),
            'order_nsu'       => $charge1['gateway_charge_id'],
        ];
        (new checkout_controller())->captureGatewayReturnParams(['idx' => $orderId1]);
        $order1After = $this->loadOrder($orderId1);
        $this->assertSame('aguardando_pagamento', $order1After['status']);
        $this->assertNull($order1After['paid_at']);

        // Nao sobrescreve
        $orderId2 = $this->createOrder();
        $chargeId2 = $this->createChargeWithNsu($orderId2, $gatewayId, 'nsu-antigo-' . uniqid());
        $charge2 = $this->loadCharge($chargeId2);
        $_GET = [
            'transaction_nsu' => 'nsu-novo-' . uniqid(),
            'slug'            => 'slug-' . uniqid(),
            'order_nsu'       => $charge2['gateway_charge_id'],
        ];
        (new checkout_controller())->captureGatewayReturnParams(['idx' => $orderId2]);
        $order2After = $this->loadOrder($orderId2);
        $this->assertSame('aguardando_pagamento', $order2After['status']);
        $this->assertNull($order2After['paid_at']);

        // order_nsu divergente
        $orderId3 = $this->createOrder();
        $chargeId3 = $this->createChargeWithNsu($orderId3, $gatewayId);
        $_GET = [
            'transaction_nsu' => 'nsu-' . uniqid(),
            'slug'            => 'slug-' . uniqid(),
            'order_nsu'       => 'order-nsu-de-outro-pedido-' . uniqid(),
        ];
        (new checkout_controller())->captureGatewayReturnParams(['idx' => $orderId3]);
        $order3After = $this->loadOrder($orderId3);
        $this->assertSame('aguardando_pagamento', $order3After['status']);
        $this->assertNull($order3After['paid_at']);

        // $_GET vazio
        $orderId4 = $this->createOrder();
        $this->createChargeWithNsu($orderId4, $gatewayId);
        $_GET = [];
        (new checkout_controller())->captureGatewayReturnParams(['idx' => $orderId4]);
        $order4After = $this->loadOrder($orderId4);
        $this->assertSame('aguardando_pagamento', $order4After['status']);
        $this->assertNull($order4After['paid_at']);
    }
}
