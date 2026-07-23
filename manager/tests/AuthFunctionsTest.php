<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre as funcoes puras (ou so-DB, sem exit) de auth em CommonFunctions.php:
 * verify_password_with_migration, o fallback em arquivo de
 * check_and_increment_rate_limit, e o caminho de aceite de validate_csrf.
 *
 * O caminho de rejeicao de validate_csrf chama basic_redir() -> exit() e nao
 * e coberto aqui (ver plano 023, Step 3).
 */
final class AuthFunctionsTest extends DBTestCase
{
    /** @var string[] chaves de rate limit usadas no teste, para limpeza no tearDown */
    private array $rateLimitKeys = [];

    /** @var mixed backup do $_SESSION original, para restaurar no tearDown */
    private $sessionBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rateLimitKeys = [];
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->rateLimitKeys as $key) {
            reset_rate_limit(null, $key);
        }

        $_SESSION = $this->sessionBackup ?? [];

        parent::tearDown();
    }

    private function createUser(string $password): int
    {
        $model = new users_model();
        $model->populate([
            'name'     => 'Auth Test User',
            'mail'     => 'auth_test_' . uniqid() . '@example.com',
            'login'    => 'auth_test_' . uniqid(),
            'password' => $password,
        ]);
        $id = $model->save();
        $this->assertGreaterThan(0, $id, 'Insert deve retornar um ID valido');

        return $id;
    }

    private function loadPassword(int $id): string
    {
        $model = new users_model();
        $model->set_field([" idx ", " password "]);
        $model->set_filter(["idx = ?"], [$id]);
        $model->set_paginate([1]);
        $model->load_data();

        return $model->data[0]['password'];
    }

    public function testVerifyPasswordBcryptCorrectDoesNotAlterHash(): void
    {
        $hash = password_hash('senha-correta', PASSWORD_BCRYPT);
        $id = $this->createUser($hash);

        $result = verify_password_with_migration($hash, 'senha-correta', (string)$id);

        $this->assertTrue($result);
        $this->assertSame($hash, $this->loadPassword($id));
    }

    public function testVerifyPasswordBcryptIncorrectReturnsFalse(): void
    {
        $hash = password_hash('senha-correta', PASSWORD_BCRYPT);
        $id = $this->createUser($hash);

        $result = verify_password_with_migration($hash, 'senha-errada', (string)$id);

        $this->assertFalse($result);
        $this->assertSame($hash, $this->loadPassword($id));
    }

    public function testVerifyPasswordLegacyMd5CorrectMigratesToBcrypt(): void
    {
        $legacyHash = md5('senha-legado');
        $id = $this->createUser($legacyHash);

        $result = verify_password_with_migration($legacyHash, 'senha-legado', (string)$id);

        $this->assertTrue($result);

        $reloaded = $this->loadPassword($id);
        $this->assertNotSame($legacyHash, $reloaded);
        $this->assertNotSame('unknown', password_get_info($reloaded)['algoName']);
        $this->assertTrue(password_verify('senha-legado', $reloaded));
    }

    public function testVerifyPasswordLegacyMd5IncorrectReturnsFalseAndKeepsHash(): void
    {
        $legacyHash = md5('senha-legado');
        $id = $this->createUser($legacyHash);

        $result = verify_password_with_migration($legacyHash, 'senha-errada', (string)$id);

        $this->assertFalse($result);
        $this->assertSame($legacyHash, $this->loadPassword($id));
    }

    public function testVerifyPasswordInputTooLongReturnsFalse(): void
    {
        $legacyHash = md5('senha-legado');
        $id = $this->createUser($legacyHash);

        $tooLong = str_repeat('a', 1025);
        $result = verify_password_with_migration($legacyHash, $tooLong, (string)$id);

        $this->assertFalse($result);
        $this->assertSame($legacyHash, $this->loadPassword($id));
    }

    private function rateLimitKey(string $suffix): string
    {
        $key = 'auth-functions-test-' . $suffix . '-' . uniqid();
        $this->rateLimitKeys[] = $key;

        return $key;
    }

    public function testRateLimitFallbackUnderLimitReturnsFalse(): void
    {
        $key = $this->rateLimitKey('under');

        $this->assertFalse(check_and_increment_rate_limit(null, $key, 3, 60));
        $this->assertFalse(check_and_increment_rate_limit(null, $key, 3, 60));
        $this->assertFalse(check_and_increment_rate_limit(null, $key, 3, 60));
    }

    public function testRateLimitFallbackOverLimitReturnsTrue(): void
    {
        $key = $this->rateLimitKey('over');

        check_and_increment_rate_limit(null, $key, 2, 60);
        check_and_increment_rate_limit(null, $key, 2, 60);
        $blocked = check_and_increment_rate_limit(null, $key, 2, 60);

        $this->assertTrue($blocked);
    }

    public function testRateLimitFallbackWindowExpiryResets(): void
    {
        $key = $this->rateLimitKey('window');
        $max = 2;
        $window = 60;

        // Excede o limite dentro da janela atual.
        check_and_increment_rate_limit(null, $key, $max, $window);
        check_and_increment_rate_limit(null, $key, $max, $window);
        $this->assertTrue(check_and_increment_rate_limit(null, $key, $max, $window));

        // Simula a janela expirada reescrevendo o arquivo de lock diretamente,
        // sem esperar o tempo real passar.
        $dir = ratelimit_fallback_dir();
        $file = $dir . '/' . md5($key) . '.lock';
        file_put_contents($file, json_encode([
            'count'        => $max + 5,
            'window_start' => time() - ($window + 1),
        ]));

        // Nova janela: conta reseta para 1, abaixo do limite.
        $this->assertFalse(check_and_increment_rate_limit(null, $key, $max, $window));
    }

    public function testValidateCsrfAcceptConsumesToken(): void
    {
        $token = random_token();
        $_SESSION['_csrf_token'] = $token;
        unset($_SESSION['_csrf_used']);

        validate_csrf($token, '/x');

        $this->assertArrayNotHasKey('_csrf_token', $_SESSION);
        $this->assertArrayHasKey($token, $_SESSION['_csrf_used'] ?? []);
    }
}
