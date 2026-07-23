<?php

declare(strict_types=1);

/**
 * Cobre GatewayRouter::pick(): so sorteia gateways enabled='yes', escolhe pelo
 * menor mtd/limite quando todos estouram o headroom, e a distribuicao do
 * sorteio ponderado respeita a proporcao de headroom entre os gateways.
 *
 * Isolamento: GatewayRouter::pick() consulta TODOS os gateways enabled='yes'
 * sem filtro adicional — para nao depender de quais gateways ja estao
 * habilitados no banco (seeds da migration 011, ou estado deixado por outro
 * teste), desabilitamos temporariamente qualquer gateway ja habilitado no
 * setUp e restauramos no tearDown. Os gateways de teste usam slug unico
 * (uniqid()) e sao desativados no tearDown.
 */
final class GatewayRouterTest extends DBTestCase
{
    /** @var int[] */
    private array $previouslyEnabledIds = [];

    /** @var int[] */
    private array $createdGatewayIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $model = new payment_gateways_model();
        $model->set_field([" idx "]);
        $model->set_filter([" active = 'yes' ", " enabled = 'yes' "]);
        $model->load_data(false);

        $this->previouslyEnabledIds = array_map(static fn(array $row) => (int)$row['idx'], $model->data);

        foreach ($this->previouslyEnabledIds as $idx) {
            $this->setGatewayEnabled($idx, 'no');
        }

