<?php

declare(strict_types=1);

/**
 * Cobre GatewayCredentials::get()/save()/clear()/isComplete()/masked() (plano 026)
 * contra o banco real.
 *
 * O SCHEMA e fechado por slug (mercadopago/pagbank/infinitepay) — nao da pra usar
 * um slug de fixture uniqid() como o resto da suite faz (GatewaysActionTest,
 * GatewayRouterTest). Em vez disso, os testes usam o slug real 'pagbank' e
 * salvam/restauram o credentials_enc ORIGINAL da linha no setUp()/tearDown(),
 * mesmo padrao de salvar-e-restaurar de GatewayRouterTest::setUp()/tearDown()
 * para o campo `enabled`.
 */
final class GatewayCredentialsTest extends DBTestCase
{
    private const SLUG = 'pagbank';

    private ?string $originalCredentialsEnc = null;

    protected function setUp(): void
    {
        parent::setUp();
        GatewayCredentials::resetCache();

        $model = new payment_gateways_model();
        $model->set_field([" credentials_enc "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [self::SLUG]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $this->originalCredentialsEnc = $model->data[0]['credentials_enc'] ?? null;

        // Estado limpo para cada teste, independente do que sobrou de outra suite
        // (models usam localPDO::getInstance(), fora da transacao de rollback do
        // DBTestCase — ver docblock de DBTestCase).
        GatewayCredentials::clear(self::SLUG);
    }

    protected function tearDown(): void
    {
        $model = new payment_gateways_model();
        $model->set_filter(["slug = ?"], [self::SLUG]);
        $model->execute_raw_prepared(
            "UPDATE payment_gateways SET credentials_enc = ? WHERE slug = ?",
            [$this->originalCredentialsEnc, self::SLUG]
        );

        GatewayCredentials::resetCache();
        parent::tearDown();
    }

    private function rawCredentialsEnc(): ?string
    {
        $model = new payment_gateways_model();
        $model->set_field([" credentials_enc "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [self::SLUG]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0]['credentials_enc'] ?? null;
    }

    public function testGetWithoutCredentialsReturnsEmptyArray(): void
    {
        $this->assertSame([], GatewayCredentials::get(self::SLUG));
    }

    public function testSaveThenGetRoundTrip(): void
    {
        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => 'tok_abc123',
        ]);

        $this->assertSame([
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => 'tok_abc123',
        ], GatewayCredentials::get(self::SLUG));
    }

    public function testSaveWithEmptyFieldPreservesCurrentValue(): void
    {
        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => 'tok_original',
        ]);

        GatewayCredentials::save(self::SLUG, [
            'api_base' => '',
            'token'    => 'tok_novo',
        ]);

