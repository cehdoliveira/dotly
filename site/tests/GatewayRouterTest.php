<?php

declare(strict_types=1);

/**
 * Cobre GatewayRouter::pick(): so sorteia gateways enabled='yes' COM credencial
 * completa (plano 026), escolhe pelo menor mtd/limite quando todos estouram o
 * headroom, e a distribuicao do sorteio ponderado respeita a proporcao de
 * headroom entre os gateways.
 *
 * Isolamento: GatewayRouter::pick() consulta TODOS os gateways enabled='yes'
 * sem filtro adicional — para nao depender de quais gateways ja estao
 * habilitados no banco (seeds de migrations/007_create_table_payment_gateways.sql, ou estado deixado por outro
 * teste), desabilitamos temporariamente qualquer gateway ja habilitado no
 * setUp e restauramos no tearDown.
 *
 * Fixtures (plano 026): GatewayCredentials::SCHEMA e fechado por slug
 * (mercadopago/pagbank/infinitepay), entao nao da mais pra criar gateways com
 * slug uniqid() como antes do plano 026 — o filtro de credencial completa do
 * Step 6 os excluiria sempre. Os testes usam os 2 slugs reais 'mercadopago' e
 * 'pagbank' como "gateway A"/"gateway B", mutando enabled/monthly_limit_cents/
 * max_order_cents/avoid_on_spike/credentials_enc dessas 2 linhas reais e
 * restaurando o estado ORIGINAL de cada campo no tearDown — mesmo padrao de
 * salvar-e-restaurar que este arquivo ja usava para `enabled`.
 */
final class GatewayRouterTest extends DBTestCase
{
    private const SLUG_A = 'mercadopago';
    private const SLUG_B = 'pagbank';

    /** @var int[] */
    private array $previouslyEnabledIds = [];

    /** @var array<int, array<string,mixed>> idx => snapshot da linha antes do teste */
    private array $originalGatewayState = [];

    /** @var int[] */
    private array $touchedGatewayIdx = [];

    protected function setUp(): void
    {
        parent::setUp();
        GatewayCredentials::resetCache();

        $model = new payment_gateways_model();
        $model->set_field([" idx "]);
        $model->set_filter([" active = 'yes' ", " enabled = 'yes' "]);
        $model->load_data(false);

        $this->previouslyEnabledIds = array_map(static fn(array $row) => (int)$row['idx'], $model->data);

        foreach ($this->previouslyEnabledIds as $idx) {
            $this->setGatewayEnabled($idx, 'no');
        }

        $this->originalGatewayState = [];
        $this->touchedGatewayIdx = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedGatewayIdx as $idx) {
            $orig = $this->originalGatewayState[$idx];

            $update = new payment_gateways_model();
            $update->set_filter(["idx = ?"], [$idx]);
            $update->populate([
                'enabled'             => (string)$orig['enabled'],
                'monthly_limit_cents' => (string)$orig['monthly_limit_cents'],
                'max_order_cents'     => $orig['max_order_cents'],
                'avoid_on_spike'      => (string)$orig['avoid_on_spike'],
            ]);
            $update->save();

            // Restaura o credentials_enc cru (bypassa GatewayCredentials::save()/
            // clear() de proposito — o valor original pode ser NULL, o que
            // save() nao produz).
            $update->execute_raw_prepared(
                "UPDATE payment_gateways SET credentials_enc = ? WHERE idx = ?",
                [$orig['credentials_enc'], $idx]
            );
        }

        foreach ($this->previouslyEnabledIds as $idx) {
            $this->setGatewayEnabled($idx, 'yes');
        }

        // Restaura o default seedado por migrations/011_create_table_settings.sql, independente da ordem
        // de execucao dos testes (mesma conexao/transacao global e compartilhada
        // entre todos os testes do processo — ver padrao em OrderPricingTest).
        $this->setSetting('velocity_paid_orders_per_hour', '0');

        GatewayCredentials::resetCache();
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

