<?php

declare(strict_types=1);

/**
 * Cobre config_controller::violatesUnlimitedInvariant() (plano 042): entre os
 * gateways HABILITADOS, pelo menos 1 precisa ficar sem teto por pedido
 * (max_order_cents NULL) apos o save. O metodo simula o estado RESULTANTE do
 * save (aplica enabled/maxOrderCents pendentes ao gateway $idx antes de
 * varrer todos os gateways active='yes') — nao e testavel via saveGateway()
 * diretamente (termina em basic_redir() -> exit(), mesmo motivo documentado
 * em CustomerBlockWriteTest), por isso o metodo foi extraido e e chamado via
 * ReflectionMethod.
 *
 * Isolamento: violatesUnlimitedInvariant() varre TODOS os gateways
 * active='yes' sem filtro adicional — para nao depender de quais gateways ja
 * estao habilitados no banco (seeds da migration 011), desabilitamos
 * temporariamente qualquer gateway ja habilitado no setUp e restauramos no
 * tearDown, mesmo padrao de GatewayRouterTest (site). Os gateways de teste
 * usam slug unico (uniqid()) e sao desativados no tearDown.
 */
final class GatewayLimitInvariantTest extends DBTestCase
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

        parent::tearDown();
    }

    private function setGatewayEnabled(int $idx, string $enabled): void
    {
        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$idx]);
        $update->populate(['enabled' => $enabled]);
        $update->save();
    }

    private function createGateway(string $slug, string $enabled, ?int $maxOrderCents): int
    {
        $model = new payment_gateways_model();
        $data = [
            'name'                => 'Gateway ' . $slug,
            'slug'                => $slug,
            'mode'                => 'qr',
            'enabled'             => $enabled,
            'monthly_limit_cents' => 100000,
        ];
        if ($maxOrderCents !== null) {
            $data['max_order_cents'] = $maxOrderCents;
        }
        $model->populate($data);
        $id = (int) $model->save();
        $this->assertGreaterThan(0, $id);

        $this->createdGatewayIds[] = $id;

        return $id;
    }

    private function violatesUnlimitedInvariant(int $idx, string $enabled, ?int $maxOrderCents): bool
    {
        $controller = new config_controller();
        $method     = new ReflectionMethod($controller, 'violatesUnlimitedInvariant');
        $method->setAccessible(true);

        return $method->invoke($controller, $idx, $enabled, $maxOrderCents);
    }

    public function testGivingLastUnlimitedGatewayATetoIsSafeWhenAnotherStaysUnlimited(): void
    {
        // 2 habilitados, ambos sem teto; dar teto a 1 -> sobra 1 ilimitado (B).
        $gatewayA = $this->createGateway('teste-inv-a-' . uniqid(), 'yes', null);
        $this->createGateway('teste-inv-b-' . uniqid(), 'yes', null);

        $this->assertFalse($this->violatesUnlimitedInvariant($gatewayA, 'yes', 5000));
    }

    public function testGivingLastUnlimitedGatewayATetoViolatesWhenOthersAlreadyHaveTeto(): void
    {
        // 2 habilitados, um JA com teto; dar teto ao outro -> nenhum ilimitado sobra.
        $gatewayA = $this->createGateway('teste-inv-c-' . uniqid(), 'yes', null);
        $this->createGateway('teste-inv-d-' . uniqid(), 'yes', 3000);

        $this->assertTrue($this->violatesUnlimitedInvariant($gatewayA, 'yes', 5000));
    }

    public function testDisablingTheOnlyUnlimitedGatewayViolatesInvariant(): void
    {
        // 3 habilitados, 2 com teto; desabilitar o unico sem teto -> violacao via 'enabled'.
        $gatewayA = $this->createGateway('teste-inv-e-' . uniqid(), 'yes', null);
        $this->createGateway('teste-inv-f-' . uniqid(), 'yes', 1000);
        $this->createGateway('teste-inv-g-' . uniqid(), 'yes', 2000);

        $this->assertTrue($this->violatesUnlimitedInvariant($gatewayA, 'no', null));
    }

    public function testGivingTetoToTheOnlyEnabledGatewayViolatesInvariant(): void
    {
        // Unico habilitado, sem teto; dar teto a ele -> nenhum habilitado fica sem teto.
        $gatewayA = $this->createGateway('teste-inv-h-' . uniqid(), 'yes', null);

        $this->assertTrue($this->violatesUnlimitedInvariant($gatewayA, 'yes', 5000));
    }

    public function testDisablingTheOnlyEnabledGatewayDoesNotViolateInvariant(): void
    {
        // Desabilitar o unico gateway habilitado -> sem habilitados, nada a violar
        // (comportamento pre-existente preservado).
        $gatewayA = $this->createGateway('teste-inv-i-' . uniqid(), 'yes', null);

        $this->assertFalse($this->violatesUnlimitedInvariant($gatewayA, 'no', null));
    }

    public function testEditingGatewayBackToUnlimitedNeverViolatesInvariant(): void
    {
        // Gateway com teto volta a ilimitado (null) -> sempre seguro.
        $gatewayA = $this->createGateway('teste-inv-j-' . uniqid(), 'yes', 5000);
        $this->createGateway('teste-inv-k-' . uniqid(), 'yes', 3000);

        $this->assertFalse($this->violatesUnlimitedInvariant($gatewayA, 'yes', null));
    }
}
