<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * TestCase para testes que tocam banco.
 *
 * ATENCAO ao isolamento real: setUp() abre transacao em $this->con, uma conexao
 * PROPRIA — mas os models (DOLModel) usam localPDO::getInstance(), que e OUTRA
 * conexao (singleton do processo). Fixtures criadas via model NAO sao revertidas
 * pelo rollback do tearDown(): elas ficam na transacao do singleton, que so termina
 * no fim do processo (localPDO::__destruct faz rollback de seguranca) — ou seja,
 * nao vazam para o banco, mas SAO visiveis para os testes seguintes do mesmo
 * processo. Por isso a suite usa uniqid() nos identificadores de fixture: siga esse
 * padrao em teste novo, nao confie em "cada teste comeca do zero".
 *
 * $this->con serve para consultas diretas do proprio teste.
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
