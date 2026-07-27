<?php

/**
 * Cifra simetrica autenticada (AES-256-GCM) para dados sensiveis em repouso
 * (plano 026: credenciais dos gateways de pagamento em
 * payment_gateways.credentials_enc).
 *
 * Formato do payload retornado por encrypt(): base64(iv[12] || tag[16] || ciphertext).
 * IV e tag ficam junto do ciphertext (nao sao segredo) — so a chave precisa
 * ficar fora do banco. Um dump do banco sozinho nao decifra nada.
 *
 * A chave (APP_ENCRYPTION_KEY) mora no kernel.php dos DOIS ambientes (manager
 * cifra, site decifra) e NUNCA no banco — se morasse, a cifra seria decorativa
 * (quem tem o dump teria a chave junto).
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    /** Cifra $plaintext. Retorna base64(iv || tag || ciphertext). */
    public static function encrypt(string $plaintext, ?string $key = null): string
    {
        $binaryKey = self::resolveKey($key);
        $iv = random_bytes(self::IV_LEN);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $binaryKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        return base64_encode($iv . $tag . (string)$ciphertext);
    }

    /** Decifra. Retorna null se o payload for invalido, adulterado ou de outra chave. */
    public static function decrypt(string $payload, ?string $key = null): ?string
    {
        $binaryKey = self::resolveKey($key);

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $binaryKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Chave binaria de 32 bytes. Default: base64_decode(APP_ENCRYPTION_KEY).
     * @throws RuntimeException se a constante faltar, nao for base64 valido
     *         ou nao decodificar para exatamente 32 bytes.
     */
    private static function resolveKey(?string $key): string
    {
        $encoded = $key ?? (defined('APP_ENCRYPTION_KEY') ? (string)constant('APP_ENCRYPTION_KEY') : '');

        if ($encoded === '') {
            throw new RuntimeException('APP_ENCRYPTION_KEY ausente ou invalida (esperado base64 de 32 bytes)');
        }

        $binary = base64_decode($encoded, true);
        if ($binary === false || strlen($binary) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY ausente ou invalida (esperado base64 de 32 bytes)');
        }

        return $binary;
    }
}
