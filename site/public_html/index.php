<?php

/**
 * Front Controller Principal
 * PHP 8.3+ com PDO e MySQL 8.0
 *
 * Este arquivo é o ponto de entrada da aplicação
 * Gerencia sessões, rotas e despacho de requisições
 */

// ob_start() ANTES de qualquer output garante que header() e Set-Cookie
// funcionem mesmo que algum include gere bytes acidentais (espaços, BOM, etc.)
ob_start();

// Iniciar sessão com configurações seguras para PHP 8.4
// cookie_secure: força envio do cookie apenas sobre HTTPS (alinhado ao php.ini)
// cookie_samesite Lax: permite cookies em redirects GET de topo (pós-login)
// use_only_cookies: impede que o session_id seja passado via URL
// use_strict_mode REMOVIDO: conflita com session_write_close() explícito no phpredis —
//   sessões ficam como "não inicializadas" e são rejeitadas na próxima requisição.
//   Proteção contra session fixation é feita via session_regenerate_id(true) no login.
$isHttpsRequest = (
	(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
	(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
	(!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

session_start([
	'cookie_httponly'  => true,
	'cookie_secure'    => $isHttpsRequest,
	'cookie_samesite'  => 'Lax',
	'use_only_cookies' => true,
]);

header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");

// Configurações de erro — controladas pelo php.ini em produção
// ini_set('display_errors', 1) foi REMOVIDO: em produção erros não devem ser exibidos

// Carregar dependências principais
require_once($_SERVER["DOCUMENT_ROOT"] . "/../app/inc/main.php");

// CSP com nonce por request — precisa ser gerada em PHP (nginx não pode variar por
// resposta). Exposta via $GLOBALS pois head.php é incluído dentro do escopo local dos
// métodos de controller, não no escopo global deste arquivo.
$GLOBALS["cspNonce"] = random_token(16);
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $GLOBALS["cspNonce"] . "' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; object-src 'none'; base-uri 'self'");

// Janela de vendas (plano 037): fora da janela / sem estoque / override
// 'closed', as rotas de compra caem na pagina "vendas encerradas". Pos-venda
// segue vivo — /pagamento, /pedido e /acompanhar-pedido acessiveis (PIX
// pendente) e /webhook/pix recebendo confirmacoes do PSP. Manager nao passa
// por aqui. Fail-open: SalesWindow devolve aberto em erro de DB.
$salesPath = (string) (parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/");
if (!SalesWindow::isPostSaleRoute($salesPath)) {
	$salesStatus = SalesWindow::status();
	if (!$salesStatus["open"]) {
		$noindex = true;
		include(constant("cRootServer") . "ui/common/head.php");
		include(constant("cRootServer") . "ui/page/sales_closed.php");
		exit;
	}
}

// Parâmetros da requisição (PHP 8.4 compatível)
$params = [
	"sr" => isset($_GET["sr"]) && (int)$_GET["sr"] > 1 ? (int)$_GET["sr"] : 0,
	"format" => ".html",
	"post" => $_POST ?? null,
	"get" => $_GET ?? null,
];

// Flags de ação
$btn_save = isset($_POST["btn_save"]) ? true : null;
$btn_remove = isset($_POST["btn_remove"]) ? true : null;

$dispatcher = new Dispatcher(true);

// Definir rotas da aplicação
$dispatcher->add_route("GET", "/(index(\.json|\.xml|\.html)).*?", "function:basic_redir", null, $home_url);

// Home pública
$dispatcher->add_route("GET", "/?", "site_controller:home", null, $params);
$dispatcher->add_route("GET", "/termos-de-uso(\.json|\.xml|\.html)?", "site_controller:terms", null, $params);
$dispatcher->add_route("GET", "/politica-de-privacidade(\.json|\.xml|\.html)?", "site_controller:privacy", null, $params);

// Carrinho
$dispatcher->add_route("GET",  "/carrinho", "cart_controller:index",  null, $params);
$dispatcher->add_route("POST", "/carrinho", "cart_controller:action", null, $params);

// Produto
$dispatcher->add_route("GET", "/produto/([a-z0-9]+(?:[-_][a-z0-9]+)*)", "shop_controller:product", null, $params);

// Checkout — a regex [a-f0-9]{32} casa exatamente o formato de random_token(16)
$dispatcher->add_route("GET",  "/checkout",                       "checkout_controller:index",    null, $params);
$dispatcher->add_route("GET",  "/checkout/cep/([0-9]{8})",        "checkout_controller:cep",      null, $params);
$dispatcher->add_route("POST", "/checkout",                       "checkout_controller:finalize", null, $params);
$dispatcher->add_route("GET",  "/pagamento/([a-f0-9]{32})/status", "checkout_controller:status",   null, $params);
$dispatcher->add_route("GET",  "/pagamento/([a-f0-9]{32})",        "checkout_controller:payment",  null, $params);
$dispatcher->add_route("GET",  "/pedido/([a-f0-9]{32})",           "checkout_controller:done",     null, $params);

// Webhook PIX — NAO valida CSRF (sem sessao do comprador, PSP nao tem como mandar token).
// Autenticidade vem da assinatura verificada em webhook_controller::receive().
$dispatcher->add_route("POST", "/webhook/pix/(mercadopago|pagbank|infinitepay)", "webhook_controller:receive", null, $params);

// Acompanhar pedido (público — e-mail + 4 últimos dígitos do telefone)
$dispatcher->add_route("GET",  "/acompanhar-pedido", "track_order_controller:index",  null, $params);
$dispatcher->add_route("POST", "/acompanhar-pedido", "track_order_controller:search", null, $params);

// Executar dispatcher e tratar falhas
if (!$dispatcher->exec()) {
	basic_redir($home_url);
}
