<?php
// autoload.php PRECISA vir antes do kernel.php: senao class_exists("RedisCache")
// dentro do kernel.php sempre da falso (a classe so existe depois que o autoload
// registra o loader), e $GLOBALS['redis'] nunca e populado — Redis fica morto em
// todo o app, sempre caindo no fallback de arquivo do rate limit sem avisar
// ninguem. Usa o mesmo DOCUMENT_ROOT que o include do kernel.php (nao pode
// depender de cRootServer_APP, que so existe DEPOIS que o kernel.php roda).
require_once($_SERVER["DOCUMENT_ROOT"] . "/../app/inc/lib/vendor/autoload.php");
include($_SERVER["DOCUMENT_ROOT"] . "/../app/inc/kernel.php");
require_once(constant("cRootServer_APP") . "/inc/lists.php");
require_once(constant("cRootServer_APP") . "/inc/lib/CommonFunctions.php");
require_once(constant("cRootServer_APP") . "/inc/urls.php");

// Timeout de inatividade aplicado pela APLICACAO. Este e o primeiro ponto do
// request em que o kernel.php ja foi carregado (a constante existe) e a sessao ja
// esta aberta (session_start em public_html/index.php, que roda antes do kernel e
// por isso nao pode ler a constante). Sem isto, SESSION_LIFETIME era uma constante
// morta e quem decidia era o session.gc_maxlifetime do php.ini — mais curto que a
// janela anunciada e probabilistico (gc por sorteio).
$_session_lifetime = defined('SESSION_LIFETIME') ? (int) constant('SESSION_LIFETIME') : 0;
if ($_session_lifetime > 0) {
	$_session_last = isset($_SESSION['_last_activity']) ? (int) $_SESSION['_last_activity'] : null;
	if ($_session_last !== null && (time() - $_session_last) > $_session_lifetime) {
		// Sessao ociosa: descarta TUDO (credencial do admin, carrinho, tokens) e
		// comeca uma sessao limpa com id novo, para nao reaproveitar o id antigo.
		$_SESSION = [];
		session_destroy();
		// session_start() aqui NAO e redundante: session_destroy() deixa
		// session_status() em PHP_SESSION_NONE, e session_regenerate_id()
		// exige sessao ativa - sem este start(), o regenerate falha e o id
		// antigo (ja marcado como expirado) seria reaproveitado.
		session_start();
		session_regenerate_id(true);
	}
	$_SESSION['_last_activity'] = time();
}
unset($_session_lifetime, $_session_last);

if (empty($_SESSION['_csrf_token'])) {
	$_SESSION['_csrf_token'] = random_token();
}
