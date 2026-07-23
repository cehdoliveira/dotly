<?php

declare(strict_types=1);

/**
 * Cobre a escrita de payment_gateways feita por config_controller::saveGateway() (antes
 * gateways_controller::action(), absorvido pela tela de Configuracoes). O controller nao
 * e chamado diretamente (o handler termina em basic_redir(), que faz exit() e mataria o
 * processo do PHPUnit — mesmo motivo pelo qual nenhum outro teste do repo invoca
 * *_controller::action()). Em vez disso, reproduz-se aqui exatamente o mesmo populate()
 * que o controller monta — provando que so `enabled` e `monthly_limit_cents` sao
 * graváveis, mesmo que `slug`/`mode` venham forjados no POST.
 */
final class GatewaysActionTest extends DBTestCase
{
    /** @var int[] */
    private array $createdGatewayIds = [];

    /**
     * Hard-delete das fixtures criadas — o rollback de `localPDO::__destruct()` nao
     * e confiavel neste ambiente (phpunit por processo via docker), entao sem limpeza
     * explicita cada run vazava linhas 'Gateway Teste' na base (ver mesma nota em
     * SalesDashboardTest). `remove()` (soft-delete) so marcaria active='no' e as linhas
     * seguiriam acumulando; DELETE segue o precedente de MigrationRunnerTest para
     * artefatos que sao exclusivamente de teste.
     */
    protected function tearDown(): void
    {
        if ($this->createdGatewayIds !== []) {
            $model = new payment_gateways_model();
            $placeholders = implode(',', array_fill(0, count($this->createdGatewayIds), '?'));
            $model->execute_raw_prepared(
                "DELETE FROM payment_gateways WHERE idx IN ($placeholders)",
                $this->createdGatewayIds
            );
        }

        parent::tearDown();
    }

    private function makeGateway(string $slug, string $mode): int
    {
        $insert = new payment_gateways_model();
        $insert->populate([
            'name'                => 'Gateway Teste ' . uniqid(),
            'slug'                => $slug,
            'mode'                => $mode,
            'enabled'             => 'no',
            'monthly_limit_cents' => 0,
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');
        $this->createdGatewayIds[] = $id;

        return $id;
    }

    /**
     * Mesma construcao de $data que config_controller::saveGateway() monta a partir do
     * $_POST — nunca inclui as chaves 'slug' nem 'mode'. Inclui max_order_cents (plano 042):
     * input vazio = NULL (sem teto), senao normalizado com o mesmo preg_replace do
     * monthly_limit_cents.
     */
    private function buildUpdateData(array $post): array
    {
        $maxOrderRaw = trim((string) ($post['max_order_cents'] ?? ''));

        return [
            'enabled'             => (($post['enabled'] ?? 'no') === 'yes') ? 'yes' : 'no',
            'monthly_limit_cents' => (int) preg_replace('/\D/', '', (string) ($post['monthly_limit_cents'] ?? '')),
            'max_order_cents'     => $maxOrderRaw === '' ? null : (int) preg_replace('/\D/', '', $maxOrderRaw),
        ];
    }

    public function testEnabledAndMonthlyLimitCentsAreWritable(): void
    {
        $id = $this->makeGateway('gw_' . uniqid(), 'qr');

        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate($this->buildUpdateData([
            'enabled'             => 'yes',
            'monthly_limit_cents' => 'R$ 5.000,00',
        ]));
        $update->save();

        $reload = new payment_gateways_model();
        $reload->set_field([' idx ', ' enabled ', ' monthly_limit_cents ']);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame('yes', $reload->data[0]['enabled']);
        $this->assertSame(500000, (int) $reload->data[0]['monthly_limit_cents']);
    }

    public function testSlugAndModeNeverWrittenEvenIfForgedInPost(): void
    {
        $originalSlug = 'gw_' . uniqid();
        $originalMode = 'qr';
        $id = $this->makeGateway($originalSlug, $originalMode);

        $forgedPost = [
            'action'              => 'editar',
            'idx'                 => (string) $id,
            'enabled'             => 'yes',
            'monthly_limit_cents' => '1.000,00',
            'slug'                => 'forjado-pelo-post',
            'mode'                => 'redirect',
        ];

        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate($this->buildUpdateData($forgedPost));
        $update->save();

        $reload = new payment_gateways_model();
        $reload->set_field([' idx ', ' slug ', ' mode ', ' enabled ', ' monthly_limit_cents ']);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame('yes', $reload->data[0]['enabled']);
        $this->assertSame(100000, (int) $reload->data[0]['monthly_limit_cents']);
        $this->assertSame($originalSlug, $reload->data[0]['slug'], 'slug forjado no POST nao pode sobrescrever o valor original');
        $this->assertSame($originalMode, $reload->data[0]['mode'], 'mode forjado no POST nao pode sobrescrever o valor original');
    }

    /**
     * Plano 042: input vazio de max_order_cents deve persistir como NULL (sem teto),
     * nao como 0 ou string vazia — e o reload confirma o valor que realmente foi
     * gravado no banco (nao so o retorno do metodo puro).
     */
    public function testMaxOrderCentsEmptyInputPersistsAsNull(): void
    {
        $id = $this->makeGateway('gw_' . uniqid(), 'qr');

        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate($this->buildUpdateData([
            'enabled'         => 'yes',
            'max_order_cents' => '',
        ]));
        $update->save();

        $reload = new payment_gateways_model();
        $reload->set_field([' idx ', ' max_order_cents ']);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertNull($reload->data[0]['max_order_cents']);
    }

    /**
     * Plano 042: input formatado em reais e normalizado e persistido em centavos,
     * mesma normalizacao do monthly_limit_cents.
     */
    public function testMaxOrderCentsNumericInputPersistsAsCents(): void
    {
        $id = $this->makeGateway('gw_' . uniqid(), 'qr');

        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate($this->buildUpdateData([
            'enabled'         => 'yes',
            'max_order_cents' => 'R$ 2.500,00',
        ]));
        $update->save();

        $reload = new payment_gateways_model();
        $reload->set_field([' idx ', ' max_order_cents ']);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame(250000, (int) $reload->data[0]['max_order_cents']);
    }

    /**
     * Achado do /ship (especialista de testing): input sem digitos ("abc") nao e
     * rejeitado — preg_replace('/\D/','','abc') === '' e (int)'' === 0. Diferente
     * de monthly_limit_cents (onde 0 so degenera o calculo de headroom),
     * max_order_cents=0 vira um bloqueio de fato: nenhum pedido real (>0) passa no
     * filtro `$orderCents <= 0`. Documenta o comportamento atual (aceito por
     * decisao do dono via /ship, nao um bug a corrigir aqui) para que uma mudanca
     * futura na normalizacao quebre este teste em vez de passar silenciosamente.
     */
    public function testMaxOrderCentsNonNumericInputPersistsAsZeroHardBlock(): void
    {
        $id = $this->makeGateway('gw_' . uniqid(), 'qr');

        $update = new payment_gateways_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate($this->buildUpdateData([
            'enabled'         => 'yes',
            'max_order_cents' => 'abc',
        ]));
        $update->save();

        $reload = new payment_gateways_model();
        $reload->set_field([' idx ', ' max_order_cents ']);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame(0, (int) $reload->data[0]['max_order_cents'], 'input sem digitos deve persistir como 0 (bloqueio de fato), nao null (ilimitado)');
    }
}
