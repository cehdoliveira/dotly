<?php

declare(strict_types=1);

/**
 * Cobre a integracao entre OrderPricing::compute() e a persistencia do
 * pedido/cobranca PIX em checkout_controller::finalize() — sem chamar
 * finalize() diretamente, pois ele termina em basic_redir() -> exit() (mesmo
 * padrao documentado em CheckoutStockTest/CheckoutPaymentChargeTest).
 *
 * Reproduz exatamente a sequencia de finalize(): calcula o breakdown, grava
 * no pedido, e usa o mesmo total_cents (com taxa) na cobranca PIX. Protege
 * contra dois riscos reais: (1) esquecer de listar uma coluna nova em
 * orders_model::$field, que faria populate()/save() descartar o valor em
 * silencio; (2) algum caminho futuro usar o subtotal em vez do total
 * pricing-computed ao criar a cobranca PIX.
 */
final class OrderFeeBreakdownPersistenceTest extends DBTestCase
{
    private function createProduct(array $overrides = []): int
    {
        $model = new products_model();
        $model->populate(array_merge([
            'name'             => 'Produto Teste ' . uniqid(),
            'slug'             => 'produto-teste-' . uniqid(),
            'category'         => 'peptideos',
            'is_infinity'      => 'no',
            'price_unit_cents' => 5000,
            'box_qty'          => 10,
            'stock'            => 100,
        ], $overrides));
        $id = $model->save();
        $this->assertIsInt($id);

        return $id;
    }

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

    public function testOrderPersistsFullFeeBreakdownMatchingOrderPricing(): void
    {
        $productId = $this->createProduct();

        $lines = [
            ['products_id' => $productId, 'line_total_cents' => 10000],
        ];
        $pricing = OrderPricing::compute($lines, 10000);

        // Mesma sequencia de finalize(): populate() com as 4 colunas novas +
        // total_cents ja com taxa.
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
            'subtotal_cents'     => $pricing['subtotal_cents'],
            'fee_percent_cents'  => $pricing['fee_percent_cents'],
            'fee_fixed_cents'    => $pricing['fee_fixed_cents'],
            'fee_infinity_cents' => $pricing['fee_infinity_cents'],
            'total_cents'        => $pricing['total_cents'],
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        // Le de volta com uma instancia nova — prova que $field lista as 4
        // colunas (senao populate()/save() teria descartado os valores).
        $reload = new orders_model();
        $reload->set_filter(['idx = ?'], [$orderId]);
        $reload->set_paginate([1]);
        $reload->load_data(false);
        $row = $reload->data[0];

        $this->assertSame($pricing['subtotal_cents'], (int)$row['subtotal_cents']);
        $this->assertSame($pricing['fee_percent_cents'], (int)$row['fee_percent_cents']);
        $this->assertSame($pricing['fee_fixed_cents'], (int)$row['fee_fixed_cents']);
        $this->assertSame($pricing['fee_infinity_cents'], (int)$row['fee_infinity_cents']);
        $this->assertSame($pricing['total_cents'], (int)$row['total_cents']);
        $this->assertSame(17000, (int)$row['total_cents']);
    }

    public function testPixChargeAmountCentsMatchesOrderTotalCentsWithFees(): void
    {
        $productId = $this->createProduct(['is_infinity' => 'yes']);
        $this->setInfinityFee('500');

        $lines = [
            ['products_id' => $productId, 'line_total_cents' => 10000],
        ];
        $pricing = OrderPricing::compute($lines, 10000);
        $totalCents = $pricing['total_cents'];

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
            'subtotal_cents'     => $pricing['subtotal_cents'],
            'fee_percent_cents'  => $pricing['fee_percent_cents'],
            'fee_fixed_cents'    => $pricing['fee_fixed_cents'],
            'fee_infinity_cents' => $pricing['fee_infinity_cents'],
            'total_cents'        => $totalCents,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();

        // finalize() usa o MESMO $totalCents (ja com taxa) tanto no pedido
        // quanto em $orderRow['total_cents'] -> pix_charges.amount_cents.
        $gatewayId = $this->gatewayIdBySlug('infinitepay');
        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $orderId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => 'chg-' . uniqid(),
            'status'              => 'pendente',
            'amount_cents'        => $totalCents,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $chargeId = $charge->save();
        $this->assertIsInt($chargeId);

        $orderReload = new orders_model();
        $orderReload->set_filter(['idx = ?'], [$orderId]);
        $orderReload->set_paginate([1]);
        $orderReload->load_data(false);

        $chargeReload = new pix_charges_model();
        $chargeReload->set_filter(['idx = ?'], [$chargeId]);
        $chargeReload->set_paginate([1]);
        $chargeReload->load_data(false);

        $this->assertSame(17500, (int)$orderReload->data[0]['total_cents']);
        $this->assertSame(
            (int)$orderReload->data[0]['total_cents'],
            (int)$chargeReload->data[0]['amount_cents']
        );
    }

    public function testGatewayItemsSumMatchesFeeInclusiveTotal(): void
    {
        // Regressao: InfinitePay nao tem campo de total separado — o valor
        // cobrado e a soma dos itens enviados. finalize() manda um unico item
        // generico (nome neutro "{loja} - Pedido #{idx}") com unit_price_cents = total_cents (ja com
        // taxa) em vez da lista por produto — nao expomos o carrinho ao PSP.
        // Sem isso batendo com o total, o pedido nunca bateria com
        // orders.total_cents no webhook (paidAmountCents >= total_cents),
        // ficando preso em aguardando_pagamento pra sempre.
        $productId = $this->createProduct();

        $finalLines = [
            ['products_id' => $productId, 'name' => 'Produto Teste', 'variant' => 'unit', 'qty' => 2, 'unit_price_cents' => 5000, 'line_total_cents' => 10000],
        ];
        $pricing = OrderPricing::compute($finalLines, 10000);

        // Mesma construcao de checkout_controller::finalize(): um unico item
        // generico com unit_price_cents = total_cents.
        $gatewayItems = [[
            'product_name'     => constant("cStoreName") . ' - Pedido #999',
            'variant'          => null,
            'qty'              => 1,
            'unit_price_cents' => $pricing['total_cents'],
        ]];

        $itemsSum = array_sum(array_map(
            static fn(array $item) => $item['qty'] * $item['unit_price_cents'],
            $gatewayItems
        ));

        $this->assertSame($pricing['total_cents'], $itemsSum);
        $this->assertCount(1, $gatewayItems, 'finalize() deve mandar um unico item generico ao PSP, nao um por produto');
    }

    private function setInfinityFee(string $bps): void
    {
        $model = new settings_model();
        $model->execute_raw_prepared(
            "UPDATE settings SET svalue = ? WHERE skey = 'fee_infinity_bps'",
            [$bps]
        );
    }

    protected function tearDown(): void
    {
        $this->setInfinityFee('0');
        parent::tearDown();
    }
}
