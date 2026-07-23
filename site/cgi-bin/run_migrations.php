#!/usr/bin/env php
<?php

/**
 * Migration CLI Runner
 * Executa todas as migrations pendentes do banco de dados
 *
 * Uso:
 *   php run_migrations.php
 */

define('APP_PATH', realpath(__DIR__ . '/../app'));

require_once APP_PATH . '/inc/kernel.php';
require_once APP_PATH . '/inc/lib/localPDO.php';
require_once APP_PATH . '/inc/lib/MigrationRunner.php';

try {
    $pdo = new localPDO();
    $runner = new MigrationRunner($pdo);

    echo "\n========================================\n";
    echo "🚀 Executando Migrations\n";
    echo "========================================\n";

    $dir = $runner->getMigrationsDir();
    echo "📁 Diretório: " . ($dir ?: "(não encontrado)") . "\n";
    echo "   Existe? " . (is_dir($dir) ? "✅ SIM" : "❌ NÃO") . "\n";

    if (is_dir($dir)) {
        $files = glob($dir . '/*.sql');
        echo "   Arquivos .sql: " . count($files) . "\n";
    }
    echo "\n";

    $results = $runner->run();

    echo "\n========================================\n";
    echo "📊 Resumo:\n";
    echo "  ✅ Executadas: " . count($results['executed']) . "\n";
    echo "  ⏭️  Ignoradas: " . count($results['skipped']) . "\n";
    echo "  ❌ Falhas: " . count($results['failed']) . "\n";
    echo "========================================\n\n";

    exit(count($results['failed']) > 0 ? 1 : 0);
} catch (Exception $e) {
    echo "\n========================================\n";
    echo "❌ Erro na Execução\n";
    echo "========================================\n";
    echo $e->getMessage() . "\n\n";
    exit(1);
}
