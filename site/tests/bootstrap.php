<?php

/**
 * Bootstrap do PHPUnit — simula ambiente CLI para testes
 */
date_default_timezone_set('America/Sao_Paulo');

$_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__) . "/public_html/";
// HTTP_HOST precisa ser valido segundo ALLOWED_HOSTS do kernel, senao o guard
// anti-Host-Injection devolve 400 e o boot do PHPUnit morre antes do 1o teste.
// Le o primeiro host de ALLOWED_HOSTS direto do kernel (real se existir, senao
// example) para ser brand-agnostic apos whitelabel.
(function () {
	$kernelFile = __DIR__ . '/../app/inc/kernel.php';
	if (!file_exists($kernelFile)) {
		$kernelFile = __DIR__ . '/../app/inc/kernel.php.example';
	}
	$src = file_get_contents($kernelFile);
	if (preg_match('/define\(\s*["\']ALLOWED_HOSTS["\']\s*,\s*["\']([^"\']+)["\']/', $src, $m)) {
		$hosts = explode(',', $m[1]);
		$_SERVER["HTTP_HOST"] = trim($hosts[0]);
	} else {
		$_SERVER["HTTP_HOST"] = 'localhost';
	}
})();

putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');
putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');

set_include_path($_SERVER["DOCUMENT_ROOT"] . PATH_SEPARATOR . get_include_path());

define('CLI_MODE', true);
define('TESTING', true);

require_once __DIR__ . '/../app/inc/kernel.php';
require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';
require_once __DIR__ . '/../app/inc/lists.php';

// Autoloader manual
spl_autoload_register(function ($name) {
    if (strpos($name, '\\') !== false) return;
    // App classes: model, lib, controller
    $base = __DIR__ . '/../app/inc/';
    foreach (['model', 'lib', 'controller'] as $dir) {
        $file = $base . "$dir/$name.php";
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    // Test helpers: tests/
    $testFile = __DIR__ . "/$name.php";
    if (file_exists($testFile)) {
        require_once $testFile;
    }
});
