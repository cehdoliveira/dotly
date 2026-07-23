#!/usr/bin/env php
<?php

/**
 * reconcile_charges.php
 *
 * Cron de reconciliacao de cobrancas (Plano 034). Fallback para quando o
 * webhook de pagamento falha ou atrasa (rede, PSP, janela de deploy): varre
 * um lote pequeno de cobrancas `pendente` (mercadopago/pagbank, pedido ainda
 * `aguardando_pagamento`, criadas nas ultimas 24h), confirma no PSP via
 * fetchStatus() e marca `pago` quando confirmado. InfinitePay fica de fora —
 * sem endpoint de consulta de status (fetchStatus() sempre 'pendente').
 *
 * Toda a logica (guarda de corrida com o webhook, escrita condicional,
 * commit por cobranca) vive em OrderReconciler::reconcilePending()
 * (app/inc/lib/, testavel por PHPUnit) — este script e so a casca do cron,
 * mesmo padrao de dispatch_emails.php / expire_orders.php.
 *
 * Uso: php reconcile_charges.php
 */

date_default_timezone_set('America/Sao_Paulo');

// Simulacao de ambiente HTTP para CLI — necessario porque kernel.php deriva
// cRootServer_APP (usado pelo autoload de model/) de $_SERVER["DOCUMENT_ROOT"].
// Mesmo padrao de cgi-bin/dispatch_emails.php e expire_orders.php.
$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"]     = getenv("CLI_HTTP_HOST") ?: "infinnityimportacao.local";

require_once __DIR__ . '/../app/inc/kernel.php';
require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';

$pdo = localPDO::getInstance();
$rawPdo = $pdo->getPdo();

// Lock advisorio no banco — nome PROPRIO, diferente dos outros jobs, para nao
// competir pelo mesmo lock. GET_LOCK(...,0) retorna imediatamente: se outro
// processo detem o lock, pulamos este ciclo em vez de arriscar 2 execucoes
// confirmando a mesma cobranca ao mesmo tempo.
$got = $rawPdo->query("SELECT GET_LOCK('infinnityimportacao_reconcile_charges', 0) AS l")->fetch(\PDO::FETCH_ASSOC);
if ((int)($got['l'] ?? 0) !== 1) {
    echo "reconcile_charges: outro processo ja esta rodando — pulando este ciclo.\n";
    exit(0);
}

try {
    $summary = (new OrderReconciler())->reconcilePending();

    echo sprintf(
        "reconcile_charges: %d cobranca(s) verificada(s), %d confirmada(s) como paga(s), %d pulada(s), %d com erro (ver error_log), %d expirada(s)-mas-paga(s) alertada(s) (requer reconciliacao manual)\n",
        $summary['checked'],
        $summary['confirmed'],
        $summary['skipped'],
        $summary['errored'],
        $summary['alerted']
    );
} catch (\Throwable $e) {
    // Fail-open: ex. deploy novo antes do 1o tick de run_migrations.php,
    // ou qualquer erro inesperado — nao e fatal, so espera o proximo ciclo.
    error_log("reconcile_charges: ciclo abortado — " . $e->getMessage());
    echo "reconcile_charges: erro — " . $e->getMessage() . "\n";
} finally {
    $rawPdo->query("SELECT RELEASE_LOCK('infinnityimportacao_reconcile_charges')");
}

exit(0);
