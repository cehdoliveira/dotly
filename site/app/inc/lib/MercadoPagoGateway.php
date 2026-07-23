<?php

/**
 * Adapter Mercado Pago — modo `qr` (PIX inline via POST /v1/payments).
 *
 * Verificado contra a documentacao oficial (Checkout API / "Integrar Pix", referencia
 * "Create payment") em julho/2026. Sem SDK — cURL nativo apenas.
 *
 * Credenciais: MP_ACCESS_TOKEN, MP_WEBHOOK_SECRET (kernel.php, fail-closed se vazias).
 */
class MercadoPagoGateway implements PixGateway
{
    private const API_BASE = 'https://api.mercadopago.com';
    private const HTTP_TIMEOUT = 10;
    private const HTTP_CONNECT_TIMEOUT = 5;

    private function accessToken(): string
    {
        $token = defined('MP_ACCESS_TOKEN') ? (string)constant('MP_ACCESS_TOKEN') : '';
        if ($token === '') {
            throw new RuntimeException('MP_ACCESS_TOKEN nao configurado');
        }
        return $token;
    }

    public function createCharge(array $order, array $items): array
    {
        $token = $this->accessToken();

        $body = [
            'transaction_amount' => round(((int)$order['total_cents']) / 100, 2),
            'description'        => 'Pedido ' . (string)$order['token'],
            'payment_method_id'  => 'pix',
            'payer'              => [
                'email'          => (string)$order['customer_mail'],
                'first_name'     => (string)$order['customer_name'],
                'identification' => [
                    'type'   => 'CPF',
                    'number' => (string)$order['customer_cpf'],
                ],
            ],
            'notification_url'   => $this->webhookUrl(),
            'date_of_expiration' => $this->expirationIso((string)$order['expires_at']),
        ];

        [$httpCode, $response] = $this->request(
            'POST',
            self::API_BASE . '/v1/payments',
            $body,
            [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'X-Idempotency-Key: ' . (string)$order['token'],
            ]
        );

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($response)) {
            Logger::getInstance()->error('MercadoPagoGateway::createCharge falhou', [
                'orders_id' => $order['idx'] ?? null,
                'http_code' => $httpCode,
            ]);
            throw new RuntimeException('Falha ao criar cobranca PIX no Mercado Pago');
        }

        $chargeId = isset($response['id']) ? (string)$response['id'] : '';
        if ($chargeId === '') {
            Logger::getInstance()->error('MercadoPagoGateway::createCharge sem id na resposta', [
                'orders_id' => $order['idx'] ?? null,
                'http_code' => $httpCode,
            ]);
            throw new RuntimeException('Resposta do Mercado Pago sem id de cobranca');
        }

        $transactionData = $response['point_of_interaction']['transaction_data'] ?? [];

        return [
            'gateway_charge_id' => $chargeId,
            'qr_payload'        => $transactionData['qr_code'] ?? null,
            'qr_image_base64'   => $transactionData['qr_code_base64'] ?? null,
            'redirect_url'      => null,
            'expires_at'        => (string)$order['expires_at'],
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $query = []): bool
    {
        $secret = defined('MP_WEBHOOK_SECRET') ? (string)constant('MP_WEBHOOK_SECRET') : '';
        if ($secret === '') {
            Logger::getInstance()->error('MP_WEBHOOK_SECRET nao configurado — recusando webhook');
            return false;
        }

        $signatureHeader = $this->headerValue($headers, 'x-signature');
        $requestId       = $this->headerValue($headers, 'x-request-id');

        if ($signatureHeader === null || $requestId === null) {
            return false;
        }

        $ts = null;
        $hash = null;
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = array_pad(explode('=', trim($part), 2), 2, null);
            if ($pair[0] === 'ts') {
                $ts = $pair[1];
            }
            if ($pair[0] === 'v1') {
                $hash = $pair[1];
            }
        }

        if ($ts === null || $hash === null) {
            return false;
        }

        // O manifest do x-signature exige o data.id da query string da
        // notificacao (doc oficial), nao do body — ver PixGateway::verifyWebhook.
        $dataId = $this->extractChargeId($rawBody, $query);
        if ($dataId === null) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $computed = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($computed, $hash);
    }

    public function extractChargeId(string $rawBody, array $query): ?string
    {
        $payload = json_decode($rawBody, true);
        $id = null;

        if (is_array($payload) && isset($payload['data']['id'])) {
            $id = $payload['data']['id'];
        } elseif (isset($query['data_id'])) {
            // PHP troca "." por "_" nas chaves de $_GET — a notificacao do MP
            // chega como ?data.id=X, mas webhook_controller::receive() repassa
            // $_GET direto, entao a chave real aqui e "data_id", nunca "data.id".
            $id = $query['data_id'];
        } elseif (isset($query['data.id'])) {
            $id = $query['data.id'];
        } elseif (isset($query['id'])) {
            $id = $query['id'];
        }

        return is_scalar($id) ? (string)$id : null;
    }

    public function extractPaidAmountCents(string $rawBody): ?int
    {
        // O payload de notificacao do Mercado Pago so traz o id do pagamento
        // (ver extractChargeId) — o valor so e conhecido consultando a API, o
        // que fetchStatus() ja faz contra a cobranca especifica antes de marcar
        // como pago.
        return null;
    }

    /**
     * Sempre null: o gateway_charge_id do MP JA E o payment_id (cobranca criada
     * via POST /v1/payments) — o NSU-equivalente ja esta persistido, ver docblock
     * da interface PixGateway::extractTransactionNsu().
     */
    public function extractTransactionNsu(string $rawBody): ?string
    {
        return null;
    }

    public function fetchStatus(string $gatewayChargeId): string
    {
        $token = $this->accessToken();

        [$httpCode, $response] = $this->request(
            'GET',
            self::API_BASE . '/v1/payments/' . rawurlencode($gatewayChargeId),
            null,
            ['Authorization: Bearer ' . $token]
        );

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($response)) {
            Logger::getInstance()->warning('MercadoPagoGateway::fetchStatus falhou', [
                'gateway_charge_id' => $gatewayChargeId,
                'http_code'         => $httpCode,
            ]);
            return 'erro';
        }

        $status = $response['status'] ?? null;

        return match ($status) {
            'approved'            => 'pago',
            'cancelled', 'rejected' => 'expirado',
            default                => 'pendente',
        };
    }

    private function webhookUrl(): string
    {
        return rtrim(canonical_url('SITE_CANONICAL_URL'), '/') . '/webhook/pix/mercadopago';
    }

    private function expirationIso(string $expiresAt): string
    {
        $date = new DateTime($expiresAt);
        return $date->format('Y-m-d\TH:i:sP');
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
            throw new RuntimeException('Falha ao inicializar cURL para o Mercado Pago');
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
            Logger::getInstance()->error('MercadoPagoGateway: falha de rede', [
                'url_path' => parse_url($url, PHP_URL_PATH),
                'error'    => $curlError,
            ]);
            throw new RuntimeException('Falha de rede ao comunicar com o Mercado Pago');
        }

        $decoded = json_decode((string)$raw, true);

        return [$httpCode, is_array($decoded) ? $decoded : null];
    }
}
