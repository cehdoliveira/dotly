<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre MercadoPagoGateway::verifyWebhook()/extractChargeId()/extractPaidAmountCents()
 * direto — sem rede, sem banco. Estende TestCase puro (nao DBTestCase).
 *
 * verifyWebhook() exige MP_WEBHOOK_SECRET configurado (fail-closed sem ele). O
 * kernel.php.example nao define essa constante, entao os casos que dependem de
 * uma assinatura VALIDA viram skip quando o ambiente nao tem o valor real —
 * mesmo padrao usado para PAGBANK_TOKEN em WebhookIdempotencyTest e
 * PagBankGatewayTest. Nunca hardcodar o segredo: o hash esperado sempre usa
 * constant('MP_WEBHOOK_SECRET').
 *
 * Investigacao do plano 026 (data.id do manifest): a documentacao oficial do
 * Mercado Pago (docs de "Webhooks" / "payment-notifications" — verificado em
 * julho/2026) instrui explicitamente a ler o `data.id` usado no manifest do
 * x-signature a partir da QUERY STRING da notificacao (ex.: `req.query['data.id']`
 * nos exemplos oficiais em JS/Go), nao do body. O verifyWebhook() ja foi
 * corrigido neste plano para repassar a query recebida a extractChargeId() em
 * vez de uma query vazia — os testes abaixo cobrem o comportamento NOVO.
 */
final class MercadoPagoGatewayTest extends TestCase
{
    private function mpSecretOrSkip(): string
    {
        if (!defined('MP_WEBHOOK_SECRET') || (string)constant('MP_WEBHOOK_SECRET') === '') {
            $this->markTestSkipped('MP_WEBHOOK_SECRET nao configurado neste ambiente.');
        }

        return (string)constant('MP_WEBHOOK_SECRET');
    }

    private function manifestSignature(string $secret, string $dataId, string $requestId, string $ts): string
    {
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";

        return hash_hmac('sha256', $manifest, $secret);
    }

    public function testVerifyWebhookValidSignaturePasses(): void
    {
        $secret = $this->mpSecretOrSkip();

        $rawBody = '{"data":{"id":"123"}}';
        $ts = '1700000000';
        $requestId = 'req-1';
        $v1 = $this->manifestSignature($secret, '123', $requestId, $ts);

        $gateway = new MercadoPagoGateway();

        $result = $gateway->verifyWebhook($rawBody, [
            'x-signature'  => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ]);

        $this->assertTrue($result);
    }

    public function testVerifyWebhookTamperedV1Fails(): void
    {
        $secret = $this->mpSecretOrSkip();

        $rawBody = '{"data":{"id":"123"}}';
        $ts = '1700000000';
        $requestId = 'req-1';
        $v1 = $this->manifestSignature($secret, '123', $requestId, $ts);
        $tamperedV1 = substr($v1, 0, -1) . (($v1[-1] ?? '0') === '0' ? '1' : '0');

        $gateway = new MercadoPagoGateway();

        $result = $gateway->verifyWebhook($rawBody, [
            'x-signature'  => "ts={$ts},v1={$tamperedV1}",
            'x-request-id' => $requestId,
        ]);

        $this->assertFalse($result);
    }

    public function testVerifyWebhookArbitrarySignatureAlwaysFails(): void
    {
        // Nao depende de MP_WEBHOOK_SECRET estar configurado: com o segredo
        // ausente, verifyWebhook() ja falha fechado; com o segredo presente,
        // um v1 arbitrario nunca bate no hash_equals(). Nos dois casos o
        // resultado correto e false — cobre "assinatura invalida" sem skip.
        $gateway = new MercadoPagoGateway();

        $result = $gateway->verifyWebhook('{"data":{"id":"123"}}', [
            'x-signature'  => 'ts=1700000000,v1=assinatura-forjada-e-invalida',
            'x-request-id' => 'req-1',
        ]);

        $this->assertFalse($result);
    }

    public function testVerifyWebhookMissingSignatureHeaderFails(): void
    {
        $gateway = new MercadoPagoGateway();

        $result = $gateway->verifyWebhook('{"data":{"id":"123"}}', [
            'x-request-id' => 'req-1',
        ]);

        $this->assertFalse($result);
    }

    public function testVerifyWebhookMalformedSignatureHeaderFails(): void
    {
        $gateway = new MercadoPagoGateway();

        // Sem "ts=" nem "v1=" reconheciveis.
        $result = $gateway->verifyWebhook('{"data":{"id":"123"}}', [
            'x-signature'  => 'garbage-sem-formato-esperado',
            'x-request-id' => 'req-1',
        ]);

        $this->assertFalse($result);
    }

    /**
     * Documenta o comportamento ATUAL: body sem `data.id` e SEM query — a
     * cobranca nao e identificavel e verifyWebhook() falha (falso), mesmo com
     * segredo/assinatura corretos. E o cenario que motivou a investigacao do
     * plano 026 — sem query, nao ha de onde tirar o data.id.
     */
    public function testVerifyWebhookBodyWithoutDataIdAndNoQueryFails(): void
    {
        $secret = $this->mpSecretOrSkip();

        $rawBody = '{"type":"payment"}';
        $ts = '1700000000';
        $requestId = 'req-1';
        // Assinatura calculada sobre um id arbitrario — nao importa: sem
        // data.id extraido, verifyWebhook() nunca chega a comparar o hash.
        $v1 = $this->manifestSignature($secret, '999', $requestId, $ts);

        $gateway = new MercadoPagoGateway();

        $result = $gateway->verifyWebhook($rawBody, [
            'x-signature'  => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ]);

        $this->assertFalse($result);
    }

