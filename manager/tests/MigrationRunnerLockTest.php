<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Testa o guard GET_LOCK do MigrationRunner::run().
 *
 * Estende TestCase puro (nao DBTestCase): o runner gerencia suas
 * proprias transacoes e commita de verdade (createMigrationsTable(),
 * executeMigration(), recordMigration()). O padrao rollback-por-teste
 * do DBTestCase mascararia ou entraria em conflito com esses commits.
 */
final class MigrationRunnerLockTest extends TestCase
{
    private string $tmpDir;
    private localPDO $con;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/mr_test_' . uniqid();
        mkdir($this->tmpDir);
        file_put_contents(
            $this->tmpDir . '/900_getlock_regression_test.sql',
            "CREATE TABLE IF NOT EXISTS test_mr_getlock (id INT PRIMARY KEY);\n" .
            "DROP TABLE IF EXISTS test_mr_getlock;\n"
        );

        $this->con = new localPDO();
    }

    protected function tearDown(): void
    {
        try {
            $this->con->getPdo()->prepare(
                "DELETE FROM migrations_log WHERE migration_name = ?"
            )->execute(['900_getlock_regression_test']);
        } catch (\PDOException $e) {
            // migrations_log pode nao existir ainda (ex.: bug do guard impediu
            // createMigrationsTable() de rodar) — nada a limpar nesse caso.
        }

        @unlink($this->tmpDir . '/900_getlock_regression_test.sql');
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testRunAcquiresLockAndExecutesMigrations(): void
    {
        $runner = new MigrationRunner($this->con, $this->tmpDir);
        $runner->setLogger(function ($m) {});

        $results = $runner->run();

        $this->assertContains('900_getlock_regression_test', $results['executed']);
        $this->assertEmpty($results['failed']);

        $results2 = $runner->run();

        $this->assertContains('900_getlock_regression_test', $results2['skipped']);
    }

    public function testRunSkipsWhenLockHeldElsewhere(): void
    {
        $other = new localPDO();
        $other->getPdo()->query("SELECT GET_LOCK('dotly_migrations', 0)");

        try {
            $runner = new MigrationRunner($this->con, $this->tmpDir);
            $runner->setLogger(function ($m) {});

            $results = $runner->run();

            $this->assertEmpty($results['executed']);
            $this->assertEmpty($results['skipped']);
            $this->assertEmpty($results['failed']);
        } finally {
            $other->getPdo()->query("SELECT RELEASE_LOCK('dotly_migrations')");
        }
    }
}