    private function gatewayIdxBySlug(string $slug): int
    {
        $model = new payment_gateways_model();
        $model->set_field([" idx "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $idx = (int)($model->data[0]['idx'] ?? 0);
        $this->assertGreaterThan(0, $idx, "gateway seed '$slug' deveria existir (migrations/007)");

        return $idx;
    }

    /** @return array<string,string> valores de credencial validos para o slug (SCHEMA required). */
    private function validCredentialsFor(string $slug): array
    {
        return match ($slug) {
            self::SLUG_A => ['access_token' => 'tok_teste', 'webhook_secret' => 'sec_teste'],
            self::SLUG_B => ['api_base' => 'https://sandbox.api.pagseguro.com', 'token' => 'tok_teste'],
            default => throw new \InvalidArgumentException("sem credencial de teste para slug '$slug'"),
        };
    }

    /**
     * mtd (mes corrente) ja acumulado pelo gateway $gatewayIdx, mesma query de
     * GatewayRouter::pick(). Os 3 gateways reais sao COMPARTILHADOS por toda a
     * suite (models usam localPDO::getInstance(), sem rollback entre testes/
     * arquivos — ver docblock de DBTestCase) — outras suites (webhook, checkout,
     * etc.) ja criaram pix_charges/orders pagos contra esses mesmos idx antes
     * deste teste rodar.
     */
    private function currentMtdCents(int $gatewayIdx): int
    {
        $monthStart = date('Y-m-01 00:00:00');

        $model = new pix_charges_model();
        $stmt = $model->select(
            [" COALESCE(SUM(o.total_cents), 0) AS mtd "],
            "WHERE c.active = 'yes' AND c.payment_gateways_id = ? AND o.status = 'pago' AND o.paid_at >= ?",
            [$gatewayIdx, $monthStart],
            "c",
            "JOIN orders o ON o.idx = c.orders_id"
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? (int)$row['mtd'] : 0;
    }

    /**
     * Configura o gateway real $slug com os parametros de teste e credencial
     * completa (para nao ser excluido pelo filtro do Step 6). Salva o estado
     * ORIGINAL da linha na 1a chamada do teste, para o tearDown restaurar.
     *
     * $desiredHeadroomCents NAO e gravado direto em monthly_limit_cents: o
     * valor gravado e currentMtdCents($idx) + $desiredHeadroomCents, para o
     * headroom REAL (limit - mtd) que GatewayRouter::pick() calcula bater
     * exatamente com o que o teste pede, mesmo com os 3 gateways reais
     * carregando mtd acumulado de outras suites (ver currentMtdCents()). Um
     * pedido pago criado DEPOIS desta chamada (createPaidOrderForGateway) soma
     * ao mtd normalmente e reduz o headroom, como esperado.
     */
    private function useGateway(
        string $slug,
        string $enabled,
        int $desiredHeadroomCents,
        ?int $maxOrderCents = null,
        ?string $avoidOnSpike = null,
        bool $withCredentials = true
    ): int {
        $idx = $this->gatewayIdxBySlug($slug);

        if (!in_array($idx, $this->touchedGatewayIdx, true)) {
            $read = new payment_gateways_model();
            $read->set_field([" idx ", " enabled ", " monthly_limit_cents ", " max_order_cents ", " avoid_on_spike ", " credentials_enc "]);
            $read->set_filter(["idx = ?"], [$idx]);
            $read->set_paginate([1]);
            $read->load_data(false);
            $this->originalGatewayState[$idx] = $read->data[0];
            $this->touchedGatewayIdx[] = $idx;
        }

        $monthlyLimitCents = $this->currentMtdCents($idx) + $desiredHeadroomCents;

        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$idx]);
        $update->populate([
            'enabled'             => $enabled,
            'monthly_limit_cents' => $monthlyLimitCents,
            'max_order_cents'     => $maxOrderCents,
            'avoid_on_spike'      => $avoidOnSpike ?? 'no',
        ]);
        $update->save();

        if ($withCredentials) {
            GatewayCredentials::save($slug, $this->validCredentialsFor($slug));
        } else {
            GatewayCredentials::clear($slug);
        }

        return $idx;
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
        $enabledId = $this->useGateway(self::SLUG_A, 'yes', 100000);
        $this->useGateway(self::SLUG_B, 'no', 100000);

        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick();
            $this->assertSame($enabledId, $picked['idx']);
        }
    }

    public function testAllOutOfHeadroomPicksLowestUtilizationRatio(): void
    {
        // A: limite 10000, ja faturou 10000 -> ratio 1.0 (estourado)
        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 10000);
        $this->createPaidOrderForGateway($gatewayA, 10000);

        // B: limite 10000, ja faturou 9000 -> ratio 0.9 (estourado, mas menos)
        $gatewayB = $this->useGateway(self::SLUG_B, 'yes', 10000);
        $this->createPaidOrderForGateway($gatewayB, 9000);

        $picked = GatewayRouter::pick();

