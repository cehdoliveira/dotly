<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre ui/page/order_label.php: a etiqueta de envio (padrão Correios) gerada
 * por orders_controller::label(). Página standalone (fora do layout do
 * manager), então só depende de $order + $GLOBALS['order_url'] + constante
 * cTitle. Garante que os dados do destinatário aparecem formatados e que
 * nenhum valor cru escapa sem htmlspecialchars.
 */
final class OrderLabelViewTest extends TestCase
{
    /** @var array<string,mixed> backup das entradas de $GLOBALS usadas pela view */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->globalsBackup['order_url'] = $GLOBALS['order_url'] ?? null;
        $GLOBALS['order_url'] = '/pedidos/%d';
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function order(array $overrides = []): array
    {
        return array_merge([
            'idx' => 42, 'token' => 'abc123def456', 'customer_name' => 'Fulano de Tal',
            'customer_phone' => '11999998888', 'ship_zip' => '01310100',
            'ship_street' => 'Av. Paulista', 'ship_number' => '1000', 'ship_complement' => 'Sala 5',
            'ship_district' => 'Bela Vista', 'ship_city' => 'São Paulo', 'ship_uf' => 'SP',
        ], $overrides);
    }

    private function render(array $order): string
    {
        ob_start();
        try {
            (function () use ($order) {
                include dirname(__DIR__) . '/public_html/ui/page/order_label.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }

    private function renderStrict(array $order): string
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            return $this->render($order);
        } finally {
            restore_error_handler();
        }
    }

    public function testRendersRecipientBlockInCorreiosFormat(): void
    {
        $html = $this->renderStrict($this->order());

        $this->assertStringContainsString('Destinatário', $html);
        $this->assertStringContainsString('FULANO DE TAL', $html, 'nome do destinatário em maiúsculas');
        $this->assertStringContainsString('Av. Paulista, 1000', $html, 'logradouro + número');
        $this->assertStringContainsString('Sala 5', $html, 'complemento');
        $this->assertStringContainsString('Bela Vista', $html, 'bairro');
        $this->assertStringContainsString('01310-100', $html, 'CEP formatado');
        $this->assertStringContainsString('São Paulo - SP', $html, 'cidade/UF (maiúsculas via CSS, não no HTML)');
        $this->assertStringContainsString('Pedido #42', $html, 'referência do pedido para conferência');
    }

    public function testOmitsComplementLineWhenEmpty(): void
    {
        $with = $this->renderStrict($this->order(['ship_complement' => 'Apto 12']));
        $this->assertStringContainsString('Apto 12', $with, 'complemento aparece quando informado');

        $without = $this->renderStrict($this->order(['ship_complement' => '']));
        $this->assertStringNotContainsString('Apto 12', $without, 'complemento vazio não renderiza a linha');
        $this->assertStringContainsString('01310-100', $without, 'CEP continua presente');
    }

    /**
     * Regressao: a CSP do manager (script-src 'self' 'nonce-...') bloqueia
     * handlers inline como onclick — o botao Imprimir nao pode depender de
     * onclick. Ele usa um <script nonce> com addEventListener.
     */
    public function testPrintButtonIsCspSafe(): void
    {
        $GLOBALS['cspNonce'] = 'nonce-teste';
        try {
            $html = $this->renderStrict($this->order());
        } finally {
            unset($GLOBALS['cspNonce']);
        }

        $this->assertStringNotContainsString('onclick=', $html, 'sem handler inline (bloqueado pela CSP)');
        $this->assertStringContainsString('id="label-print"', $html, 'botão tem id para o listener');
        $this->assertStringContainsString('nonce="nonce-teste"', $html, 'script carrega o nonce da CSP');
        $this->assertStringContainsString('addEventListener', $html, 'listener registrado via script');
    }

    public function testEscapesRecipientDataToPreventXss(): void
    {
        $html = $this->render($this->order(['customer_name' => '<script>alert(1)</script>']));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;SCRIPT&gt;ALERT(1)&lt;/SCRIPT&gt;', $html, 'nome escapado e em maiúsculas');
    }
}
