<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre InfinitePayGateway direto — sem rede, sem banco. Estende TestCase puro.
 *
 * Documentacao executavel da decisao registrada: InfinitePay nao documenta
 * assinatura de webhook publicamente, entao verifyWebhook() sempre retorna
 * true (autenticacao real acontece via order_nsu opaco + checagem de valor no
 * webhook_controller — ver comentario no adapter). Este teste trava essa
 * decisao: se alguem mudar o retorno para depender de header/assinatura, o
 * teste falha e avisa que a mudanca foi intencional ou nao.
 */
final class InfinitePayGatewayTest extends TestCase
{
    public function testVerifyWebhookAlwaysReturnsTrueRegardlessOfInput(): void
    {
        $gateway = new InfinitePayGateway();

        $this->assertTrue($gateway->verifyWebhook('qualquer coisa', []));
        $this->assertTrue($gateway->verifyWebhook('', ['x-signature' => 'nao-importa']));
        $this->assertTrue($gateway->verifyWebhook('{"invalido":true}', [], ['data.id' => 'x']));
    }

    public function testExtractChargeIdFromOrderNsu(): void
    {
        $gateway = new InfinitePayGateway();

        $id = $gateway->extractChargeId('{"order_nsu":"token-abc-123"}', []);

        $this->assertSame('token-abc-123', $id);
    }

    public function testExtractChargeIdMissingOrderNsuReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $id = $gateway->extractChargeId('{"paid_amount":1000}', []);

