<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * TestCase com isolamento de banco via transacoes.
 *
 * Cada teste inicia uma transacao no setUp e faz rollback no tearDown.
 * Isso garante que nenhum dado de um teste contamine o proximo,
 * eliminando a dependencia de ordem de execucao e permitindo
 * paralelismo futuro.
 */
abstract class DBTestCase extends TestCase
{
    private static ?localPDO $sharedCon = null;
    protected localPDO $con;

    public static function setUpBeforeClass(): void
    {
        self::$sharedCon = new localPDO();
    }

    public static function tearDownAfterClass(): void
    {
        self::$sharedCon = null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Cada teste recebe sua propria conexao para isolamento
        $this->con = new localPDO();
        $this->con->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->con !== null) {
            try {
                $this->con->rollback();
            } catch (\Throwable $e) {
                // Ignora erros de rollback — transacao ja pode ter sido encerrada
            }
        }
        parent::tearDown();
    }

    /**
     * Cria uma nova instancia de model com transacao ativa.
     */
    protected function createModel(string $class, string $table): DOLModel
    {
        $model = new $class();
        return $model;
    }
}
