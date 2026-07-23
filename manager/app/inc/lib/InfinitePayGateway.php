<?php

/**
 * Adapter InfinitePay — modo `redirect` (checkout hospedado, sem API de PIX inline).
 *
 * InfinitePay nao oferece geracao de QR Code PIX via API: o unico fluxo publico e um
 * link de checkout hospedado (POST /links) para onde o comprador e redirecionado.
 * Verificado contra a documentacao publica ("Como usar o Checkout da InfinitePay")
 * em julho/2026. Sem SDK — cURL nativo apenas.
 *
 * Credenciais: INFINITEPAY_HANDLE (kernel.php, fail-closed se vazio). Sem token de
 * autenticacao no header — o `handle` identifica o recebedor.
 */
class InfinitePayGateway implements PixGateway
{
    private const API_BASE = 'https://api.checkout.infinitepay.io';
    private const HTTP_TIMEOUT = 10;
    private const HTTP_CONNECT_TIMEOUT = 5;

    private function handle(): string
    {
        return defined('INFINITEPAY_HANDLE') ? (string)constant('INFINITEPAY_HANDLE') : '';
    }

    public function createCharge(array $order, array $items): array
    {
        // Fail-closed: sem handle configurado nao ha recebedor — aborta antes de
        // qualquer HTTP. O throw fica aqui (nao em handle()) para que
        // buildChargeBody() seja testavel sem INFINITEPAY_HANDLE definido, ex.:
        // CI rodando com kernel.php.example (handle vazio).
        if ($this->handle() === '') {
            throw new RuntimeException('INFINITEPAY_HANDLE nao configurado');
        }

        $body = $this->buildChargeBody($order, $items);

        [$httpCode, $response] = $this->request('POST', self::API_BASE . '/links', $body, [
            'Content-Type: application/json',
        ]);

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($response) || empty($response['url'])) {
            Logger::getInstance()->error('InfinitePayGateway::createCharge falhou', [
                'orders_id' => $order['idx'] ?? null,
                'http_code' => $httpCode,
            ]);
            throw new RuntimeException('Falha ao criar link de checkout no InfinitePay');
        }

