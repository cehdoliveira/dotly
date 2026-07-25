<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Plano 019: rota inexistente deve responder 404 de verdade em vez de
 * redirecionar pra home (soft 404 via basic_redir($home_url)).
 *
 * O index.php nao e exercitavel em PHPUnit — faz session_start(), header() e
 * exit direto no escopo global (mesma limitacao documentada em
 * LoginActiveFilterTest / CheckoutStockTest para os fluxos que dependem do
 * front controller). Por isso os testes aqui cobrem o que da pra verificar
 * sem servidor HTTP: a pagina de 404 existe e nao tem erro de sintaxe, e o
 * fallback no fonte do index.php usa http_response_code(404) em vez de
 * basic_redir($home_url). A verificacao de comportamento real (404/200/302
 * via curl) fica fora do PHPUnit — ver relatorio do plano 019.
 */
final class NotFoundTest extends TestCase
{
    public function testPaginaDeNotFoundExiste(): void
    {
        $path = constant("cRootServer") . "ui/page/not_found.php";
        $this->assertFileExists($path);
    }

    public function testPaginaDeNotFoundNaoTemErroDeSintaxe(): void
    {
        $path = constant("cRootServer") . "ui/page/not_found.php";
        exec('php -l ' . escapeshellarg($path), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testIndexNaoRedirecionaMaisRotaInexistenteParaHome(): void
    {
        $source = file_get_contents(__DIR__ . '/../public_html/index.php');
        $this->assertIsString($source);

        $this->assertStringContainsString('http_response_code(404)', $source);

        // O fallback do dispatcher (bloco apos "if (!$dispatcher->exec())") nao
        // pode mais chamar basic_redir($home_url) — essa era a regressao exata
        // que o plano 019 corrige (soft 404: 302 + home pra qualquer URL invalida).
        $fallbackStart = strpos($source, 'if (!$dispatcher->exec())');
        $this->assertIsInt($fallbackStart, 'fallback do dispatcher nao encontrado no index.php');

        $fallbackBlock = substr($source, $fallbackStart);
        $this->assertStringNotContainsString('basic_redir($home_url)', $fallbackBlock);
    }
}