        $this->assertSame($gatewayB, $picked['idx'], 'Deveria escolher o gateway com menor mtd/limite (B)');
    }

    public function testZeroMonthlyLimitCountsAsZeroHeadroom(): void
    {
        // limite 0 conta como headroom 0 — so e escolhido no fallback.
        $gatewayZeroLimit = $this->useGateway(self::SLUG_A, 'yes', 0);
        $gatewayWithRoom  = $this->useGateway(self::SLUG_B, 'yes', 5000);

        $picked = GatewayRouter::pick();

        $this->assertSame($gatewayWithRoom, $picked['idx']);
    }

    public function testWeightedDistributionRespectsHeadroomProportion(): void
    {
        // A: headroom 8000 (80%), B: headroom 2000 (20%). Sem faturamento no mes.
        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 8000);
        $gatewayB = $this->useGateway(self::SLUG_B, 'yes', 2000);

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
        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 100000, 50000);
        $gatewayB = $this->useGateway(self::SLUG_B, 'yes', 100000);

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
        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 100000, 40000);
        $gatewayB = $this->useGateway(self::SLUG_B, 'yes', 100000);

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
        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 100000, 1000);
        $gatewayB = $this->useGateway(self::SLUG_B, 'yes', 100000, 2000);

        $picked = GatewayRouter::pick(50000);

        $this->assertContains($picked['idx'], [$gatewayA, $gatewayB]);
    }

    public function testPickWithoutOrderCentsIgnoresMaxOrderCents(): void
    {
        // Regressao: pick() sem argumento preserva o comportamento antigo — nao
        // filtra por max_order_cents, mesmo quando o gateway tem teto definido.
        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 100000, 1000);

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
        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 100000, 0);
        $gatewayB = $this->useGateway(self::SLUG_B, 'yes', 100000);

        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick(1);
            $this->assertSame($gatewayB, $picked['idx']);
        }
    }

    public function testVelocityThresholdZeroKeepsSpikeSensitiveGatewayEligible(): void
    {
        // Threshold 0 (default seedado por migrations/011_create_table_settings.sql) = detecao desligada:
        // mesmo com pedidos pagos recentes, o gateway avoid_on_spike continua
        // elegivel.
        $this->setSetting('velocity_paid_orders_per_hour', '0');

        $spikeGateway = $this->useGateway(self::SLUG_A, 'yes', 100000, null, 'yes');
        $calmGateway  = $this->useGateway(self::SLUG_B, 'yes', 100000, null, 'no');

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
        // Threshold FIXO (3), sem calibrar pela baseline: os pedidos pagos que
        // ja existem na janela so SOMAM na contagem, entao os 3 pedidos criados
        // aqui bastam para cruzar o threshold sozinhos. Calibrar (baseline + 3)
        // deixava o teste racy: a janela de 60min e deslizante, e pedidos
        // antigos saindo dela entre a leitura da baseline e o pick() derrubavam
        // a contagem abaixo do threshold (o banco de teste tem rajadas de
        // dezenas de pedidos pagos no mesmo segundo).
        $this->setSetting('velocity_paid_orders_per_hour', '3');

        $spikeGateway = $this->useGateway(self::SLUG_A, 'yes', 100000, null, 'yes');
        $calmGateway  = $this->useGateway(self::SLUG_B, 'yes', 100000, null, 'no');

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

        $spikeGateway = $this->useGateway(self::SLUG_A, 'yes', 100000, null, 'yes');
        $calmGateway  = $this->useGateway(self::SLUG_B, 'yes', 100000, null, 'no');

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
        // Threshold fixo pelo mesmo motivo de
        // testSpikeAboveThresholdExcludesAvoidOnSpikeGateway.
        $this->setSetting('velocity_paid_orders_per_hour', '3');

        $gatewayA = $this->useGateway(self::SLUG_A, 'yes', 100000, null, 'yes');
        $gatewayB = $this->useGateway(self::SLUG_B, 'yes', 100000, null, 'yes');

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

        $spikeGateway = $this->useGateway(self::SLUG_A, 'yes', 100000, null, 'yes');
        $calmGateway  = $this->useGateway(self::SLUG_B, 'yes', 100000, null, 'no');

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
            $spikeGateway = $this->useGateway(self::SLUG_A, 'yes', 100000, null, 'yes');
            $calmGateway  = $this->useGateway(self::SLUG_B, 'yes', 100000, null, 'no');

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

    /**
     * Plano 026: gateway enabled='yes' mas SEM credencial completa nao entra
     * no sorteio, mesmo tendo headroom disponivel — GatewayRouter::pick()
     * escolhe sempre o outro gateway (com credencial).
     */
    public function testEnabledGatewayWithoutCredentialsExcludedFromSorteio(): void
    {
        $incompleteGateway = $this->useGateway(self::SLUG_A, 'yes', 100000, null, null, withCredentials: false);
        $completeGateway   = $this->useGateway(self::SLUG_B, 'yes', 100000);

        for ($i = 0; $i < 20; $i++) {
            $picked = GatewayRouter::pick();
            $this->assertSame($completeGateway, $picked['idx'], 'gateway sem credencial nunca deveria ser sorteado');
        }
        // sanidade: o gateway sem credencial de fato nao tem credencial completa.
        $this->assertNotSame($incompleteGateway, $completeGateway);
    }

    /**
     * Plano 026: se o UNICO gateway habilitado nao tem credencial completa,
     * pick() lanca RuntimeException('nenhum gateway habilitado') — mesma
     * mensagem de "nenhum gateway enabled", de proposito (checkout_controller
     * ja trata essa string).
     */
    public function testAllEnabledGatewaysWithoutCredentialsThrows(): void
    {
        $this->useGateway(self::SLUG_A, 'yes', 100000, null, null, withCredentials: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nenhum gateway habilitado');
        GatewayRouter::pick();
    }
}