        $this->assertSame([
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => 'tok_novo',
        ], GatewayCredentials::get(self::SLUG));
    }

    public function testSaveIgnoresKeyNotInSchema(): void
    {
        GatewayCredentials::save(self::SLUG, [
            'api_base'        => 'https://sandbox.api.pagseguro.com',
            'token'           => 'tok_abc123',
            'campo_forjado'   => 'nao deveria ser salvo',
        ]);

        $this->assertArrayNotHasKey('campo_forjado', GatewayCredentials::get(self::SLUG));
    }

    public function testIsCompleteFalseWithMissingRequiredField(): void
    {
        GatewayCredentials::save(self::SLUG, ['api_base' => 'https://sandbox.api.pagseguro.com']);

        $this->assertFalse(GatewayCredentials::isComplete(self::SLUG));
    }

    public function testIsCompleteTrueWithAllRequiredFields(): void
    {
        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => 'tok_abc123',
        ]);

        $this->assertTrue(GatewayCredentials::isComplete(self::SLUG));
    }

    public function testMaskedSecretFieldNeverContainsClearTextValue(): void
    {
        $secretValue = 'tok_super_secreto_1234567890';

        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => $secretValue,
        ]);

        $masked = GatewayCredentials::masked(self::SLUG);

        $this->assertStringNotContainsString($secretValue, implode('', $masked));
        $this->assertStringEndsWith(mb_substr($secretValue, -4), $masked['token']);
    }

    public function testMaskedNonSecretFieldReturnsClearTextValue(): void
    {
        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => 'tok_abc123',
        ]);

        $masked = GatewayCredentials::masked(self::SLUG);

        $this->assertSame('https://sandbox.api.pagseguro.com', $masked['api_base']);
    }

    public function testClearErasesCredentialsAndIsCompleteBecomesFalse(): void
    {
        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => 'tok_abc123',
        ]);
        $this->assertTrue(GatewayCredentials::isComplete(self::SLUG));

        GatewayCredentials::clear(self::SLUG);

        $this->assertSame([], GatewayCredentials::get(self::SLUG));
        $this->assertFalse(GatewayCredentials::isComplete(self::SLUG));
    }

    public function testCredentialsEncColumnNeverContainsClearTextValue(): void
    {
        $secretValue = 'tok_valor_em_claro_nao_pode_vazar';

        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => $secretValue,
        ]);

        $raw = $this->rawCredentialsEnc();

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($secretValue, $raw);
    }

    public function testUnknownSlugSaveThrows(): void
    {
        $this->expectException(RuntimeException::class);
        GatewayCredentials::save('gateway-desconhecido-' . uniqid(), ['x' => 'y']);
    }

    public function testUnknownSlugClearThrows(): void
    {
        $this->expectException(RuntimeException::class);
        GatewayCredentials::clear('gateway-desconhecido-' . uniqid());
    }

    public function testUnknownSlugIsCompleteReturnsFalse(): void
    {
        $this->assertFalse(GatewayCredentials::isComplete('gateway-desconhecido-' . uniqid()));
    }

    public function testUnknownSlugGetReturnsEmptyArray(): void
    {
        $this->assertSame([], GatewayCredentials::get('gateway-desconhecido-' . uniqid()));
    }

    /**
     * Achado da revisao do plano 026: json_encode(..., JSON_THROW_ON_ERROR) em
     * save() lanca \JsonException (NAO RuntimeException) quando algum campo tem
     * bytes UTF-8 invalidos — confirma por que config_controller::saveCredentials()
     * precisa capturar os dois tipos, nao so RuntimeException.
     */
    public function testSaveWithInvalidUtf8ThrowsJsonException(): void
    {
        $invalidUtf8 = "tok_\xB1\xB2invalido";

        $this->expectException(\JsonException::class);
        GatewayCredentials::save(self::SLUG, [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => $invalidUtf8,
        ]);
    }

    /**
     * preload() (achado de performance da revisao do plano 026): carrega varios
     * slugs numa unica query e popula o cache estatico, para GatewayRouter::pick()
     * e config_controller::index() pararem de disparar 1 SELECT por gateway.
     */
    public function testPreloadPopulatesCacheForMultipleSlugs(): void
    {
        $mpModel = new payment_gateways_model();
        $mpModel->set_field([" credentials_enc "]);
        $mpModel->set_filter([" active = 'yes' ", " slug = ? "], ['mercadopago']);
        $mpModel->set_paginate([1]);
        $mpModel->load_data(false);
        $originalMpCredentialsEnc = $mpModel->data[0]['credentials_enc'] ?? null;

        try {
            GatewayCredentials::save(self::SLUG, [
                'api_base' => 'https://sandbox.api.pagseguro.com',
                'token'    => 'tok_pagbank_preload',
            ]);
            GatewayCredentials::save('mercadopago', [
                'access_token'   => 'tok_mp_preload',
                'webhook_secret' => 'sec_mp_preload',
            ]);
            GatewayCredentials::resetCache();

            GatewayCredentials::preload([self::SLUG, 'mercadopago']);

            $this->assertSame(
                ['api_base' => 'https://sandbox.api.pagseguro.com', 'token' => 'tok_pagbank_preload'],
                GatewayCredentials::get(self::SLUG),
                'preload deveria ter populado o cache do pagbank'
            );
            $this->assertSame(
                ['access_token' => 'tok_mp_preload', 'webhook_secret' => 'sec_mp_preload'],
                GatewayCredentials::get('mercadopago')
            );
        } finally {
            $restore = new payment_gateways_model();
            $restore->execute_raw_prepared(
                "UPDATE payment_gateways SET credentials_enc = ? WHERE slug = ?",
                [$originalMpCredentialsEnc, 'mercadopago']
            );
            GatewayCredentials::resetCache();
        }
    }

    /** preload() de slug sem linha na tabela (ou sem credencial) deixa o cache como []. */
    public function testPreloadCachesEmptyArrayForSlugWithoutCredentials(): void
    {
        GatewayCredentials::preload([self::SLUG]);

        $this->assertSame([], GatewayCredentials::get(self::SLUG), 'setUp() ja limpa a credencial do pagbank; preload nao deveria inventar dado');
    }
}
