<?php

/**
 * Leitura/escrita/validacao das credenciais de gateway de pagamento (plano 026),
 * guardadas cifradas em payment_gateways.credentials_enc (ver Crypto).
 *
 * Cache estatico por request: sem ele, GatewayRouter::pick() faria uma query por
 * gateway e cada adapter mais uma — save()/clear() invalidam a entrada do slug
 * afetado.
 */
final class GatewayCredentials
{
    /**
     * Campos de credencial por slug de gateway. 'secret' => true mascara o
     * valor na UI e usa <input type="password">. 'required' => true entra na
     * conta de isComplete() — gateway incompleto nao entra no sorteio do
     * GatewayRouter.
     *
     * Adicionar um 4o PSP = 1 entrada aqui + 1 classe implementando PixGateway
     * + 1 INSERT IGNORE numa migration. Esta classe nao muda.
     *
     * @var array<string, array<string, array{label:string, secret:bool, required:bool}>>
     */
    public const SCHEMA = [
        'mercadopago' => [
            'access_token'   => ['label' => 'Access Token',   'secret' => true,  'required' => true],
            'webhook_secret' => ['label' => 'Webhook Secret', 'secret' => true,  'required' => true],
        ],
        'pagbank' => [
            'api_base' => ['label' => 'URL base da API (sandbox ou producao)', 'secret' => false, 'required' => true],
            'token'    => ['label' => 'Token',                                 'secret' => true,  'required' => true],
        ],
        'infinitepay' => [
            'handle' => ['label' => 'Handle', 'secret' => false, 'required' => true],
        ],
    ];

    /** @var array<string, array<string,string>> */
    private static array $cache = [];

    /** @return array<string,string> campos preenchidos; [] se nao houver credencial */
    public static function get(string $slug): array
    {
        if (array_key_exists($slug, self::$cache)) {
            return self::$cache[$slug];
        }

        $values = self::load($slug);
        self::$cache[$slug] = $values;

        return $values;
    }

    /** @return array<string,string> */
    private static function load(string $slug): array
    {
        $model = new payment_gateways_model();
        $model->set_field([" credentials_enc "]);
        $model->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $row = $model->data[0] ?? null;
        $encrypted = $row['credentials_enc'] ?? null;

        if ($encrypted === null || $encrypted === '') {
            return [];
        }

        $json = Crypto::decrypt((string)$encrypted);
        if ($json === null) {
            Logger::getInstance()->warning('GatewayCredentials: credencial ilegivel', ['slug' => $slug]);
            return [];
        }

        $values = json_decode($json, true);
        if (!is_array($values)) {
            Logger::getInstance()->warning('GatewayCredentials: credencial ilegivel', ['slug' => $slug]);
            return [];
        }

        $result = [];
        foreach ($values as $field => $value) {
            if (is_string($field) && is_string($value)) {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,string> $values campo => valor; string vazia = manter o valor atual */
    public static function save(string $slug, array $values): void
    {
        if (!isset(self::SCHEMA[$slug])) {
            throw new RuntimeException('gateway desconhecido');
        }

        $current = self::get($slug);
        $merged = $current;

        foreach (self::SCHEMA[$slug] as $field => $def) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if ($value !== '') {
                $merged[$field] = $value;
            }
        }

        $model = new payment_gateways_model();
        $model->set_filter(["slug = ?"], [$slug]);
        $model->populate([
            'credentials_enc' => Crypto::encrypt(json_encode($merged, JSON_THROW_ON_ERROR)),
        ]);
        $model->save();

        self::$cache[$slug] = $merged;
    }

    /** Apaga todas as credenciais do gateway (credentials_enc = NULL). */
    public static function clear(string $slug): void
    {
        if (!isset(self::SCHEMA[$slug])) {
            throw new RuntimeException('gateway desconhecido');
        }

        $model = new payment_gateways_model();
        $model->set_filter(["slug = ?"], [$slug]);
        $model->populate(['credentials_enc' => null]);
        $model->save();

        self::$cache[$slug] = [];
    }

    /** True se todos os campos required do slug estao preenchidos. */
    public static function isComplete(string $slug): bool
    {
        if (!isset(self::SCHEMA[$slug])) {
            return false;
        }

        $values = self::get($slug);

        // Passa por schemaFor() (nao self::SCHEMA[$slug] direto): o retorno tipado
        // como bool generico evita que o PHPStan trate 'required' como sempre-true
        // so porque, HOJE, todo campo do SCHEMA e obrigatorio — 'required' => false
        // e um valor valido do formato, so nao usado ainda.
        foreach (self::schemaFor($slug) as $field => $def) {
            if ($def['required'] && trim((string)($values[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{label:string, secret:bool, required:bool}>
     */
    private static function schemaFor(string $slug): array
    {
        return self::SCHEMA[$slug] ?? [];
    }

    /** @return array<string,string> campo => '••••1234' (4 ultimos) ou '' se vazio. NUNCA valor em claro. */
    public static function masked(string $slug): array
    {
        if (!isset(self::SCHEMA[$slug])) {
            return [];
        }

        $values = self::get($slug);
        $result = [];

        foreach (self::SCHEMA[$slug] as $field => $def) {
            $value = (string)($values[$field] ?? '');

            if ($value === '') {
                $result[$field] = '';
                continue;
            }

            if (!$def['secret']) {
                $result[$field] = $value;
                continue;
            }

            $result[$field] = mb_strlen($value) <= 4
                ? str_repeat('•', mb_strlen($value))
                : str_repeat('•', 4) . mb_substr($value, -4);
        }

        return $result;
    }

    /** Zera o cache estatico. Uso: testes. */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
