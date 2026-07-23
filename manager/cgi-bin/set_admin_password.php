#!/usr/bin/env php
<?php

/**
 * set_admin_password.php
 *
 * CLI para definir/reativar a senha do usuário admin (login "admin").
 * Necessário após a rotação da migration 007, que desabilita o admin
 * seedado e invalida a senha default commitada no repositório.
 *
 * A nova senha é lida via STDIN (nunca via argumento de linha de comando,
 * que ficaria visível em `ps`/histórico do shell).
 *
 * Uso:
 *   echo 'nova-senha' | php set_admin_password.php
 */

// Simulação de ambiente HTTP para CLI — necessário porque scripts CLI não
// possuem $_SERVER configurado (mesmo padrão de kafka_email_worker.php).
$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"]     = getenv("CLI_HTTP_HOST") ?: "";

define('APP_PATH', realpath(__DIR__ . '/../app'));

require_once APP_PATH . '/inc/kernel.php';
require_once APP_PATH . '/inc/lib/vendor/autoload.php';

$password = trim((string) fgets(STDIN));

if (strlen($password) < 6) {
    echo "Erro: a senha deve ter pelo menos 6 caracteres.\n";
    exit(1);
}

$users = new users_model();
$users->set_field([" idx "]);
$users->set_filter([" login = ? ", " active = 'yes' "], ["admin"]);
$users->set_paginate([1]);
$users->load_data();

$userIdx = $users->data[0]["idx"] ?? null;

if (!$userIdx) {
    echo "Erro: usuário admin não encontrado.\n";
    exit(1);
}

$users->set_filter(["idx = ?"], [$userIdx]);
$users->populate([
    "password" => password_hash($password, PASSWORD_BCRYPT),
    "enabled"  => "yes",
]);
$users->save();

localPDO::getInstance()->commit();

echo "Senha do admin atualizada com sucesso.\n";
exit(0);
