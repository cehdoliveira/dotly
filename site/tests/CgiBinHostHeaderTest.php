<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regressao do bug corrigido no plano 027: manager/cgi-bin/kafka_email_worker.php
 * simulava $_SERVER["HTTP_HOST"] com o host do SITE em vez do proprio, e o
 * kernel.php mata o processo com "Invalid host header" quando ALLOWED_HOSTS
 * nao bate — o worker nunca conseguia rodar. Esta suite garante que nenhum
 * script cgi-bin do SITE hardcoda um host fora do ALLOWED_HOSTS deste ambiente.
 */
final class CgiBinHostHeaderTest extends TestCase
{
    public function testEveryCgiBinScriptHardcodesAnAllowedHost(): void
    {
        $allowedHosts = array_map('trim', explode(',', constant('ALLOWED_HOSTS')));
        $scripts = glob(__DIR__ . '/../cgi-bin/*.php');
        $this->assertNotEmpty($scripts, 'Nenhum script cgi-bin encontrado — glob quebrado?');

        foreach ($scripts as $script) {
            $source = file_get_contents($script);
            if (!preg_match('/\$_SERVER\[\s*["\']HTTP_HOST["\']\s*\]\s*=\s*["\']([^"\']+)["\']/', $source, $m)) {
                continue;
            }

            $this->assertContains(
                $m[1],
                $allowedHosts,
                basename($script) . " hardcoda HTTP_HOST=\"{$m[1]}\", que nao esta em ALLOWED_HOSTS "
                    . '(' . constant('ALLOWED_HOSTS') . ") — o kernel.php vai matar o processo com \"Invalid host header\"."
            );
        }
    }
}
