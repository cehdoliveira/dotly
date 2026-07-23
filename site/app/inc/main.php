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

if (empty($_SESSION['_csrf_token'])) {
	$_SESSION['_csrf_token'] = random_token();
}
