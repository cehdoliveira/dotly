<?php

declare(strict_types=1);

/**
 * Cobre o filtro por status usado por orders_controller::index() — mesma forma de
 * set_filter/? que o controller usa, e a mesma lista fixa de status validos
 * (VALID_STATUSES) para provar que um valor fora da lista e ignorado, nunca bindado.
 */
final class OrdersFilterTest extends DBTestCase
{
    private const VALID_STATUSES = ['aguardando_pagamento', 'pago', 'cancelado', 'expirado'];

    /**
     * Invoca orders_controller::buildFilter() via reflection (mesmo padrao de
     * OrdersExportTest) para cobrir os filtros de cpf/telefone (plano 019).
     */
    private function buildFilter(array $info): array
    {
        $controller = new orders_controller();
        $method     = new ReflectionMethod($controller, 'buildFilter');
        $method->setAccessible(true);

        return $method->invoke($controller, $info);
    }

    private function makeOrder(string $status, string $marker, string $cpf = '12345678909', string $phone = '11999999999'): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => $status,
            'customer_name'  => "Cliente {$marker}",
            'customer_mail'  => 'cliente_' . uniqid() . '@example.com',
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

    public function testValidStatusFilterReturnsOnlyMatchingOrders(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker);
        $this->makeOrder('pago', $marker);
        $this->makeOrder('aguardando_pagamento', $marker);

        $statusParam = 'pago';
        $status = in_array($statusParam, self::VALID_STATUSES, true) ? $statusParam : null;
        $this->assertSame('pago', $status, 'status valido nao deve ser descartado pelo guard');

