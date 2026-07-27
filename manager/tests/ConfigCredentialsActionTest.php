<?php

declare(strict_types=1);

/**
 * Cobre a escrita de credenciais feita por config_controller::saveCredentials()
 * (action=credenciais) e o guard de config_controller::saveGateway() que recusa
 * enabled='yes' sem credencial completa (plano 026). O controller nao e chamado
 * diretamente (os handlers terminam em basic_redir(), que faz exit() e mataria o
 * processo do PHPUnit — mesmo motivo documentado em GatewaysActionTest). Em vez
 * disso, reproduz-se aqui exatamente a mesma montagem de $values a partir do
 * $_POST que saveCredentials() monta, e o mesmo teste booleano do guard de
 * saveGateway().
 *
 * Usa o slug real 'infinitepay' (schema de 1 campo, 'handle') — o SCHEMA e
 * fechado por slug, entao nao da pra usar slug de fixture uniqid() como o
 * resto da suite. Salva e restaura o credentials_enc original no
 * setUp()/tearDown(), mesmo padrao de GatewayCredentialsTest.
 */
final class ConfigCredentialsActionTest extends DBTestCase
{
    private const SLUG = 'infinitepay';

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

        GatewayCredentials::clear(self::SLUG);
    }

    protected function tearDown(): void
    {
        $model = new payment_gateways_model();
        $model->execute_raw_prepared(
            "UPDATE payment_gateways SET credentials_enc = ? WHERE slug = ?",
            [$this->originalCredentialsEnc, self::SLUG]
        );

        GatewayCredentials::resetCache();
        parent::tearDown();
    }

    /**
     * Reproduz exatamente a montagem de $values que
     * config_controller::saveCredentials() faz a partir do $_POST: itera
     * SCHEMA[$slug] (nunca as chaves do POST direto) e confia em cred_<campo>.
     *
     * @param array<string,string> $post
     * @return array<string,string>
     */
    private function buildCredentialValues(string $slug, array $post): array
    {
        $values = [];
        foreach (GatewayCredentials::SCHEMA[$slug] as $field => $def) {
            $values[$field] = trim((string)($post['cred_' . $field] ?? ''));
        }
        return $values;
    }

    public function testOnlySchemaKeysAreWritableEvenIfForgedInPost(): void
    {
        $forgedPost = [
            'action'          => 'credenciais',
            'slug'            => self::SLUG,
            'cred_handle'     => 'meu_handle_real',
            'cred_slug'       => 'forjado-pelo-post',
            'cred_enabled'    => 'yes',
        ];

        $values = $this->buildCredentialValues(self::SLUG, $forgedPost);

        $this->assertSame(['handle' => 'meu_handle_real'], $values, 'so o campo do SCHEMA (handle) pode aparecer em $values');

        GatewayCredentials::save(self::SLUG, $values);

        $this->assertSame(['handle' => 'meu_handle_real'], GatewayCredentials::get(self::SLUG));
    }

    public function testEmptyFieldInPostPreservesCurrentValue(): void
    {
        GatewayCredentials::save(self::SLUG, ['handle' => 'handle_original']);

        $values = $this->buildCredentialValues(self::SLUG, ['cred_handle' => '']);
        $this->assertSame(['handle' => ''], $values, 'buildCredentialValues nao decide o merge — quem preserva e GatewayCredentials::save()');

        GatewayCredentials::save(self::SLUG, $values);

        $this->assertSame(['handle' => 'handle_original'], GatewayCredentials::get(self::SLUG), 'campo vazio no POST deve manter o valor ja cadastrado');
    }

    /**
     * Reproduz o guard de config_controller::saveGateway() (Step 7e do plano 026):
     * `$enabled === 'yes' && !GatewayCredentials::isComplete($slug)` — sem
     * credencial completa, o toggle de habilitar deve ser recusado.
     */
    public function testSaveGatewayGuardRejectsEnabledWithoutCompleteCredentials(): void
    {
        $this->assertFalse(GatewayCredentials::isComplete(self::SLUG), 'fixture comeca sem credencial (limpa no setUp)');

        $enabled = 'yes';
        $guardRejects = ($enabled === 'yes' && !GatewayCredentials::isComplete(self::SLUG));

        $this->assertTrue($guardRejects, 'guard deveria recusar enabled=yes sem credencial completa');
    }

    public function testSaveGatewayGuardAllowsEnabledWithCompleteCredentials(): void
    {
        GatewayCredentials::save(self::SLUG, ['handle' => 'meu_handle']);
        $this->assertTrue(GatewayCredentials::isComplete(self::SLUG));

        $enabled = 'yes';
        $guardRejects = ($enabled === 'yes' && !GatewayCredentials::isComplete(self::SLUG));

        $this->assertFalse($guardRejects, 'guard nao deveria recusar enabled=yes com credencial completa');
    }
}
