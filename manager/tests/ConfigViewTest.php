<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre a renderizacao de ui/page/config.php: ConfigActionTest so cobre a logica de
 * escrita (perfil/senha/gateway), nunca o render em si — mesma lacuna que
 * OrdersViewTest/SalesDashboardViewTest cobrem nas views delas. Sem isso, um
 * htmlspecialchars() esquecido nos inputs da conta so apareceria em producao.
 *
 * TestCase puro (nao DBTestCase): a view so consome os arrays/escalares ja montados pelo
 * controller ($user, $gateways), passados aqui como fixture PHP direta.
 */
final class ConfigViewTest extends TestCase
{
    /** @var mixed */
    private $sessionBackup = null;

    /** @var array<string,mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [
            constant("cAppKey") => ["credential" => ["name" => "Admin Teste", "idx" => 1]],
            "_csrf_token"       => "tok-fixture",
        ];

        $urls = [
            'home_url' => '/', 'customers_url' => '/clientes', 'products_url' => '/produtos',
            'orders_url' => '/pedidos', 'logout_url' => '/sair', 'config_url' => '/config', 'config_users_url' => '/config/usuarios', 'config_cep_url' => '/config/cep/', 'customer_url' => '/clientes/%d', 'order_url' => '/pedidos/%d',
        ];
        foreach ($urls as $key => $value) {
            $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
            $GLOBALS[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup ?? [];
        foreach ($this->globalsBackup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $user
     * @param array<int,array<string,mixed>> $gateways
     * @param array<string,string>|null $senderSettings null (default) deixa a
     *     variavel ausente na view, que ja tem `$senderSettings ?? [...8 chaves
     *     vazias...]` — `null` passa pelo `??` do mesmo jeito que "variavel nao
     *     definida", entao os testes existentes (que nunca passam esse 3o
     *     parametro) continuam se comportando exatamente como antes.
     */
    private function render(array $user = [], array $gateways = [], ?array $senderSettings = null): string
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE | E_DEPRECATED);

        ob_start();
        try {
            (function () use ($user, $gateways, $senderSettings) {
                include dirname(__DIR__) . '/public_html/ui/page/config.php';
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            restore_error_handler();
            throw $e;
        }
        restore_error_handler();
        return ob_get_clean();
    }

    public function testRendersThreeSections(): void
    {
        $html = $this->render(['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => '']);

        $this->assertStringContainsString('Dados da Conta', $html);
        $this->assertStringContainsString('Alterar Senha', $html);
        $this->assertStringContainsString('Gateways de Pagamento', $html);
        $this->assertStringContainsString('value="admin"', $html, 'campo login deve vir preenchido');
    }

    public function testAccountFieldsAreEscapedToPreventXss(): void
    {
        $html = $this->render([
            'name'  => '"><script>alert(1)</script>',
            'mail'  => 'a@b.com',
            'login' => 'admin',
            'phone' => '',
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'nome deve ser escapado, nunca renderizado cru');
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testEmptyGatewaysShowsEmptyState(): void
    {
        $html = $this->render(['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => ''], []);

        $this->assertStringContainsString('Nenhum gateway cadastrado.', $html);
    }

    public function testGatewayRowRenders(): void
    {
        $html = $this->render(
            ['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => ''],
            [['idx' => 3, 'name' => 'PagBank', 'slug' => 'pagbank', 'mode' => 'qr', 'enabled' => 'yes', 'monthly_limit_cents' => 500000, 'mtd_cents' => 100000, 'usage_pct' => 20.0]]
        );

        $this->assertStringContainsString('PagBank', $html);
        $this->assertStringContainsString('name="idx" value="3"', $html);
        $this->assertStringContainsString('value="5.000,00"', $html, 'limite mensal deve vir formatado no input');
    }

    /**
     * Plano 042: max_order_cents ausente/NULL renderiza "Ilimitado" na celula de
     * exibicao e o input do teto vem vazio (nao "0,00").
     */
    public function testMaxOrderCentsNullRendersIlimitado(): void
    {
        $html = $this->render(
            ['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => ''],
            [['idx' => 3, 'name' => 'PagBank', 'slug' => 'pagbank', 'mode' => 'qr', 'enabled' => 'yes', 'monthly_limit_cents' => 500000, 'max_order_cents' => null, 'mtd_cents' => 100000, 'usage_pct' => 20.0]]
        );

        $this->assertStringContainsString('Ilimitado', $html);
        $this->assertMatchesRegularExpression('/name="max_order_cents"[^>]*value="">/s', $html, 'input do teto deve vir vazio quando max_order_cents e NULL');
    }

    /**
     * Plano 042: max_order_cents com valor renderiza "R$ n.nnn,nn" na celula de
     * exibicao e o input do teto vem pre-preenchido no mesmo formato.
     */
    public function testMaxOrderCentsValueRendersFormatted(): void
    {
        $html = $this->render(
            ['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => ''],
            [['idx' => 3, 'name' => 'PagBank', 'slug' => 'pagbank', 'mode' => 'qr', 'enabled' => 'yes', 'monthly_limit_cents' => 500000, 'max_order_cents' => 250000, 'mtd_cents' => 100000, 'usage_pct' => 20.0]]
        );

        $this->assertStringContainsString('R$ 2.500,00', $html, 'celula de exibicao deve mostrar o teto formatado');
        $this->assertMatchesRegularExpression('/name="max_order_cents"[^>]*value="2\.500,00">/s', $html, 'input do teto deve vir pre-preenchido');
    }

    /**
     * Plano 026: a coluna "Credenciais" mostra o badge de completude e o valor
     * mascarado no placeholder do modal — o valor em claro do campo secret NUNCA
     * pode aparecer no HTML renderizado.
     */
    public function testGatewayCredentialsColumnRendersWithoutLeakingSecret(): void
    {
        $clearTextSecret = 'segredo_super_secreto_nao_pode_vazar';

        $html = $this->render(
            ['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => ''],
            [[
                'idx' => 1, 'name' => 'Mercado Pago', 'slug' => 'mercadopago', 'mode' => 'qr', 'enabled' => 'yes',
                'monthly_limit_cents' => 500000, 'max_order_cents' => null, 'mtd_cents' => 0, 'usage_pct' => 0.0,
                'cred_schema' => [
                    'access_token'   => ['label' => 'Access Token', 'secret' => true, 'required' => true],
                    'webhook_secret' => ['label' => 'Webhook Secret', 'secret' => true, 'required' => true],
                ],
                'cred_masked' => [
                    'access_token'   => '••••' . mb_substr($clearTextSecret, -4),
                    'webhook_secret' => '',
                ],
                'cred_complete' => true,
            ]]
        );

        $this->assertStringContainsString('Credenciais', $html);
        $this->assertStringContainsString('Configuradas', $html, 'gateway completo deve mostrar o badge "Configuradas"');
        $this->assertStringContainsString('gatewayCredsModal1', $html);
        $this->assertStringContainsString('type="password"', $html, 'campo secret deve usar input type=password');
        $this->assertStringNotContainsString($clearTextSecret, $html, 'valor em claro do campo secret nunca pode aparecer no HTML');
        $this->assertStringContainsString('••••' . mb_substr($clearTextSecret, -4), $html, 'placeholder deve mostrar o valor mascarado');

        // Achados da revisao do plano 026 (Frontend/A11y): label do campo de
        // credencial associado ao input via for=/id=, e o botao de fechar do
        // modal nao usa btn-close-white (invisivel no tema claro).
        $this->assertMatchesRegularExpression(
            '/<label class="form-label" for="cred-1-access_token"[^>]*>\s*Access Token/s',
            $html,
            'label do campo access_token deve ter for= apontando pro id= do input correspondente'
        );
        $this->assertStringContainsString('id="cred-1-access_token"', $html);
        $this->assertMatchesRegularExpression(
            '/id="gatewayCredsModal1"[^\x00]*?<button type="button" class="btn-close" data-bs-dismiss="modal"/s',
            $html,
            'botao de fechar do modal de credenciais nao deve usar btn-close-white (invisivel no tema claro)'
        );
    }

    /**
     * Plano 026: gateway sem credencial completa mostra o badge "Faltando" e o
     * checkbox de habilitar vem desabilitado (defesa visual — a defesa real e o
     * guard de config_controller::saveGateway()).
     */
    public function testGatewayIncompleteCredentialsShowsBadgeAndDisablesCheckbox(): void
    {
        $html = $this->render(
            ['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => ''],
            [[
                'idx' => 2, 'name' => 'PagBank', 'slug' => 'pagbank', 'mode' => 'qr', 'enabled' => 'no',
                'monthly_limit_cents' => 0, 'max_order_cents' => null, 'mtd_cents' => 0, 'usage_pct' => 0.0,
                'cred_schema' => [
                    'api_base' => ['label' => 'URL base da API', 'secret' => false, 'required' => true],
                    'token'    => ['label' => 'Token', 'secret' => true, 'required' => true],
                ],
                'cred_masked' => ['api_base' => '', 'token' => ''],
                'cred_complete' => false,
            ]]
        );

        $this->assertStringContainsString('Faltando', $html);
        $this->assertMatchesRegularExpression('/name="enabled"[^>]*disabled/s', $html, 'checkbox de habilitar deve vir desabilitado sem credencial completa');
    }

    public function testSenderFormRendersAndEscapes(): void
    {
        $html = $this->render(
            ['name' => 'Admin', 'mail' => 'a@b.com', 'login' => 'admin', 'phone' => ''],
            [],
            [
                'sender_name' => '"><script>alert(1)</script>', 'sender_zip' => '13010100',
                'sender_street' => 'R. Exemplo', 'sender_number' => '45',
                'sender_complement' => '', 'sender_district' => 'Centro',
                'sender_city' => 'Campinas', 'sender_uf' => 'SP',
            ]
        );

        $this->assertStringContainsString('Endereço do Remetente', $html);
        $this->assertStringContainsString('name="action" value="remetente"', $html);
        $this->assertStringContainsString('name="sender_zip"', $html);
        $this->assertStringContainsString('value="13010100"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'nome do remetente deve ser escapado, nunca renderizado cru');
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