        $this->createdGatewayIds = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdGatewayIds as $idx) {
            $update = new payment_gateways_model();
            $update->set_filter(["idx = ?"], [$idx]);
            $update->populate(['active' => 'no', 'enabled' => 'no']);
            $update->save();
        }

        foreach ($this->previouslyEnabledIds as $idx) {
            $this->setGatewayEnabled($idx, 'yes');
        }

        // Restaura o default seedado pela migration 046, independente da ordem
        // de execucao dos testes (mesma conexao/transacao global e compartilhada
        // entre todos os testes do processo — ver padrao em OrderPricingTest).
        $this->setSetting('velocity_paid_orders_per_hour', '0');

        parent::tearDown();
    }

    private function setSetting(string $key, string $value): void
    {
        $model = new settings_model();
        $model->execute_raw_prepared(
            "UPDATE settings SET svalue = ? WHERE skey = ?",
            [$value, $key]
        );
    }

    /**
     * Conta pedidos pagos na ultima hora fora do GatewayRouter, para calibrar
     * o threshold dos testes de velocity em cima de uma baseline real — o
     * banco de teste acumula pedidos pagos de outras suites (sem rollback,
     * ver nota no tearDown), entao um numero fixo de threshold seria fragil.
     */
    private function countPaidOrdersLastHour(): int
    {
        $model = new orders_model();
        $stmt = $model->select(
            [" COUNT(*) AS c "],
            "WHERE active = 'yes' AND status = 'pago' AND paid_at >= ?",
            [date('Y-m-d H:i:s', strtotime('-60 minutes'))]
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? (int)$row['c'] : 0;
    }

    private function setGatewayEnabled(int $idx, string $enabled): void
    {
        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$idx]);
        $update->populate(['enabled' => $enabled]);
        $update->save();
    }

    private function createGateway(string $slug, string $enabled, int $monthlyLimitCents, ?int $maxOrderCents = null, ?string $avoidOnSpike = null): int
    {
        $model = new payment_gateways_model();
        $data = [
            'name'                => 'Gateway ' . $slug,
            'slug'                => $slug,
            'mode'                => 'qr',
            'enabled'             => $enabled,
            'monthly_limit_cents' => $monthlyLimitCents,
        ];
        if ($maxOrderCents !== null) {
            $data['max_order_cents'] = $maxOrderCents;
        }
        if ($avoidOnSpike !== null) {
            $data['avoid_on_spike'] = $avoidOnSpike;
        }
        $model->populate($data);
        $id = $model->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $this->createdGatewayIds[] = $id;

        return $id;
    }

    private function createPaidOrderForGateway(int $gatewayId, int $totalCents, ?string $paidAt = null): void
    {
        $paidAt ??= date('Y-m-d H:i:s');

        $order = new orders_model();
        $order->populate([
            'token'           => bin2hex(random_bytes(16)),
            'status'          => 'pago',
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
            'paid_at'         => $paidAt,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = $order->save();
        $this->assertIsInt($orderId);

        $charge = new pix_charges_model();
        $charge->populate([
            'orders_id'           => $orderId,
            'payment_gateways_id' => $gatewayId,
            'gateway_charge_id'   => 'chg_' . uniqid(),
            'status'              => 'pago',
            'amount_cents'        => $totalCents,
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'paid_at'             => $paidAt,
        ]);
        $chargeId = $charge->save();
        $this->assertIsInt($chargeId);
    }

    public function testThrowsWhenNoGatewayEnabled(): void
    {
        $this->expectException(RuntimeException::class);
        GatewayRouter::pick();
    }

    public function testOnlyPicksEnabledGateways(): void
    {
        $enabledId = $this->createGateway('teste-enabled-' . uniqid(), 'yes', 100000);
        $this->createGateway('teste-disabled-' . uniqid(), 'no', 100000);

        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick();
            $this->assertSame($enabledId, $picked['idx']);
        }
    }

    public function testAllOutOfHeadroomPicksLowestUtilizationRatio(): void
    {
        // A: limite 10000, ja faturou 10000 -> ratio 1.0 (estourado)
        $gatewayA = $this->createGateway('teste-estourado-a-' . uniqid(), 'yes', 10000);
        $this->createPaidOrderForGateway($gatewayA, 10000);

        // B: limite 10000, ja faturou 9000 -> ratio 0.9 (estourado, mas menos)
        $gatewayB = $this->createGateway('teste-estourado-b-' . uniqid(), 'yes', 10000);
        $this->createPaidOrderForGateway($gatewayB, 9000);

        $picked = GatewayRouter::pick();

        $this->assertSame($gatewayB, $picked['idx'], 'Deveria escolher o gateway com menor mtd/limite (B)');
    }

    public function testZeroMonthlyLimitCountsAsZeroHeadroom(): void
    {
        // limite 0 conta como headroom 0 — so e escolhido no fallback.
        $gatewayZeroLimit = $this->createGateway('teste-limite-zero-' . uniqid(), 'yes', 0);
        $gatewayWithRoom  = $this->createGateway('teste-com-folga-' . uniqid(), 'yes', 5000);

        $picked = GatewayRouter::pick();

        $this->assertSame($gatewayWithRoom, $picked['idx']);
    }

    public function testWeightedDistributionRespectsHeadroomProportion(): void
    {
        // A: headroom 8000 (80%), B: headroom 2000 (20%). Sem faturamento no mes.
        $gatewayA = $this->createGateway('teste-peso-a-' . uniqid(), 'yes', 8000);
        $gatewayB = $this->createGateway('teste-peso-b-' . uniqid(), 'yes', 2000);

        $counts = [$gatewayA => 0, $gatewayB => 0];
        $iterations = 1000;

        for ($i = 0; $i < $iterations; $i++) {
            $picked = GatewayRouter::pick();
            $this->assertArrayHasKey($picked['idx'], $counts);
            $counts[$picked['idx']]++;
        }

        // Tolerancia generosa (±10pp) para evitar flakiness mantendo o teste
        // sensivel a uma distribuicao claramente errada (ex.: 50/50).
        $this->assertGreaterThan($iterations * 0.70, $counts[$gatewayA], 'Gateway A deveria receber ~80% dos sorteios');
        $this->assertLessThan($iterations * 0.90, $counts[$gatewayA], 'Gateway A nao deveria dominar totalmente os sorteios');
        $this->assertGreaterThan($iterations * 0.10, $counts[$gatewayB], 'Gateway B deveria receber ~20% dos sorteios');
        $this->assertLessThan($iterations * 0.30, $counts[$gatewayB], 'Gateway B nao deveria ser ignorado');
    }

    public function testOrderAboveMaxOrderCentsExcludesGateway(): void
    {
        // A: teto 50000 (abaixo do pedido), B: sem teto. pedido de 60000 -> so B
        // fica elegivel, entao o sorteio (mesmo ponderado) sempre escolhe B.
        $gatewayA = $this->createGateway('teste-teto-a-' . uniqid(), 'yes', 100000, 50000);
        $gatewayB = $this->createGateway('teste-teto-b-' . uniqid(), 'yes', 100000);

        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick(60000);
            $this->assertSame($gatewayB, $picked['idx']);
        }
    }

    public function testOrderAtOrBelowMaxOrderCentsKeepsGatewayEligible(): void
    {
        // A: teto 40000 (== pedido, elegivel por <=), B: sem teto. Ambos com
        // headroom generoso -> ambos devem aparecer no sorteio ao longo de N
        // tentativas.
        $gatewayA = $this->createGateway('teste-teto-ok-a-' . uniqid(), 'yes', 100000, 40000);
        $gatewayB = $this->createGateway('teste-teto-ok-b-' . uniqid(), 'yes', 100000);

        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $picked = GatewayRouter::pick(40000);
            $seen[$picked['idx']] = true;
        }

        $this->assertArrayHasKey($gatewayA, $seen, 'Gateway A (teto == pedido) deveria ser elegivel');
        $this->assertArrayHasKey($gatewayB, $seen, 'Gateway B (sem teto) deveria ser elegivel');
    }

    public function testAllGatewaysBelowOrderCentsIgnoresTetoAndStillPicksOne(): void
    {
        // A e B com teto abaixo do pedido -> filtro esvaziaria o conjunto; teto e
        // ignorado (nunca bloqueia a venda) e o sorteio segue normalmente.
        $gatewayA = $this->createGateway('teste-teto-est-a-' . uniqid(), 'yes', 100000, 1000);
        $gatewayB = $this->createGateway('teste-teto-est-b-' . uniqid(), 'yes', 100000, 2000);

        $picked = GatewayRouter::pick(50000);

        $this->assertContains($picked['idx'], [$gatewayA, $gatewayB]);
    }

    public function testPickWithoutOrderCentsIgnoresMaxOrderCents(): void
    {
        // Regressao: pick() sem argumento preserva o comportamento antigo — nao
        // filtra por max_order_cents, mesmo quando o gateway tem teto definido.
        $gatewayA = $this->createGateway('teste-sem-arg-' . uniqid(), 'yes', 100000, 1000);

        $picked = GatewayRouter::pick();

        $this->assertSame($gatewayA, $picked['idx']);
    }

    /**
     * Achado do /ship (especialista de testing): max_order_cents=0 (persistido
     * quando o admin digita algo sem numeros, ver GatewaysActionTest no manager)
     * exclui o gateway de QUALQUER pedido real, ja que $orderCents <= 0 nunca e
     * verdadeiro para um total > 0. Documenta o comportamento — 0 e um bloqueio
     * de fato, nao "ilimitado" — para o sorteio.
     */
    public function testMaxOrderCentsZeroExcludesGatewayFromAnyRealOrder(): void
    {
        $gatewayA = $this->createGateway('teste-teto-zero-a-' . uniqid(), 'yes', 100000, 0);
        $gatewayB = $this->createGateway('teste-teto-zero-b-' . uniqid(), 'yes', 100000);

        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick(1);
            $this->assertSame($gatewayB, $picked['idx']);
        }
    }

    public function testVelocityThresholdZeroKeepsSpikeSensitiveGatewayEligible(): void
    {
        // Threshold 0 (default seedado pela migration 046) = detecao desligada:
        // mesmo com pedidos pagos recentes, o gateway avoid_on_spike continua
        // elegivel.
        $this->setSetting('velocity_paid_orders_per_hour', '0');

        $spikeGateway = $this->createGateway('tv0-spike-' . uniqid(), 'yes', 100000, null, 'yes');
        $calmGateway  = $this->createGateway('tv0-calm-' . uniqid(), 'yes', 100000, null, 'no');

        for ($i = 0; $i < 3; $i++) {
            $this->createPaidOrderForGateway($calmGateway, 1000);
        }

        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick();
            $seen[$picked['idx']] = true;
        }

        $this->assertArrayHasKey($spikeGateway, $seen, 'Com threshold 0, avoid_on_spike nao deveria filtrar nada');
    }

    public function testSpikeAboveThresholdExcludesAvoidOnSpikeGateway(): void
    {
        // Calibra o threshold em cima da baseline real (banco de teste
        // acumula pedidos pagos de outras suites, sem rollback) para nao
        // depender de um numero fixo de pedidos existentes na janela.
        $baseline = $this->countPaidOrdersLastHour();
        $threshold = $baseline + 3;
        $this->setSetting('velocity_paid_orders_per_hour', (string)$threshold);

        $spikeGateway = $this->createGateway('tv1-spike-' . uniqid(), 'yes', 100000, null, 'yes');
        $calmGateway  = $this->createGateway('tv1-calm-' . uniqid(), 'yes', 100000, null, 'no');

        for ($i = 0; $i < 3; $i++) {
            $this->createPaidOrderForGateway($calmGateway, 1000);
        }

        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick();
            $this->assertSame($calmGateway, $picked['idx'], 'Pico deveria desviar o sorteio do gateway avoid_on_spike');
        }
    }

    public function testOldPaidOrdersOutsideWindowDoNotCountTowardsSpike(): void
    {
        // Pedidos pagos ha mais de 60 minutos nao contam para a janela —
        // threshold calibrado acima da baseline recente, entao pedidos
        // antigos nao deveriam ser suficientes para desviar o sorteio.
        $baseline = $this->countPaidOrdersLastHour();
        $threshold = $baseline + 3;
        $this->setSetting('velocity_paid_orders_per_hour', (string)$threshold);

        $spikeGateway = $this->createGateway('tv2-spike-' . uniqid(), 'yes', 100000, null, 'yes');
        $calmGateway  = $this->createGateway('tv2-calm-' . uniqid(), 'yes', 100000, null, 'no');

        $oldPaidAt = date('Y-m-d H:i:s', strtotime('-90 minutes'));
        for ($i = 0; $i < 5; $i++) {
            $this->createPaidOrderForGateway($calmGateway, 1000, $oldPaidAt);
        }

        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick();
            $seen[$picked['idx']] = true;
        }

        $this->assertArrayHasKey($spikeGateway, $seen, 'Pedidos pagos fora da janela de 60min nao deveriam disparar o desvio');
    }

    public function testAllGatewaysAvoidOnSpikeStillPicksOneDuringSpike(): void
    {
        // Todos os gateways marcados avoid_on_spike + pico detectado: o filtro
        // esvaziaria o conjunto, entao e ignorado — pick() nunca lanca.
        $baseline = $this->countPaidOrdersLastHour();
        $threshold = $baseline + 3;
        $this->setSetting('velocity_paid_orders_per_hour', (string)$threshold);

        $gatewayA = $this->createGateway('tv3-a-' . uniqid(), 'yes', 100000, null, 'yes');
        $gatewayB = $this->createGateway('tv3-b-' . uniqid(), 'yes', 100000, null, 'yes');

        for ($i = 0; $i < 3; $i++) {
            $this->createPaidOrderForGateway($gatewayA, 1000);
        }

        $picked = GatewayRouter::pick();

        $this->assertContains($picked['idx'], [$gatewayA, $gatewayB]);
    }

    public function testInvalidVelocitySettingTreatedAsDisabled(): void
    {
        // svalue nao numerico ('abc') e tratado como 0 (detecao desligada),
        // sem excecao — mesmo padrao de OrderPricing::intSetting().
        $this->setSetting('velocity_paid_orders_per_hour', 'abc');

        $spikeGateway = $this->createGateway('tv4-spike-' . uniqid(), 'yes', 100000, null, 'yes');
        $calmGateway  = $this->createGateway('tv4-calm-' . uniqid(), 'yes', 100000, null, 'no');

        for ($i = 0; $i < 3; $i++) {
            $this->createPaidOrderForGateway($calmGateway, 1000);
        }

        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick();
            $seen[$picked['idx']] = true;
        }

        $this->assertArrayHasKey($spikeGateway, $seen, 'svalue invalido deveria ser tratado como threshold 0 (desligado)');
    }

    public function testMissingVelocitySettingRowTreatedAsDisabled(): void
    {
        // Sem row ativa em settings (soft-deletada) => velocityThreshold() nao
        // encontra o skey e cai no default seguro (0 = detecao desligada), sem
        // excecao. Restaura active='yes' no finally para nao vazar estado para
        // outros testes (settings nao tem rollback de transacao, ver setSetting()).
        $model = new settings_model();
        $model->execute_raw_prepared(
            "UPDATE settings SET active = 'no' WHERE skey = ?",
            ['velocity_paid_orders_per_hour']
        );

        try {
            $spikeGateway = $this->createGateway('tv5-spike-' . uniqid(), 'yes', 100000, null, 'yes');
            $calmGateway  = $this->createGateway('tv5-calm-' . uniqid(), 'yes', 100000, null, 'no');

            for ($i = 0; $i < 3; $i++) {
                $this->createPaidOrderForGateway($calmGateway, 1000);
            }

            $seen = [];
            for ($i = 0; $i < 20; $i++) {
                $picked = GatewayRouter::pick();
                $seen[$picked['idx']] = true;
            }

            $this->assertArrayHasKey($spikeGateway, $seen, 'Sem row de settings, deveria cair no default (desligado)');
        } finally {
            $model->execute_raw_prepared(
                "UPDATE settings SET active = 'yes' WHERE skey = ?",
                ['velocity_paid_orders_per_hour']
            );
        }
    }
}
