<?php

/**
 * Adapter PagBank — modo `qr` (PIX via POST /orders com qr_codes).
 *
 * Verificado contra a documentacao oficial (developer.pagbank.com.br —
 * "Criar pedido com QR Code (PIX)", "Objeto Charge", "Objeto Order",
 * "Confirmar autenticidade da notificacao") em julho/2026. Sem SDK — cURL
 * nativo apenas.
 *
 * Credenciais: PAGBANK_API_BASE (ex.: https://sandbox.api.pagseguro.com em
 * teste), PAGBANK_TOKEN (kernel.php, fail-closed se vazio).
 *
 * Nota sobre fetchStatus(): a doc oficial nao detalha o corpo de resposta de
 * GET /orders/{id}, e relatos da comunidade PagBank indicam que a consulta
 * pode devolver 404 enquanto o pedido ainda esta pendente de pagamento. Por
 * isso 404 e tratado como 'pendente' (nao 'erro') — ver comentario no metodo.
 */
class PagBankGateway implements PixGateway
{
    private const HTTP_TIMEOUT = 10;
    private const HTTP_CONNECT_TIMEOUT = 5;

    private function apiBase(): string
    {
        $base = defined('PAGBANK_API_BASE') ? (string)constant('PAGBANK_API_BASE') : '';
        if ($base === '') {
            throw new RuntimeException('PAGBANK_API_BASE nao configurado');
        }
        return rtrim($base, '/');
    }

    private function token(): string
    {
        $token = defined('PAGBANK_TOKEN') ? (string)constant('PAGBANK_TOKEN') : '';
        if ($token === '') {
            throw new RuntimeException('PAGBANK_TOKEN nao configurado');
        }
        return $token;
    }