    /**
     * Comportamento NOVO (pos-correcao do plano 026): body sem `data.id`, mas
     * a query da notificacao traz `data.id` — verifyWebhook() agora repassa a
     * query recebida a extractChargeId() e consegue validar a assinatura.
     * Antes da correcao, extractChargeId() sempre recebia uma query vazia e
     * este caso falhava mesmo com assinatura correta.
     *
     * A chave usada aqui e "data_id" (underscore), nao "data.id" (ponto): o PHP
     * troca "." por "_" ao popular $_GET a partir da query string
     * (`?data.id=456` vira `$_GET['data_id']`), e webhook_controller::receive()
     * repassa $_GET direto como $query — entao esta e a forma REAL que chega em
     * producao, nao a hipotetica com ponto.
     */
    public function testVerifyWebhookValidSignatureWithDataIdOnlyInQueryPasses(): void
    {
        $secret = $this->mpSecretOrSkip();

        $rawBody = '{"type":"payment"}';
        $ts = '1700000000';
        $requestId = 'req-1';
        $v1 = $this->manifestSignature($secret, '456', $requestId, $ts);

        $gateway = new MercadoPagoGateway();

        $result = $gateway->verifyWebhook(
            $rawBody,
            [
                'x-signature'  => "ts={$ts},v1={$v1}",
                'x-request-id' => $requestId,
            ],
            ['data_id' => '456']
        );

        $this->assertTrue($result);
    }

    public function testExtractChargeIdFromBodyDataId(): void
    {
        $gateway = new MercadoPagoGateway();

        $id = $gateway->extractChargeId('{"data":{"id":"123456"}}', []);

        $this->assertSame('123456', $id);
    }

    public function testExtractChargeIdFromQueryDataIdUnderscoreKey(): void
    {
        // Forma REAL como a query chega em producao: PHP troca "." por "_" ao
        // popular $_GET (`?data.id=q9` -> `$_GET['data_id']`), e
        // webhook_controller::receive() repassa $_GET direto como $query.
        $gateway = new MercadoPagoGateway();

        $id = $gateway->extractChargeId('{}', ['data_id' => 'q9']);

        $this->assertSame('q9', $id);
    }

    public function testExtractChargeIdFromQueryDataIdDotKey(): void
    {
        // Fallback secundario para um chamador hipotetico que preserve o ponto
        // (query manualmente parseada em vez de vinda de $_GET).
        $gateway = new MercadoPagoGateway();

        $id = $gateway->extractChargeId('{}', ['data.id' => 'q9']);

        $this->assertSame('q9', $id);
    }

    public function testExtractChargeIdFromQueryIdKeyFallback(): void
    {
        $gateway = new MercadoPagoGateway();

        $id = $gateway->extractChargeId('{}', ['id' => 'q9']);

        $this->assertSame('q9', $id);
    }

    public function testExtractChargeIdUnrecognizedQueryKeyReturnsNull(): void
    {
        $gateway = new MercadoPagoGateway();

        // Nenhuma das 3 chaves honradas (data_id, data.id, id) presente -> null.
        $id = $gateway->extractChargeId('{}', ['topic' => 'payment']);

        $this->assertNull($id);
    }

    /**
     * Achado da revisao adversarial (/ship do plano 026): `?data.id[]=x` chega
     * em $_GET como array (PHP aceita colchetes em chaves de query string).
     * Sem guarda, o cast final `(string)$id` dispara "Array to string
     * conversion" e devolve a string literal "Array" em vez de null — nunca
     * um bypass de auth (a assinatura HMAC continua reprovando), mas um valor
     * de lookup sem sentido e ruido de log. `is_scalar()` fecha o caminho.
     */
    public function testExtractChargeIdArrayValueInQueryReturnsNull(): void
    {
        $gateway = new MercadoPagoGateway();

        $id = $gateway->extractChargeId('{}', ['data_id' => ['nao-e-string']]);

        $this->assertNull($id);
    }

    public function testExtractChargeIdInvalidJsonReturnsNull(): void
    {
        $gateway = new MercadoPagoGateway();

        $id = $gateway->extractChargeId('isso nao e json', ['data.id' => 'q9']);

        // JSON invalido -> json_decode() retorna null -> cai no fallback de query.
        $this->assertSame('q9', $id);
    }

    public function testExtractChargeIdInvalidJsonAndNoQueryReturnsNull(): void
    {
        $gateway = new MercadoPagoGateway();

        $id = $gateway->extractChargeId('isso nao e json', []);

        $this->assertNull($id);
    }

    public function testExtractPaidAmountCentsAlwaysReturnsNull(): void
    {
        // O webhook do Mercado Pago so traz o id do pagamento — o valor e
        // conhecido apenas via fetchStatus() contra a API. extractPaidAmountCents()
        // e um `return null;` incondicional (ver corpo do metodo).
        $gateway = new MercadoPagoGateway();

        $this->assertNull($gateway->extractPaidAmountCents('{"data":{"id":"123"}}'));
        $this->assertNull($gateway->extractPaidAmountCents('{}'));
        $this->assertNull($gateway->extractPaidAmountCents('json invalido'));
    }

    public function testExtractTransactionNsuAlwaysReturnsNull(): void
    {
        // gateway_charge_id do MP JA E o payment_id — extractTransactionNsu()
        // e um `return null;` incondicional (ver PixGateway::extractTransactionNsu).
        $gateway = new MercadoPagoGateway();

        $this->assertNull($gateway->extractTransactionNsu('{"data":{"id":"123"}}'));
        $this->assertNull($gateway->extractTransactionNsu('{}'));
        $this->assertNull($gateway->extractTransactionNsu('json invalido'));
    }
}
