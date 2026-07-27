<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre Crypto::encrypt()/decrypt() (AES-256-GCM, plano 026) isoladamente do
 * banco — nao estende DBTestCase, nao precisa de kernel.php com
 * APP_ENCRYPTION_KEY valido (os testes passam $key explicitamente).
 */
final class CryptoTest extends TestCase
{
    private function randomKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function plaintextProvider(): array
    {
        return [
            'string curta'        => ['ola'],
            'utf-8 acentuado'      => ['crédito e débito não são a mesma coisa — ção'],
            'string longa (4 KB)' => [str_repeat('x', 4096)],
        ];
    }

    /**
     * @dataProvider plaintextProvider
     */
    public function testRoundTrip(string $plaintext): void
    {
        $key = $this->randomKey();
        $encrypted = Crypto::encrypt($plaintext, $key);

        $this->assertSame($plaintext, Crypto::decrypt($encrypted, $key));
    }

    public function testTwoEncryptionsOfSameTextProduceDifferentPayloads(): void
    {
        $key = $this->randomKey();
        $plaintext = 'mesmo texto';

        $first = Crypto::encrypt($plaintext, $key);
        $second = Crypto::encrypt($plaintext, $key);

        $this->assertNotSame($first, $second, 'IV precisa ser aleatorio a cada encrypt()');
        $this->assertSame($plaintext, Crypto::decrypt($first, $key));
        $this->assertSame($plaintext, Crypto::decrypt($second, $key));
    }

    public function testDecryptTamperedPayloadReturnsNull(): void
    {
        $key = $this->randomKey();
        $encrypted = Crypto::encrypt('dado integro', $key);

        $raw = base64_decode($encrypted, true);
        $this->assertIsString($raw);

        // Flipa 1 bit bem no meio do payload (dentro do ciphertext, apos
        // iv[12]+tag[16]) — a tag do GCM deixa de bater e decrypt() falha.
        $tamperIndex = (int) (strlen($raw) / 2);
        $raw[$tamperIndex] = chr(ord($raw[$tamperIndex]) ^ 1);
        $tampered = base64_encode($raw);

        $this->assertNull(Crypto::decrypt($tampered, $key));
    }

    public function testDecryptNonBase64PayloadReturnsNull(): void
    {
        $key = $this->randomKey();

        $this->assertNull(Crypto::decrypt('isto nao e base64 !!! ###', $key));
    }

    public function testDecryptWithDifferentKeyReturnsNull(): void
    {
        $encrypted = Crypto::encrypt('segredo', $this->randomKey());

        $this->assertNull(Crypto::decrypt($encrypted, $this->randomKey()));
    }

    public function testMissingOrTooShortKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Crypto::decrypt('qualquer-coisa', 'chave-curta-demais');
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Crypto::encrypt('texto', '');
    }
}
