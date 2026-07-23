<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre o comportamento consultivo e de execucao do MigrationRunner
 * (fora do guard GET_LOCK, ja coberto por MigrationRunnerLockTest):
 *
 *  - uma migration nova em um diretorio de fixture e executada e registrada;
 *  - uma segunda chamada a run() pula a mesma migration (idempotencia);
 *  - o splitter por ';' nao se confunde com ';' dentro de comentarios de linha
 *    ou de bloco (a regex de remocao de comentarios roda antes do explode);
 *  - status() reflete pending -> executed/success.
 *
 * Estende TestCase puro (nao DBTestCase): o runner administra suas proprias
 * transacoes e comita de verdade — o padrao rollback-por-teste do DBTestCase
 * mascararia ou entraria em conflito com esses commits.
 */
final class MigrationRunnerTest extends TestCase
{
    private string $tmpDir;
    private localPDO $con;
    /** @var string[] nomes de migration criados no teste corrente, para limpeza */
    private array $createdMigrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/mr_runner_test_' . uniqid();
        mkdir($this->tmpDir);
        $this->con = new localPDO();
        $this->createdMigrations = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdMigrations as $name) {
            try {
                $this->con->getPdo()->prepare(
                    "DELETE FROM migrations_log WHERE migration_name = ?"
                )->execute([$name]);
            } catch (\PDOException $e) {
                // migrations_log pode nao existir ainda — nada a limpar.
            }
        }

        foreach (glob($this->tmpDir . '/*.sql') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    /**
     * Escreve um arquivo de migration de fixture e registra o nome para
     * limpeza automatica no tearDown.
     */
    private function writeMigration(string $name, string $sql): void
    {
        file_put_contents($this->tmpDir . '/' . $name . '.sql', $sql);
        $this->createdMigrations[] = $name;
    }

    public function testRunExecutesNewMigrationAndRecordsIt(): void
    {
        $name = '910_new_migration_' . uniqid();
        $this->writeMigration(
            $name,
            "CREATE TABLE IF NOT EXISTS test_mr_new (id INT PRIMARY KEY);\n" .
            "DROP TABLE IF EXISTS test_mr_new;"
        );

        $runner = new MigrationRunner($this->con, $this->tmpDir);
        $runner->setLogger(function ($m) {});

        $results = $runner->run();

        $this->assertContains($name, $results['executed']);
        $this->assertEmpty($results['failed']);

        $stmt = $this->con->getPdo()->prepare(
            "SELECT status FROM migrations_log WHERE migration_name = ?"
        );
        $stmt->execute([$name]);
        $this->assertSame('success', $stmt->fetchColumn());
    }

    public function testRunSkipsAlreadyExecutedMigration(): void
    {
        $name = '911_idempotent_migration_' . uniqid();
        $this->writeMigration(
            $name,
            "CREATE TABLE IF NOT EXISTS test_mr_idempotent (id INT PRIMARY KEY);\n" .
            "DROP TABLE IF EXISTS test_mr_idempotent;"
        );

        $runner = new MigrationRunner($this->con, $this->tmpDir);
        $runner->setLogger(function ($m) {});

        $first = $runner->run();
        $this->assertContains($name, $first['executed']);

        $second = $runner->run();
        $this->assertContains($name, $second['skipped']);
        $this->assertNotContains($name, $second['executed']);
    }

    public function testExecuteMigrationIgnoresSemicolonsInsideComments(): void
    {
        $name = '912_comment_semicolons_' . uniqid();
        $this->writeMigration(
            $name,
            "-- comentario de linha com ; um ponto e virgula no meio\n" .
            "CREATE TABLE IF NOT EXISTS test_mr_comment (id INT PRIMARY KEY, val VARCHAR(50));\n" .
            "/* comentario de bloco; com mais de um ; ponto e virgula */\n" .
            "INSERT INTO test_mr_comment (id, val) VALUES (1, 'ok');\n" .
            "DROP TABLE IF EXISTS test_mr_comment;"
        );

        $runner = new MigrationRunner($this->con, $this->tmpDir);
        $runner->setLogger(function ($m) {});

        $results = $runner->run();

        $this->assertContains($name, $results['executed']);
        $this->assertEmpty($results['failed'], 'comentarios com ; nao devem quebrar o splitter de statements');
    }

    public function testStatusReflectsPendingThenExecuted(): void
    {
        $name = '913_status_migration_' . uniqid();
        $this->writeMigration(
            $name,
            "CREATE TABLE IF NOT EXISTS test_mr_status (id INT PRIMARY KEY);\n" .
            "DROP TABLE IF EXISTS test_mr_status;"
        );

        $runner = new MigrationRunner($this->con, $this->tmpDir);
        $runner->setLogger(function ($m) {});

        $before = $runner->status();
        $this->assertArrayHasKey($name, $before);
        $this->assertFalse($before[$name]['executed']);
        $this->assertSame('pending', $before[$name]['status']);

        $runner->run();

        $after = $runner->status();
        $this->assertTrue($after[$name]['executed']);
        $this->assertSame('success', $after[$name]['status']);
    }
}
