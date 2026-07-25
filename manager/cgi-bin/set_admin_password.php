#!/usr/bin/env php
<?php

/**
 * set_admin_password.php
 *
 * CLI para definir/reativar a senha do usuário admin (login "admin").
 * O seed em migrations/002_create_table_users.sql já nasce com `enabled='yes'`
 * e uma senha bootstrap pública (hash commitado no repositório) — este script
 * troca essa senha por uma nova (e força `enabled='yes'` de volta, caso o
 * login tenha sido desabilitado por outro processo).
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
