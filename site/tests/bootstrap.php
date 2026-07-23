<?php

/**
 * Bootstrap do PHPUnit — simula ambiente CLI para testes
 */
date_default_timezone_set('America/Sao_Paulo');

$_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__) . "/public_html/";
$_SERVER["HTTP_HOST"] = "dotly.local";

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