        $this->assertNull($id);
    }

    public function testExtractChargeIdInvalidJsonReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $id = $gateway->extractChargeId('nao e json', []);

        $this->assertNull($id);
    }

    public function testExtractPaidAmountCentsFromPaidAmount(): void
    {
        $gateway = new InfinitePayGateway();

        $amount = $gateway->extractPaidAmountCents('{"paid_amount":5000}');

        $this->assertSame(5000, $amount);
    }

    public function testExtractPaidAmountCentsFallsBackToAmount(): void
    {
        $gateway = new InfinitePayGateway();

        $amount = $gateway->extractPaidAmountCents('{"amount":4321}');

        $this->assertSame(4321, $amount);
    }

    public function testExtractPaidAmountCentsMissingReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $amount = $gateway->extractPaidAmountCents('{"order_nsu":"token-abc"}');

        $this->assertNull($amount);
    }

    public function testExtractPaidAmountCentsInvalidJsonReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $amount = $gateway->extractPaidAmountCents('nao e json');

        $this->assertNull($amount);
    }

    public function testExtractPaidAmountCentsNonNumericReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $amount = $gateway->extractPaidAmountCents('{"paid_amount":"nao-numerico"}');

        $this->assertNull($amount);
    }

    public function testExtractTransactionNsuAlwaysReturnsNull(): void
    {
        // O transaction_nsu do InfinitePay vem de confirmPayment() (payment_check),
        // nunca do corpo do webhook — extractTransactionNsu() e um `return null;`
        // incondicional (ver PixGateway::extractTransactionNsu).
        $gateway = new InfinitePayGateway();

        $this->assertNull($gateway->extractTransactionNsu('{"order_nsu":"token-abc"}'));
        $this->assertNull($gateway->extractTransactionNsu('{}'));
        $this->assertNull($gateway->extractTransactionNsu('nao e json'));
    }

    public function testBuildChargeBodyIncludesCustomerData(): void
    {
        $GLOBALS['done_url'] = 'https://site.local/pedido/%s/done';
        $gateway = new InfinitePayGateway();

        $order = [
            'token'          => 'tok-123',
            'customer_name'  => 'João Silva',
            'customer_mail'  => 'joao@email.com',
            'customer_phone' => '11988887777',
        ];
        $items = [['qty' => 1, 'unit_price_cents' => 7760, 'product_name' => 'Loja Teste - Pedido #4821']];

        $body = $gateway->buildChargeBody($order, $items);

        $this->assertArrayHasKey('customer', $body);
        $this->assertSame('João Silva', $body['customer']['name']);
        $this->assertSame('joao@email.com', $body['customer']['email']);
        // InfinitePay espera o telefone no formato internacional (+55DDDNUMERO).
        $this->assertSame('+5511988887777', $body['customer']['phone_number']);
        $this->assertSame('tok-123', $body['order_nsu']);
        $this->assertSame([['quantity' => 1, 'price' => 7760, 'description' => 'Loja Teste - Pedido #4821']], $body['items']);
    }

    public function testBuildChargeBodyOmitsCustomerWhenAbsent(): void
    {
        $GLOBALS['done_url'] = 'https://site.local/pedido/%s/done';
        $gateway = new InfinitePayGateway();

        $body = $gateway->buildChargeBody(['token' => 'tok-xyz'], []);

        $this->assertArrayNotHasKey('customer', $body);
    }

    public function testBuildPaymentCheckBodyWithCompletePayload(): void
    {
        $gateway = new InfinitePayGateway();

        $body = $gateway->buildPaymentCheckBody([
            'order_nsu'       => 'tok-abc',
            'transaction_nsu' => 'uuid-123',
            'slug'            => 'invoice-slug-1',
        ]);

        $this->assertIsArray($body);
        $this->assertArrayHasKey('handle', $body);
        $this->assertSame('tok-abc', $body['order_nsu']);
        $this->assertSame('uuid-123', $body['transaction_nsu']);
        $this->assertSame('invoice-slug-1', $body['slug']);
    }

    public function testBuildPaymentCheckBodyAcceptsInvoiceSlugKey(): void
    {
        $gateway = new InfinitePayGateway();

        $body = $gateway->buildPaymentCheckBody([
            'order_nsu'       => 'tok-abc',
            'transaction_nsu' => 'uuid-123',
            'invoice_slug'    => 'invoice-slug-1',
        ]);

        $this->assertIsArray($body);
        $this->assertSame('invoice-slug-1', $body['slug']);
    }

    public function testBuildPaymentCheckBodyMissingTransactionNsuReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $body = $gateway->buildPaymentCheckBody([
            'order_nsu' => 'tok-abc',
            'slug'      => 'invoice-slug-1',
        ]);

        $this->assertNull($body);
    }

    public function testBuildPaymentCheckBodyMissingSlugReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $body = $gateway->buildPaymentCheckBody([
            'order_nsu'       => 'tok-abc',
            'transaction_nsu' => 'uuid-123',
        ]);

        $this->assertNull($body);
    }

    public function testBuildPaymentCheckBodyMissingOrderNsuReturnsNull(): void
    {
        $gateway = new InfinitePayGateway();

        $body = $gateway->buildPaymentCheckBody([
            'transaction_nsu' => 'uuid-123',
            'slug'            => 'invoice-slug-1',
        ]);

        $this->assertNull($body);
    }

    public function testParsePaymentCheckResponsePaidTrue(): void
    {
        $gateway = new InfinitePayGateway();

        $result = $gateway->parsePaymentCheckResponse([
            'success'     => true,
            'paid'        => true,
            'paid_amount' => 1510,
        ]);

        $this->assertSame(['paid' => true, 'paid_amount_cents' => 1510], $result);
    }

    public function testParsePaymentCheckResponsePaidTrueWithoutAmountFailsClosed(): void
    {
        // paid=true sem valor autoritativo nao pode virar confirmacao — senao o
        // webhook_controller cairia no paid_amount do CORPO do webhook (atacante).
        $gateway = new InfinitePayGateway();

        $result = $gateway->parsePaymentCheckResponse([
            'success' => true,
            'paid'    => true,
        ]);

        $this->assertSame(['paid' => false, 'paid_amount_cents' => null], $result);
    }

    public function testParsePaymentCheckResponsePaidFalse(): void
    {
        $gateway = new InfinitePayGateway();

        $result = $gateway->parsePaymentCheckResponse([
            'success' => true,
            'paid'    => false,
        ]);

        $this->assertFalse($result['paid']);
    }

    public function testParsePaymentCheckResponseSuccessFalseOverridesPaid(): void
    {
        $gateway = new InfinitePayGateway();

        $result = $gateway->parsePaymentCheckResponse([
            'success' => false,
            'paid'    => true,
        ]);

        $this->assertFalse($result['paid']);
    }

    public function testParsePaymentCheckResponseFallsBackToAmount(): void
    {
        $gateway = new InfinitePayGateway();

        $result = $gateway->parsePaymentCheckResponse([
            'success' => true,
            'paid'    => true,
            'amount'  => 4321,
        ]);

        $this->assertSame(['paid' => true, 'paid_amount_cents' => 4321], $result);
    }

    public function testParsePaymentCheckResponseNonArrayReturnsNotPaid(): void
    {
        $gateway = new InfinitePayGateway();

        $result = $gateway->parsePaymentCheckResponse(null);

        $this->assertSame(['paid' => false, 'paid_amount_cents' => null], $result);
    }
}
