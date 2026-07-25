<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre o plano 014: SESSION_LIFETIME deixa de ser uma constante morta e passa a
 * ser o timeout de INATIVIDADE aplicado em app/inc/main.php. main.php nao e
 * reexecutavel dentro do PHPUnit (faz include de kernel/urls e assume
 * $_SERVER["DOCUMENT_ROOT"] de producao), entao este teste nao o inclui — ele
 * confirma os fatos verificaveis sem executar o request: a constante existe e e
 * positiva, SESSION_USE_REDIS nao existe mais, e a expressao de decisao usada em
 * main.php ((time() - $last) > $lifetime) se comporta corretamente nas fronteiras.
 *
 * Se a expressao mudar em app/inc/main.php, atualize testRegraDeExpiracaoPorInatividade().
 */
final class SessionTimeoutTest extends TestCase
{
    public function testConstanteDeLifetimeExisteEEhPositiva(): void
    {
        $this->assertTrue(defined('SESSION_LIFETIME'), 'SESSION_LIFETIME precisa existir no kernel.php');
        $this->assertGreaterThan(0, constant('SESSION_LIFETIME'), 'SESSION_LIFETIME precisa ser > 0 para o timeout valer');
    }

    public function testSessionUseRedisNaoExisteMais(): void
    {
        $this->assertFalse(defined('SESSION_USE_REDIS'), 'SESSION_USE_REDIS foi removida — nao existe sessao em Redis');
    }

    public function testRegraDeExpiracaoPorInatividade(): void
    {
        $lifetime = 7200;
        $now = time();

        // Espelha app/inc/main.php: $_session_last !== null && (time() - $_session_last) > $_session_lifetime
        $expira = static function (?int $last) use ($now, $lifetime): bool {
            return $last !== null && ($now - $last) > $lifetime;
        };

        $this->assertTrue($expira($now - 7201), 'inatividade acima do limite deve expirar');
        $this->assertFalse($expira($now - 7199), 'inatividade abaixo do limite nao deve expirar');
        $this->assertFalse($expira(null), 'sessao sem _last_activity (primeira requisicao) nao deve expirar');
    }
}
