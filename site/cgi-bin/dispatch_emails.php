#!/usr/bin/env php
<?php

/**
 * dispatch_emails.php
 *
 * Cron dispatcher da fila `email_queue` (Plano 016). Le um lote pequeno de
 * linhas 'pending', chama EmailProducer::send() (enfileira no Kafka; o worker
 * existente entrega por SMTP) e marca sent/incrementa attempts. O retry fica
 * na propria tabela — nao ha reprocessamento aqui, so no proximo tick do cron.
 *
 * Sem rdkafka disponivel, EmailProducer::send() sempre devolve false: as
 * linhas ficam 'pending'/retentando ate o Kafka voltar (degradacao fail-open
 * aceita — nenhum e-mail se perde, so atrasa).
 *
 * Uso: php dispatch_emails.php
 */

date_default_timezone_set('America/Sao_Paulo');

// Simulacao de ambiente HTTP para CLI — necessario porque kernel.php deriva
// cRootServer_APP (usado pelo autoload de model/) de $_SERVER["DOCUMENT_ROOT"].
// Mesmo padrao de cgi-bin/kafka_email_worker.php.
$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"]     = getenv("CLI_HTTP_HOST") ?: "dotly.local";

require_once __DIR__ . '/../app/inc/kernel.php';
require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';

// LOTE PEQUENO: no maximo 20 por execucao (respeita limite de provedores SMTP
// como Gmail e evita segurar a transacao aberta por muito tempo).
const DISPATCH_BATCH_SIZE = 20;

$pdo = localPDO::getInstance();
$rawPdo = $pdo->getPdo();

// Lock advisorio no banco — defesa em profundidade alem do flock -n do
// crontab, mesmo padrao de MigrationRunner::run(). GET_LOCK(...,0) retorna
// imediatamente: se outro processo detem o lock, pulamos este ciclo em vez
// de arriscar 2 dispatchers processando a mesma linha 'pending' ao mesmo
// tempo (achado da revisao adversarial, plano 016).
$got = $rawPdo->query("SELECT GET_LOCK('dotly_dispatch_emails', 0) AS l")->fetch(\PDO::FETCH_ASSOC);
if ((int)($got['l'] ?? 0) !== 1) {
    echo "dispatch_emails: outro processo ja esta rodando — pulando este ciclo.\n";
    exit(0);
}

try {
    $queue = new email_queue_model();
    $queue->set_field([" idx ", " to_mail ", " subject ", " body ", " attempts ", " max_attempts "]);
    $queue->set_filter([" active = 'yes' ", " status = 'pending' "]);
    $queue->set_order([" idx ASC "]);
    $queue->set_paginate([0, DISPATCH_BATCH_SIZE]);
    $queue->load_data(false);

    $sentCount = 0;
    $retryCount = 0;
    $failedCount = 0;

    foreach ($queue->data as $row) {
        // Processamento de 1 linha extraido para EmailQueueDispatcher (testavel
        // por PHPUnit — este script cron em si nao e invocavel pela suite).
        $status = EmailQueueDispatcher::processRow($row);

        // Commit por linha (nao 1 commit unico ao final do lote): o efeito de
        // EmailProducer::send() (produzir no Kafka) e externo e NAO e desfeito
        // por um rollback local. Um commit so no fim arriscaria, se uma linha
        // posterior do mesmo lote falhar antes do commit, reverter o status
        // 'sent' de linhas anteriores cujo e-mail ja foi entregue de verdade —
        // o proximo ciclo as reenviaria, duplicando e-mail pro cliente. Commitar
        // logo apos cada linha limita o blast radius a no maximo 1 linha por
        // falha, nao ate 20 (achado da revisao adversarial, plano 016).
        $pdo->commit();
        $pdo->beginTransaction();

        match ($status) {
            'queued' => $sentCount++,
            'failed' => $failedCount++,
            default  => $retryCount++,
        };
    }

    echo sprintf(
        "dispatch_emails: lote de %d — %d produzido(s) no Kafka, %d p/ retry, %d marcado(s) como failed\n",
        count($queue->data),
        $sentCount,
        $retryCount,
        $failedCount
    );
} catch (\Throwable $e) {
    // Fail-open: ex. a migration 027 ainda nao rodou (1o tick do dispatcher
    // antes do 1o tick de run_migrations.php num deploy novo) e email_queue
    // ainda nao existe — nao e um erro fatal, so espera o proximo ciclo.
    error_log("dispatch_emails: ciclo abortado — " . $e->getMessage());
    echo "dispatch_emails: erro — " . $e->getMessage() . "\n";
} finally {
    $rawPdo->query("SELECT RELEASE_LOCK('dotly_dispatch_emails')");
}

exit(0);
