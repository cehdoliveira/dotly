#!/usr/bin/env php
<?php

/**
 * expire_orders.php
 *
 * Cron de expiracao de pedidos (Plano 032). Varre um lote pequeno de pedidos
 * `aguardando_pagamento` cujo `expires_at` ja passou, marca-os `expirado` e
 * devolve o estoque decrementado no checkout — sem isso, todo carrinho
 * abandonado segura estoque indefinidamente ("estoque fantasma").
 *
 * Toda a logica (guarda de corrida com o webhook, calculo de unidades a
 * devolver, commit por pedido) vive em OrderExpirer::expireDueOrders()
 * (app/inc/lib/, testavel por PHPUnit) — este script e so a casca do cron,
 * mesmo padrao de dispatch_emails.php.
 *
 * Uso: php expire_orders.php
 */

date_default_timezone_set('America/Sao_Paulo');

// Simulacao de ambiente HTTP para CLI — necessario porque kernel.php deriva
// cRootServer_APP (usado pelo autoload de model/) de $_SERVER["DOCUMENT_ROOT"].
// Mesmo padrao de cgi-bin/dispatch_emails.php.
$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"]     = getenv("CLI_HTTP_HOST") ?: "infinnityimportacao.local";

require_once __DIR__ . '/../app/inc/kernel.php';
require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';

$pdo = localPDO::getInstance();
$rawPdo = $pdo->getPdo();

// Lock advisorio no banco — nome PROPRIO, diferente do dispatch_emails, para
// nao competir pelo mesmo lock. GET_LOCK(...,0) retorna imediatamente: se
// outro processo detem o lock, pulamos este ciclo em vez de arriscar 2
// execucoes processando o mesmo pedido ao mesmo tempo.
$got = $rawPdo->query("SELECT GET_LOCK('infinnityimportacao_expire_orders', 0) AS l")->fetch(\PDO::FETCH_ASSOC);
if ((int)($got['l'] ?? 0) !== 1) {
    echo "expire_orders: outro processo ja esta rodando — pulando este ciclo.\n";
    exit(0);
}

try {
    $summary = (new OrderExpirer())->expireDueOrders();

    echo sprintf(
        "expire_orders: %d pedido(s) expirado(s), %d unidade(s) devolvida(s) ao estoque, %d pulado(s) (ja resolvido por outro caminho), %d com erro (ver error_log)\n",
        $summary['expired'],
        $summary['restocked_units'],
        $summary['skipped'],
        $summary['errored']
    );
} catch (\Throwable $e) {
    // Fail-open: ex. deploy novo antes do 1o tick de run_migrations.php,
    // ou qualquer erro inesperado — nao e fatal, so espera o proximo ciclo.
    error_log("expire_orders: ciclo abortado — " . $e->getMessage());
    echo "expire_orders: erro — " . $e->getMessage() . "\n";
} finally {
    $rawPdo->query("SELECT RELEASE_LOCK('infinnityimportacao_expire_orders')");
}

exit(0);