        $model = new orders_model();
        $model->set_field([' idx ', ' status ', ' customer_name ']);
        $model->set_filter([" active = 'yes' ", " status = ? ", " customer_name LIKE ? "], [$status, "%{$marker}%"]);
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'filtro por status=pago deve retornar apenas os 2 pedidos pagos da fixture');
        foreach ($model->data as $row) {
            $this->assertSame('pago', $row['status']);
        }
    }

    public function testInvalidStatusIsIgnoredNotInjected(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker);
        $this->makeOrder('aguardando_pagamento', $marker);

        // Tentativa de injecao via querystring — nao esta na lista fixa de status.
        $statusParam = "pago' OR '1'='1";
        $status = in_array($statusParam, self::VALID_STATUSES, true) ? $statusParam : null;

        $this->assertNull($status, 'valor fora da lista fixa de status deve ser ignorado, nunca bindado');

        // Reproduz o comportamento de orders_controller::index(): status===null nao
        // aplica filtro de status nenhum (nao quebra a query, nao injeta).
        $model = new orders_model();
        $model->set_field([' idx ', ' status ', ' customer_name ']);
        if ($status !== null) {
            $model->set_filter([" active = 'yes' ", " status = ? ", " customer_name LIKE ? "], [$status, "%{$marker}%"]);
        } else {
            $model->set_filter([" active = 'yes' ", " customer_name LIKE ? "], ["%{$marker}%"]);
        }
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'status invalido deve ser ignorado e devolver todos os pedidos da fixture, sem quebrar nem injetar');
    }

    public function testBuildFilterWithMaskedCpfNormalizesAndFilters(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker, cpf: '12345678909');
        $this->makeOrder('pago', $marker, cpf: '98765432100'); // distractor: mesmo marker, CPF diferente

        [$conds, $params] = $this->buildFilter(['get' => ['cpf' => '123.456.789-09']]);

        $this->assertSame([" active = 'yes' ", " customer_cpf = ? "], $conds);
        $this->assertSame(['12345678909'], $params);

        $model = new orders_model();
        $model->set_field([' idx ', ' customer_cpf ', ' customer_name ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'CPF mascarado deve normalizar para digitos e filtrar so pelo pedido com aquele CPF, excluindo o distractor');
        $this->assertSame('12345678909', $model->data[0]['customer_cpf']);
    }

    public function testBuildFilterWithIncompleteCpfIsIgnored(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['cpf' => '123.456.789']]);

        $this->assertSame([" active = 'yes' "], $conds, 'CPF com menos de 11 digitos nao deve virar condicao de filtro');
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithFourDigitPhoneSuffixMatches(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker, phone: '11999999999');
        $this->makeOrder('pago', $marker, phone: '11988887777'); // distractor: mesmo marker, sufixo diferente

        [$conds, $params] = $this->buildFilter(['get' => ['telefone' => '9999']]);

        $this->assertSame([" active = 'yes' ", " customer_phone LIKE ? "], $conds);
        $this->assertSame(['%9999'], $params);

        $model = new orders_model();
        $model->set_field([' idx ', ' customer_phone ', ' customer_name ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'sufixo de 4 digitos deve bater so com o telefone que termina em 9999, excluindo o distractor');
        $this->assertSame('11999999999', $model->data[0]['customer_phone']);
    }

    public function testBuildFilterWithLessThanFourPhoneDigitsIsIgnored(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['telefone' => '999']]);

        $this->assertSame([" active = 'yes' "], $conds, 'telefone com menos de 4 digitos nao deve virar condicao de filtro');
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithStatusAndCpfCombinesBothConditions(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['status' => 'pago', 'cpf' => '12345678909']]);

        $this->assertSame([" active = 'yes' ", " ((status IN (?) AND shipped_at IS NULL)) ", " customer_cpf = ? "], $conds);
        $this->assertSame(['pago', '12345678909'], $params);
    }

    public function testBuildFilterWithMultipleStatusesMatchesAnyOfThem(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker);
        $this->makeOrder('cancelado', $marker);
        $this->makeOrder('aguardando_pagamento', $marker); // distractor: fora do filtro

        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['pago', 'cancelado']]]);

        $this->assertSame([" active = 'yes' ", " ((status IN (?,?) AND shipped_at IS NULL)) "], $conds);
        $this->assertSame(['pago', 'cancelado'], $params);

        $model = new orders_model();
        $model->set_field([' idx ', ' status ', ' customer_name ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'multi-status deve trazer os pedidos pago E cancelado, excluindo o aguardando_pagamento');
        foreach ($model->data as $row) {
            $this->assertContains($row['status'], ['pago', 'cancelado']);
        }
    }

    public function testBuildFilterWithCreationDateRangeFiltersInclusiveBounds(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['data_inicio' => '2026-07-01', 'data_fim' => '2026-07-31']]);

        $this->assertSame([" active = 'yes' ", " created_at >= ? ", " created_at <= ? "], $conds);
        $this->assertSame(['2026-07-01 00:00:00', '2026-07-31 23:59:59'], $params);
    }

    public function testBuildFilterWithOnlyStartDateFiltersFromThatDay(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['data_inicio' => '2026-07-15']]);

        $this->assertSame([" active = 'yes' ", " created_at >= ? "], $conds);
        $this->assertSame(['2026-07-15 00:00:00'], $params);
    }

    public function testBuildFilterWithImpossibleDateIsIgnored(): void
    {
        // 2026-02-30 nao existe — nao pode virar condicao nem bindar valor rolado.
        [$conds, $params] = $this->buildFilter(['get' => ['data_inicio' => '2026-02-30']]);

        $this->assertSame([" active = 'yes' "], $conds, 'data impossivel deve ser ignorada, nunca bindada');
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithMalformedDateIsIgnored(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['data_fim' => '31/07/2026']]);

        $this->assertSame([" active = 'yes' "], $conds, 'data em formato diferente de Y-m-d deve ser ignorada');
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithGatewayBuildsSubqueryCondition(): void
    {
        [$conds, $params] = $this->buildFilter(['get' => ['gateway' => '3']]);

        $this->assertSame([
            " active = 'yes' ",
            " idx IN (SELECT orders_id FROM pix_charges WHERE active = 'yes' AND payment_gateways_id = ?) ",
        ], $conds);
        $this->assertSame([3], $params);
    }

    public function testBuildFilterWithNonNumericGatewayIsIgnored(): void
    {
        // Injecao via ?gateway=... nao numerico deve ser descartada, nunca bindada.
        [$conds, $params] = $this->buildFilter(['get' => ['gateway' => '1 OR 1=1']]);

        $this->assertSame([" active = 'yes' "], $conds);
        $this->assertSame([], $params);
    }

    public function testGatewayFilterMatchesOnlyOrdersWithChargeOnThatGateway(): void
    {
        $gatewayIds = $this->gatewayIds(2);
        if (count($gatewayIds) < 2) {
            $this->markTestSkipped('Precisa de ao menos 2 gateways semeados para exercitar o filtro.');
        }
        [$gwA, $gwB] = $gatewayIds;

        $marker = uniqid();
        $orderA = $this->makeOrder('pago', $marker);
        $orderB = $this->makeOrder('pago', $marker);
        $this->makeCharge($orderA, $gwA);
        $this->makeCharge($orderB, $gwB);

        [$conds, $params] = $this->buildFilter(['get' => ['gateway' => (string)$gwA]]);

        $model = new orders_model();
        $model->set_field([' idx ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'filtro de gateway deve trazer so o pedido cuja cobranca usa aquele gateway');
        $this->assertSame($orderA, (int)$model->data[0]['idx']);
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

        return array_map(static fn(array $r): int => (int)$r['idx'], $model->data);
    }

    private function makeCharge(int $orderId, int $gatewayId): void
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
        $charge->save();
    }

    public function testBuildFilterWithArrayCpfDoesNotCrash(): void
    {
        // ?cpf[]=x faz o PHP popular $_GET['cpf'] como array. preg_replace() com
        // array de agulhas tem semantica diferente de string — buildFilter() deve
        // tratar como cpf ausente (mesmo endurecimento do plano 014 para ?q[]=).
        [$conds, $params] = $this->buildFilter(['get' => ['cpf' => ['12345678909']]]);

        $this->assertSame([" active = 'yes' "], $conds, 'cpf enviado como array deve ser ignorado, nunca causar erro');
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithArrayTelefoneDoesNotCrash(): void
    {
        // Mesmo endurecimento de testBuildFilterWithArrayCpfDoesNotCrash, mas para
        // telefone[] — os dois parametros passam pelo mesmo guard is_string().
        [$conds, $params] = $this->buildFilter(['get' => ['telefone' => ['9999']]]);

        $this->assertSame([" active = 'yes' "], $conds, 'telefone enviado como array deve ser ignorado, nunca causar erro');
        $this->assertSame([], $params);
    }

    public function testBuildFilterWithShippedOptionMatchesShippedOrders(): void
    {
        // "Enviado" e uma pseudo-opcao do multi-select: nao e status de pagamento
        // (nunca vira bind), casa com quem tem shipped_at preenchido.
        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['enviado']]]);

        $this->assertSame([" active = 'yes' ", " ((shipped_at IS NOT NULL)) "], $conds);
        $this->assertSame([], $params, '"enviado" nao vira placeholder de status — e um eixo separado');
    }

    public function testBuildFilterWithPaidAndShippedUnionsBothDisplayCategories(): void
    {
        // Pago + Enviado => quem aparece como "Pago" (pago e NAO enviado) OU como
        // "Enviado" (enviado). Categorias de exibicao mutuamente exclusivas => OR.
        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['pago', 'enviado']]]);

        $this->assertSame([" active = 'yes' ", " ((status IN (?) AND shipped_at IS NULL) OR (shipped_at IS NOT NULL)) "], $conds);
        $this->assertSame(['pago'], $params, 'so o status de pagamento vira bind');
    }

    public function testShippedFilterReturnsOnlyShippedOrders(): void
    {
        $marker  = uniqid();
        $shipped = $this->makeOrder('pago', $marker);
        $this->makeOrder('pago', $marker); // distractor: pago, mas nao enviado
        $this->markShipped($shipped);

        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['enviado']]]);

        $model = new orders_model();
        $model->set_field([' idx ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'filtro "enviado" deve trazer so o pedido com shipped_at preenchido');
        $this->assertSame($shipped, (int)$model->data[0]['idx']);
    }

    public function testPaidFilterExcludesShippedOrders(): void
    {
        // O nucleo do pedido do usuario: "Pago" so lista os pagos que ainda NAO
        // foram enviados — os enviados aparecem como "Enviado", nao como "Pago".
        $marker     = uniqid();
        $notShipped = $this->makeOrder('pago', $marker);
        $shipped    = $this->makeOrder('pago', $marker);
        $this->markShipped($shipped);

        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['pago']]]);

        $model = new orders_model();
        $model->set_field([' idx ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'filtro "Pago" deve excluir os ja enviados');
        $this->assertSame($notShipped, (int)$model->data[0]['idx']);
    }

    public function testPaidAndShippedFilterUnionsNotShippedPaidWithShipped(): void
    {
        $marker         = uniqid();
        $notShippedPago = $this->makeOrder('pago', $marker);
        $shipped        = $this->makeOrder('pago', $marker);
        $this->markShipped($shipped);
        $this->makeOrder('aguardando_pagamento', $marker); // distractor: nem pago-nao-enviado, nem enviado

        [$conds, $params] = $this->buildFilter(['get' => ['status' => ['pago', 'enviado']]]);

        $model = new orders_model();
        $model->set_field([' idx ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'Pago + Enviado deve unir os pagos-nao-enviados e os enviados, excluindo aguardando');
        $ids = array_map(static fn(array $r): int => (int)$r['idx'], $model->data);
        $this->assertContains($notShippedPago, $ids);
        $this->assertContains($shipped, $ids);
    }

    private function markShipped(int $orderId): void
    {
        $update = new orders_model();
        $update->set_filter(["idx = ?"], [$orderId]);
        $update->populate(['shipped_at' => date('Y-m-d H:i:s')]);
        $update->save();
    }

    public function testBuildFilterWithCpfAndTelefoneCombinesBothConditions(): void
    {
        $marker = uniqid();
        $this->makeOrder('pago', $marker, cpf: '12345678909', phone: '11999999999');
        // distractores: mesmo marker, batendo em so UM dos dois criterios cada um —
        // provam que o AND entre customer_cpf e customer_phone e real, nao um OR disfarcado.
        $this->makeOrder('pago', $marker, cpf: '12345678909', phone: '11988887777');
        $this->makeOrder('pago', $marker, cpf: '98765432100', phone: '11999999999');

        [$conds, $params] = $this->buildFilter(['get' => ['cpf' => '12345678909', 'telefone' => '9999']]);

        $this->assertSame([" active = 'yes' ", " customer_cpf = ? ", " customer_phone LIKE ? "], $conds);
        $this->assertSame(['12345678909', '%9999'], $params);

        $model = new orders_model();
        $model->set_field([' idx ', ' customer_cpf ', ' customer_phone ', ' customer_name ']);
        $model->set_filter(
            array_merge($conds, [" customer_name LIKE ? "]),
            array_merge($params, ["%{$marker}%"])
        );
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'cpf e telefone combinados devem restringir ao pedido que bate com ambos, excluindo os que batem so parcialmente');
    }
}
