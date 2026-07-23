<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre PagBankGateway::verifyWebhook()/extractChargeId()/extractPaidAmountCents()
 * direto — sem rede, sem banco. Estende TestCase puro (nao DBTestCase).
 *
 * verifyWebhook() exige PAGBANK_TOKEN configurado (fail-closed sem ele). O
 * kernel.php.example nao define essa constante, entao os casos que dependem de
 * uma assinatura VALIDA (calculada com o segredo efetivo) viram skip quando o
 * ambiente nao tem o valor real — mesmo padrao ja usado em
 * WebhookIdempotencyTest::testValidPagBankSignaturePassesAuthCheck(). Nunca
 * hardcodar o segredo: o hash esperado sempre usa constant('PAGBANK_TOKEN').
 */
final class PagBankGatewayTest extends TestCase
{
    private function pagBankTokenOrSkip(): string
    {
        if (!defined('PAGBANK_TOKEN') || (string)constant('PAGBANK_TOKEN') === '') {
            $this->markTestSkipped('PAGBANK_TOKEN nao configurado neste ambiente.');
        }

        return (string)constant('PAGBANK_TOKEN');
    }

    public function testVerifyWebhookValidSignaturePasses(): void
    {
        $token = $this->pagBankTokenOrSkip();

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
        $token = $this->pagBankTokenOrSkip();

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