    public function createCharge(array $order, array $items): array
    {
        $token = $this->token();

        $orderItems = [];
        foreach ($items as $item) {
            $orderItems[] = [
                'name'        => (string)$item['product_name'],
                'quantity'    => (int)$item['qty'],
                'unit_amount' => (int)$item['unit_price_cents'],
            ];
        }

        $body = [
            'reference_id' => (string)$order['token'],
            'customer'     => [
                'name'   => (string)$order['customer_name'],
                'email'  => (string)$order['customer_mail'],
                'tax_id' => (string)$order['customer_cpf'],
            ],
            'items'        => $orderItems,
            'qr_codes'     => [
                [
                    'amount'          => ['value' => (int)$order['total_cents']],
                    'expiration_date' => $this->expirationIso((string)$order['expires_at']),
                ],
            ],
            'notification_urls' => [$this->webhookUrl()],
        ];

        [$httpCode, $response] = $this->request(
            'POST',
            $this->apiBase() . '/orders',
            $body,
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]
        );

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($response)) {
            Logger::getInstance()->error('PagBankGateway::createCharge falhou', [
                'orders_id' => $order['idx'] ?? null,
                'http_code' => $httpCode,
            ]);
            throw new RuntimeException('Falha ao criar cobranca PIX no PagBank');
        }

        $qrCode = $response['qr_codes'][0] ?? null;
        $chargeId = is_array($qrCode) && isset($qrCode['id']) ? (string)$qrCode['id'] : '';

        if ($chargeId === '') {
            Logger::getInstance()->error('PagBankGateway::createCharge sem id de qr_code na resposta', [
                'orders_id' => $order['idx'] ?? null,
                'http_code' => $httpCode,
            ]);
            throw new RuntimeException('Resposta do PagBank sem id de qr_code');
        }

        $qrPayload = isset($qrCode['text']) ? (string)$qrCode['text'] : null;
        $qrImageBase64 = $this->downloadQrImage($qrCode['links'] ?? [], $token);

        return [
            'gateway_charge_id' => $chargeId,
            'qr_payload'        => $qrPayload,
            'qr_image_base64'   => $qrImageBase64,
            'redirect_url'      => null,
            'expires_at'        => (string)$order['expires_at'],
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $query = []): bool
    {
        $token = defined('PAGBANK_TOKEN') ? (string)constant('PAGBANK_TOKEN') : '';
        if ($token === '') {
            Logger::getInstance()->error('PAGBANK_TOKEN nao configurado — recusando webhook');
            return false;
        }

        $signature = $this->headerValue($headers, 'x-authenticity-token');
        if ($signature === null) {
            return false;
        }

        // O body precisa ser o RAW, sem reformatar — um espaco a mais e o hash diverge.
        $computed = hash('sha256', $token . '-' . $rawBody);

        return hash_equals($computed, $signature);
    }

    public function extractChargeId(string $rawBody, array $query): ?string
    {
        $payload = json_decode($rawBody, true);

        if (is_array($payload) && isset($payload['qr_codes'][0]['id'])) {
            return (string)$payload['qr_codes'][0]['id'];
        }

        return null;
    }

    public function extractPaidAmountCents(string $rawBody): ?int
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return null;
        }

        $value = $payload['charges'][0]['amount']['value'] ?? $payload['qr_codes'][0]['amount']['value'] ?? null;

        return is_numeric($value) ? (int)$value : null;
    }

    public function extractTransactionNsu(string $rawBody): ?string
    {
        $payload = json_decode($rawBody, true);

        $id = is_array($payload) ? ($payload['charges'][0]['id'] ?? null) : null;

        return (is_string($id) && $id !== '') ? $id : null;
    }

    public function fetchStatus(string $gatewayChargeId): string
    {
        $token = $this->token();

        [$httpCode, $response] = $this->request(
            'GET',
            $this->apiBase() . '/orders/' . rawurlencode($gatewayChargeId),
            null,
            ['Authorization: Bearer ' . $token]
        );

        // A doc oficial nao garante o comportamento de GET /orders/{id} antes do
        // pagamento; relatos da comunidade indicam 404 enquanto pendente. Tratamos
        // isso como 'pendente', nao como erro — evita logar warning para o caso comum.
        if ($httpCode === 404) {
            return 'pendente';
        }

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($response)) {
            Logger::getInstance()->warning('PagBankGateway::fetchStatus falhou', [
                'gateway_charge_id' => $gatewayChargeId,
                'http_code'         => $httpCode,
            ]);
            return 'erro';
        }

        $status = $response['charges'][0]['status'] ?? null;

        return match ($status) {
            'PAID'                  => 'pago',
            'DECLINED', 'CANCELED'  => 'expirado',
            default                 => 'pendente',
        };
    }

    private function webhookUrl(): string
    {
        return rtrim(canonical_url('SITE_CANONICAL_URL'), '/') . '/webhook/pix/pagbank';
    }

    private function expirationIso(string $expiresAt): string
    {
        $date = new DateTime($expiresAt);
        return $date->format('Y-m-d\TH:i:sP');
    }

    /**
     * Baixa o PNG do QR Code a partir do link `rel = "QRCODE.PNG"` e devolve em base64.
     * Falha ao baixar nao derruba a cobranca — apenas fica sem imagem (qr_payload
     * continua disponivel para o comprador copiar e colar).
     *
     * Tipado como `mixed[]` (nao a forma exata `{rel, href}`) porque vem direto do
     * json_decode() da resposta do PagBank — um payload malformado nao pode
     * quebrar a criacao da cobranca, so deixar a imagem do QR sem valor.
     *
     * @param array<mixed> $links
     */
    private function downloadQrImage(array $links, string $token): ?string
    {
        $href = null;
        foreach ($links as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'QRCODE.PNG' && !empty($link['href'])) {
                $href = (string)$link['href'];
                break;
            }
        }

        if ($href === null) {
            return null;
        }

        $ch = curl_init($href);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300 || !is_string($raw) || $raw === '') {
            Logger::getInstance()->warning('PagBankGateway: falha ao baixar QR Code PNG', [
                'http_code' => $httpCode,
            ]);
            return null;
        }

        return base64_encode($raw);
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return (string)$value;
            }
        }
        return null;
    }

    /**
     * @return array{0: int, 1: ?array}
     */
    private function request(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL para o PagBank');
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
            // Nunca logar token/header Authorization nem corpo cru da resposta.
            Logger::getInstance()->error('PagBankGateway: falha de rede', [
                'url_path' => parse_url($url, PHP_URL_PATH),
                'error'    => $curlError,
            ]);
            throw new RuntimeException('Falha de rede ao comunicar com o PagBank');
        }

        $decoded = json_decode((string)$raw, true);

        return [$httpCode, is_array($decoded) ? $decoded : null];
    }
}