        return [
            // Sem API de PIX inline: order_nsu (nosso token, ja opaco e unico) e o unico
            // identificador que atravessa ida e volta — nao ha id de cobranca do PSP.
            'gateway_charge_id' => (string)$order['token'],
            'qr_payload'        => null,
            'qr_image_base64'   => null,
            'redirect_url'      => (string)$response['url'],
            'expires_at'        => (string)$order['expires_at'],
        ];
    }

    /**
     * Monta o corpo do POST /links. Publico de proposito, para ser testavel sem
     * rede (mesmo padrao de lockAndValidateCart no checkout_controller).
     *
     * @param array<string, mixed> $order
     * @param array<int, array{qty:int, unit_price_cents:int, product_name:string}> $items
     * @return array<string, mixed>
     */
    public function buildChargeBody(array $order, array $items): array
    {
        global $done_url;

        $orderItems = [];
        foreach ($items as $item) {
            $orderItems[] = [
                'quantity'    => (int)$item['qty'],
                'price'       => (int)$item['unit_price_cents'],
                'description' => (string)$item['product_name'],
            ];
        }

        $body = [
            'handle'       => $this->handle(),
            'redirect_url' => sprintf($done_url, (string)$order['token']),
            'webhook_url'  => $this->webhookUrl(),
            'order_nsu'    => (string)$order['token'],
            'items'        => $orderItems,
        ];

        // Dados do comprador (opcional na API): pre-carrega nome/e-mail/telefone
        // na tela de pagamento do InfinitePay para o cliente nao redigitar.
        $customer = $this->buildCustomer($order);
        if ($customer !== []) {
            $body['customer'] = $customer;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    private function buildCustomer(array $order): array
    {
        $name  = trim((string)($order['customer_name'] ?? ''));
        $email = trim((string)($order['customer_mail'] ?? ''));
        $phone = preg_replace('/\D/', '', (string)($order['customer_phone'] ?? '')) ?? '';

        $customer = [];
        if ($name !== '') {
            $customer['name'] = $name;
        }
        if ($email !== '') {
            $customer['email'] = $email;
        }
        if ($phone !== '') {
            // Formato internacional esperado pela InfinitePay: +55 + DDD + numero.
            $customer['phone_number'] = '+55' . $phone;
        }

        return $customer;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $query = []): bool
    {
        // InfinitePay nao publica assinatura de webhook. verifyWebhook nao e a camada
        // de autenticacao: a defesa real e a reconfirmacao confirmPayment() (POST
        // /payment_check) que o webhook_controller chama para InfinitePay antes de
        // marcar como pago. Ver plano 031.
        return true;
    }

    /**
     * Monta o corpo do POST /payment_check a partir do payload do webhook, ou null
     * se o payload nao trouxer transaction_nsu + slug (sem eles nao ha o que
     * reconfirmar no PSP). Publico de proposito, para ser testavel sem rede.
     *
     * @param array<string, mixed> $payload payload decodificado do webhook
     * @return array<string, string>|null
     */
    public function buildPaymentCheckBody(array $payload): ?array
    {
        $orderNsu       = trim((string)($payload['order_nsu'] ?? ''));
        $transactionNsu = trim((string)($payload['transaction_nsu'] ?? ''));
        $slug           = trim((string)($payload['invoice_slug'] ?? $payload['slug'] ?? ''));

        if ($transactionNsu === '' || $slug === '' || $orderNsu === '') {
            return null;
        }

        return [
            'handle'          => $this->handle(),
            'order_nsu'       => $orderNsu,
            'transaction_nsu' => $transactionNsu,
            'slug'            => $slug,
        ];
    }

    /**
     * Interpreta a resposta do POST /payment_check. Retorna se o pagamento esta
     * confirmado como pago e o valor autoritativo pago (em centavos), que o
     * webhook_controller usa na checagem de valor NO LUGAR do paid_amount do corpo
     * do webhook (que e controlado por quem posta). Publico para ser testavel sem rede.
     *
     * @param array<string, mixed>|null $response corpo decodificado da resposta
     * @return array{paid: bool, paid_amount_cents: ?int}
     */
    public function parsePaymentCheckResponse(?array $response): array
    {
        if (!is_array($response)) {
            return ['paid' => false, 'paid_amount_cents' => null];
        }

        $paid = ($response['success'] ?? null) === true && ($response['paid'] ?? null) === true;

        $amount = $response['paid_amount'] ?? $response['amount'] ?? null;
        $paidAmountCents = is_numeric($amount) ? (int)$amount : null;

        // paid=true sem valor autoritativo nao e uma confirmacao completa: sem isto,
        // o webhook_controller cairia no fallback do paid_amount do CORPO do webhook
        // (controlado por quem posta) — exatamente o dado que esta reconfirmacao
        // existe para nao confiar. Trata como nao confirmado.
        if ($paid && $paidAmountCents === null) {
            return ['paid' => false, 'paid_amount_cents' => null];
        }

        return ['paid' => $paid, 'paid_amount_cents' => $paidAmountCents];
    }

    /**
     * Reconfirma no PSP se o webhook InfinitePay corresponde a um pagamento real.
     * InfinitePay nao assina webhooks, entao esta e a defesa contra um comprador
     * forjar o POST do proprio pedido: o transaction_nsu e um UUID gerado pela
     * InfinitePay que so existe apos um pagamento real, e o valor confirmado vem
     * da resposta da API (autoritativo), nao do corpo do webhook. Ver plano 031.
     *
     * Fail-closed: qualquer impossibilidade de reconfirmar (handle ausente, payload
     * sem transaction_nsu/slug, falha de rede, HTTP != 2xx) retorna paid=false — o
     * webhook_controller entao NAO marca o pedido como pago.
     *
     * `retriable` distingue "o PSP respondeu e disse que nao esta pago" (retriable
     * false — reenviar o MESMO corpo nunca vai mudar essa resposta, webhook_controller
     * aceita com 200) de "nao consegui nem perguntar pro PSP" (retriable true — falha
     * de rede/config/HTTP, pode se resolver sozinha; webhook_controller responde
     * nao-2xx para a InfinitePay tentar de novo). Sem essa distincao, um blip de rede
     * durante um pagamento REAL faria o pedido nunca confirmar — a InfinitePay nao
     * teria motivo pra reenviar um webhook que recebeu 200, e este gateway nao tem
     * endpoint de reconciliacao (fetchStatus() sempre 'pendente' por design).
     * Achado da revisao adversarial do /ship (red team + Codex, convergentes).
     *
     * transaction_nsu volta no retorno para o webhook_controller persistir com
     * UNIQUE em pix_charges — sem isso, o mesmo transaction_nsu real (de um
     * pagamento legitimo) poderia ser reenviado num webhook forjado para
     * confirmar um pedido DIFERENTE. Ver migration 042 e plano 031.
     *
     * @return array{paid: bool, paid_amount_cents: ?int, transaction_nsu: ?string, retriable: bool}
     */
    public function confirmPayment(string $rawBody): array
    {
        $notPaid     = ['paid' => false, 'paid_amount_cents' => null, 'transaction_nsu' => null, 'retriable' => false];
        $indeterminate = ['paid' => false, 'paid_amount_cents' => null, 'transaction_nsu' => null, 'retriable' => true];

        // Checa primeiro se ESTE corpo tem o que precisa pra reconfirmar — fato
        // permanente do request, independente de config. So checa handle() (config,
        // pode mudar) quando ja sabemos que faria sentido tentar a chamada de rede
        // de verdade — assim um ambiente sem INFINITEPAY_HANDLE configurado (ex.:
        // kernel.php.example, usado em CI) nao vira "retriable" para corpos que
        // seriam fail-closed de qualquer forma.
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $notPaid;
        }

        $body = $this->buildPaymentCheckBody($payload);
        if ($body === null) {
            // Falta transaction_nsu/slug NESTE corpo especifico — reenviar o MESMO
            // corpo nunca vai trazer esses campos, nao adianta pedir retry.
            Logger::getInstance()->warning('InfinitePay payment_check: webhook sem transaction_nsu/slug — nao reconfirmavel (fail-closed)', [
                'order_nsu' => (string)($payload['order_nsu'] ?? ''),
            ]);
            return $notPaid;
        }

        if ($this->handle() === '') {
            // Config ausente e um problema operacional que pode ser corrigido a
            // qualquer momento — retriable, para a InfinitePay tentar de novo depois
            // (em vez de perder o webhook pra sempre enquanto ninguem notou).
            Logger::getInstance()->warning('InfinitePay payment_check: INFINITEPAY_HANDLE nao configurado (fail-closed)');
            return $indeterminate;
        }

        try {
            [$httpCode, $response] = $this->request('POST', self::API_BASE . '/payment_check', $body, [
                'Content-Type: application/json',
            ]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('InfinitePay payment_check: falha de rede', [
                'order_nsu' => $body['order_nsu'],
                'error'     => $e->getMessage(),
            ]);
            return $indeterminate;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::getInstance()->warning('InfinitePay payment_check: HTTP nao-2xx', [
                'order_nsu' => $body['order_nsu'],
                'http_code' => $httpCode,
            ]);
            return $indeterminate;
        }

        $result = $this->parsePaymentCheckResponse($response);
        if (!$result['paid']) {
            return $notPaid;
        }

        return [
            'paid'              => true,
            'paid_amount_cents' => $result['paid_amount_cents'],
            'transaction_nsu'   => $body['transaction_nsu'],
            'retriable'         => false,
        ];
    }

    public function extractChargeId(string $rawBody, array $query): ?string
    {
        $payload = json_decode($rawBody, true);

        if (is_array($payload) && isset($payload['order_nsu'])) {
            return (string)$payload['order_nsu'];
        }

        return null;
    }

    public function extractPaidAmountCents(string $rawBody): ?int
    {
        // Fallback usado pelo webhook_controller para MercadoPago/PagBank (gateways
        // sem endpoint de reconfirmacao proprio no payload). Para InfinitePay, o
        // valor autoritativo vem de confirmPayment() (payment_check), nunca daqui —
        // ver plano 031.
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return null;
        }

        $value = $payload['paid_amount'] ?? $payload['amount'] ?? null;

        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Sempre null: o transaction_nsu do InfinitePay vem da reconfirmacao
     * confirmPayment() (POST /payment_check), nunca do corpo do webhook (nao
     * assinado, nao confiavel) — ver docblock da interface
     * PixGateway::extractTransactionNsu().
     */
    public function extractTransactionNsu(string $rawBody): ?string
    {
        return null;
    }

    public function fetchStatus(string $gatewayChargeId): string
    {
        // Sem endpoint publico de consulta de status no InfinitePay. Consequencia
        // honesta: pedido InfinitePay nao tem fallback de reconciliacao — se o
        // webhook nao chegar, o pedido expira (ver 002 Passo 12).
        Logger::getInstance()->warning('InfinitePayGateway::fetchStatus sem endpoint publico de consulta', [
            'gateway_charge_id' => $gatewayChargeId,
        ]);
        return 'pendente';
    }

    private function webhookUrl(): string
    {
        return rtrim(canonical_url('SITE_CANONICAL_URL'), '/') . '/webhook/pix/infinitepay';
    }

    /**
     * @return array{0: int, 1: ?array}
     */
    private function request(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL para o InfinitePay');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::getInstance()->error('InfinitePayGateway: falha de rede', [
                'url_path' => parse_url($url, PHP_URL_PATH),
                'error'    => $curlError,
            ]);
            throw new RuntimeException('Falha de rede ao comunicar com o InfinitePay');
        }

        $decoded = json_decode((string)$raw, true);

        return [$httpCode, is_array($decoded) ? $decoded : null];
    }
}
