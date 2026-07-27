<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre PagBankGateway::verifyWebhook()/extractChargeId()/extractPaidAmountCents().
 * Estende TestCase puro (nao DBTestCase, nao precisa de $this->con) mas
 * verifyWebhook() agora le a credencial via GatewayCredentials::get() (plano
 * 026), que consulta a linha real 'pagbank' em payment_gateways — entao os
 * testes que dependem de uma assinatura VALIDA tocam o banco atraves do
 * setUp()/tearDown() abaixo.
 *
 * Credencial de teste fixa semeada no setUp() via GatewayCredentials::save()
 * e restaurada no tearDown() — nunca lida de kernel.php (plano 026 tirou as
 * credenciais de gateway de la). Antes do plano 026 estes testes skipavam
 * quando PAGBANK_TOKEN nao estava configurado (mesmo padrao ja usado em
 * WebhookIdempotencyTest::testValidPagBankSignaturePassesAuthCheck()); agora
 * sempre rodam.
 */
final class PagBankGatewayTest extends TestCase
{
    private const TEST_TOKEN = 'test_pagbank_token_plano026';

    private ?string $originalCredentialsEnc = null;

    protected function setUp(): void
    {
        parent::setUp();
        GatewayCredentials::resetCache();

        $model = new payment_gateways_model();
        $model->set_field([" credentials_enc "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], ['pagbank']);
        $model->set_paginate([1]);
        $model->load_data(false);
        $this->originalCredentialsEnc = $model->data[0]['credentials_enc'] ?? null;

        GatewayCredentials::save('pagbank', [
            'api_base' => 'https://sandbox.api.pagseguro.com',
            'token'    => self::TEST_TOKEN,
        ]);
    }

    protected function tearDown(): void
    {
        $model = new payment_gateways_model();
        $model->execute_raw_prepared(
            "UPDATE payment_gateways SET credentials_enc = ? WHERE slug = ?",
            [$this->originalCredentialsEnc, 'pagbank']
        );

        GatewayCredentials::resetCache();
        parent::tearDown();
    }

    private function pagBankToken(): string
    {
        return self::TEST_TOKEN;
    }

    public function testVerifyWebhookValidSignaturePasses(): void
    {
        $token = $this->pagBankToken();

        $rawBody = '{"qr_codes":[{"id":"QRCO_X","amount":{"value":1000}}]}';
        $signature = hash('sha256', $token . '-' . $rawBody);

        $gateway = new PagBankGateway();

        $this->assertTrue($gateway->verifyWebhook($rawBody, ['x-authenticity-token' => $signature]));
    }

    public function testVerifyWebhookWrongSignatureFails(): void
    {
        $rawBody = '{"qr_codes":[{"id":"QRCO_X","amount":{"value":1000}}]}';

        $gateway = new PagBankGateway();

        $this->assertFalse($gateway->verifyWebhook($rawBody, ['x-authenticity-token' => 'assinatura-forjada']));
    }

    public function testVerifyWebhookMissingHeaderFails(): void
    {
        $rawBody = '{"qr_codes":[{"id":"QRCO_X","amount":{"value":1000}}]}';

        $gateway = new PagBankGateway();

        $this->assertFalse($gateway->verifyWebhook($rawBody, []));
    }

    public function testVerifyWebhookReformattedBodyFailsSignature(): void
    {
        $token = $this->pagBankToken();

        // Assinatura calculada sobre o body ORIGINAL...
        $originalBody = '{"qr_codes":[{"id":"QRCO_X","amount":{"value":1000}}]}';
        $signature = hash('sha256', $token . '-' . $originalBody);

        // ...mas o body recebido tem um espaco a mais (reformatado) — prova que
        // o hash e sensivel ao byte-a-byte do RAW, nao a estrutura JSON.
        $reformattedBody = '{"qr_codes": [{"id":"QRCO_X","amount":{"value":1000}}]}';

        $gateway = new PagBankGateway();

        $this->assertFalse($gateway->verifyWebhook($reformattedBody, ['x-authenticity-token' => $signature]));
    }

    public function testExtractChargeIdFromQrCodes(): void
    {
        $gateway = new PagBankGateway();

        $id = $gateway->extractChargeId('{"qr_codes":[{"id":"QRCO_ABC"}]}', []);

        $this->assertSame('QRCO_ABC', $id);
    }

    public function testExtractChargeIdMissingQrCodesReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $id = $gateway->extractChargeId('{"charges":[{"status":"PAID"}]}', []);

        $this->assertNull($id);
    }

    public function testExtractChargeIdInvalidJsonReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $id = $gateway->extractChargeId('isso nao e json', []);

        $this->assertNull($id);
    }

    public function testExtractPaidAmountCentsFromCharges(): void
    {
        $gateway = new PagBankGateway();

        $amount = $gateway->extractPaidAmountCents('{"charges":[{"amount":{"value":12345}}]}');

        $this->assertSame(12345, $amount);
    }

    public function testExtractPaidAmountCentsFallsBackToQrCodes(): void
    {
        $gateway = new PagBankGateway();

        $amount = $gateway->extractPaidAmountCents('{"qr_codes":[{"amount":{"value":6789}}]}');

        $this->assertSame(6789, $amount);
    }

    public function testExtractPaidAmountCentsMissingReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $amount = $gateway->extractPaidAmountCents('{"foo":"bar"}');

        $this->assertNull($amount);
    }

    public function testExtractPaidAmountCentsNonNumericReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $amount = $gateway->extractPaidAmountCents('{"charges":[{"amount":{"value":"nao-numerico"}}]}');

        $this->assertNull($amount);
    }

    public function testExtractTransactionNsuFromCharges(): void
    {
        $gateway = new PagBankGateway();

        $nsu = $gateway->extractTransactionNsu('{"charges":[{"id":"CHAR_ABC-123"}]}');

        $this->assertSame('CHAR_ABC-123', $nsu);
    }

    public function testExtractTransactionNsuMissingChargesReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $nsu = $gateway->extractTransactionNsu('{"qr_codes":[{"id":"QRCO_ABC"}]}');

        $this->assertNull($nsu);
    }

    public function testExtractTransactionNsuInvalidJsonReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $nsu = $gateway->extractTransactionNsu('isso nao e json');

        $this->assertNull($nsu);
    }

    public function testExtractTransactionNsuEmptyIdReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $nsu = $gateway->extractTransactionNsu('{"charges":[{"id":""}]}');

        $this->assertNull($nsu);
    }

    public function testExtractTransactionNsuNonStringIdReturnsNull(): void
    {
        $gateway = new PagBankGateway();

        $nsu = $gateway->extractTransactionNsu('{"charges":[{"id":123}]}');

        $this->assertNull($nsu);
    }
}
