<?php

/**
 * Contrato comum aos adapters de PSP de PIX (Mercado Pago, PagBank, InfinitePay).
 *
 * Sem SDK, sem dependencia Composer — implementacoes usam cURL nativo com timeout
 * explicito (CURLOPT_TIMEOUT, CURLOPT_CONNECTTIMEOUT) e CURLOPT_RETURNTRANSFER.
 * Nunca file_get_contents em URL.
 *
 * Credenciais sao lidas via defined('X') ? constant('X') : '' e sao fail-closed:
 * credencial vazia lanca RuntimeException, jamais cobranca sem credencial.
 *
 * Nunca logar token/secret — Logger::getInstance()->error(...) so com
 * gateway_charge_id, orders_id, codigo HTTP e mensagem.
 */
interface PixGateway
{
    /**
     * Cria a cobranca PIX no PSP.
     *
     * @param array $order Linha de `orders` (token, total_cents, etc.)
     * @param array $items Linhas de `order_items` do pedido
     * @return array{
     *   gateway_charge_id: string,
     *   qr_payload: ?string,
     *   qr_image_base64: ?string,
     *   redirect_url: ?string,
     *   expires_at: string
     * }
     * @throws RuntimeException em qualquer falha de rede/HTTP/payload
     */
    public function createCharge(array $order, array $items): array;

    /**
     * Valida a assinatura do webhook. Body e o RAW de php://input.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $query Query string da notificacao — o
     *   Mercado Pago exige que o `data.id` do manifest do x-signature venha
     *   daqui (nao do body); demais gateways ignoram este parametro.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $query = []): bool;

    /**
     * Extrai o id da cobranca do payload do webhook, ou null se irreconhecivel.
     *
     * @param array<string, string> $query
     */
    public function extractChargeId(string $rawBody, array $query): ?string;

    /**
     * Extrai o valor efetivamente pago (em centavos) do payload do webhook, ou
     * null se o payload nao expuser essa informacao (ex.: Mercado Pago, cujo
     * webhook so traz o id do pagamento — quem confirma o valor ali e o
     * fetchStatus() contra a cobranca especifica). Usado pelo webhook_controller
     * para conferir contra orders.total_cents antes de marcar como pago — a unica
     * defesa real em gateways sem assinatura de webhook (ex.: InfinitePay).
     */
    public function extractPaidAmountCents(string $rawBody): ?int;

    /**
     * Extrai o id de transacao (NSU-equivalente) do payload do webhook, ou null
     * quando o PSP nao expoe um id distinto do gateway_charge_id ali.
     *
     * - PagBank: charges[0].id (CHAR_...) — distinto do gateway_charge_id, que
     *   e o id do QR code (QRCO_...). E o id que o PagBank referencia em disputa.
     * - Mercado Pago: null — o gateway_charge_id JA E o payment_id (cobranca
     *   criada via POST /v1/payments), o NSU-equivalente ja esta persistido.
     * - InfinitePay: null — o transaction_nsu vem da reconfirmacao
     *   confirmPayment() (POST /payment_check), nunca do corpo do webhook
     *   (nao assinado, nao confiavel).
     *
     * Metadado de reconciliacao/chargeback, NAO portao de autorizacao: o
     * webhook_controller so grava depois de assinatura + fetchStatus/confirmPayment.
     */
    public function extractTransactionNsu(string $rawBody): ?string;

    /**
     * Consulta o PSP. Usado pelo job de reconciliacao.
     *
     * @return string 'pago' | 'pendente' | 'expirado' | 'erro'
     */
    public function fetchStatus(string $gatewayChargeId): string;
}
