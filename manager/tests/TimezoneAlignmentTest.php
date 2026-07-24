<?php

declare(strict_types=1);

/**
 * Regressao: o container MySQL roda em UTC e o PHP em America/Sao_Paulo. Sem o
 * time_zone da sessao fixado em localPDO, NOW() ficava 3h a frente de date(),
 * e todo created_at/modified_at (carimbado por now() no DOLModel) divergia dos
 * carimbos feitos em PHP. Ver plans/005.
 */
final class TimezoneAlignmentTest extends DBTestCase
{
    public function testMysqlNowMatchesPhpNow(): void
    {
        $pdo = localPDO::getInstance();
        $row = $pdo->getPdo()->query("SELECT NOW() AS n")->fetch(\PDO::FETCH_ASSOC);

        $mysql = strtotime((string)$row['n']);
        $php   = time();

        // Tolerancia de 60s cobre latencia de query e virada de segundo; 3h de
        // skew (10800s) reprova.
        $this->assertLessThan(
            60,
            abs($mysql - $php),
            'NOW() do MySQL divergiu do date() do PHP em mais de 60s — time_zone da sessao nao aplicado'
        );
    }
}
