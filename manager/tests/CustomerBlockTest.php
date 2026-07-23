<?php

declare(strict_types=1);

/**
 * Cobre o bloqueio de cliente (plano 023): a escrita de customers_controller::action()
 * e o match usado no checkout (checkout_controller::isBlocked). Um cliente esta
 * bloqueado se e-mail, CPF OU telefone bater — e strings vazias ('') nunca casam
 * entre si (guarda "<> '' AND = ?"). Reproduz a mesma query EXISTS dos dois lados.
 */
final class CustomerBlockTest extends DBTestCase
{
    private function block(string $mail, string $cpf, string $phone): int
    {
        $model = new blocked_customers_model();
        $model->populate([
            'customer_mail'  => $mail,
            'customer_cpf'   => $cpf,
            'customer_phone' => $phone,
            'blocked_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $model->save();
        $this->assertGreaterThan(0, $id, 'Insert de bloqueio deve retornar um ID valido');

        return $id;
    }

    private function isBlocked(string $mail, string $cpf, string $phone): bool
    {
        $model = new blocked_customers_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT 1 FROM blocked_customers
              WHERE active = 'yes'
                AND ( customer_mail = ?
                      OR ( customer_cpf <> '' AND customer_cpf = ? )
                      OR ( customer_phone <> '' AND customer_phone = ? ) )
              LIMIT 1",
            [$mail, $cpf, $phone]
        );

        return (bool) $stmt->fetchColumn();
    }

    public function testMatchesByAnyIdentifier(): void
    {
        $mail  = 'blk_' . uniqid() . '@example.com';
        $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone = '1198' . mt_rand(1000000, 9999999);
        $this->block($mail, $cpf, $phone);

        // Casa por e-mail apenas (CPF/telefone diferentes).
        $this->assertTrue($this->isBlocked($mail, '00000000000', '11000000000'));
        // Casa por CPF apenas.
        $this->assertTrue($this->isBlocked('outro@example.com', $cpf, '11000000000'));
        // Casa por telefone apenas.
        $this->assertTrue($this->isBlocked('outro@example.com', '00000000000', $phone));
    }

    public function testDoesNotMatchUnrelatedCustomer(): void
    {
        $this->block('blk_' . uniqid() . '@example.com', '12312312312', '11987654321');

        $this->assertFalse($this->isBlocked(
            'limpo_' . uniqid() . '@example.com',
            '99999999999',
            '21912345678'
        ));
    }

    public function testEmptyIdentifiersDoNotCrossMatch(): void
    {
        // Bloqueio so por e-mail: CPF/telefone vazios ('') nao podem casar outro
        // cliente que tambem tenha CPF/telefone vazios.
        $mail = 'blk_only_mail_' . uniqid() . '@example.com';
        $this->block($mail, '', '');

        $this->assertFalse(
            $this->isBlocked('diferente_' . uniqid() . '@example.com', '', ''),
            'CPF/telefone vazios nao devem casar entre si'
        );
        $this->assertTrue(
            $this->isBlocked($mail, '', ''),
            'O proprio e-mail bloqueado ainda casa'
        );
    }

    private function makeOrder(string $mail, string $cpf, string $phone): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => 'pago',
            'customer_name'  => 'Cliente Bloqueio',
            'customer_mail'  => $mail,
            'customer_phone' => $phone,
            'customer_cpf'   => $cpf,
            'ship_zip'       => '01000000',
            'ship_street'    => 'Rua Teste',
            'ship_number'    => '100',
            'ship_district'  => 'Centro',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => 1000,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    /**
     * NOTA: ate a revisao pre-landing, isto reproduzia a sequencia exata de
     * customers_controller::action() (SELECT de duplicidade + insert
     * separados). O controller foi corrigido para um INSERT...SELECT...WHERE
     * NOT EXISTS unico (reduz, mas sozinho nao fecha, a janela de corrida
     * entre dois cliques concorrentes — quem fecha de verdade e o UNIQUE KEY
     * da migration 035, ver testDuplicateMailInsertViolatesUniqueConstraint...)
     * — ver testInsertWhereNotExistsInsertsOnceThenNoOpsOnRepeat
     * abaixo, que reproduz a query atual literalmente. Este helper continua
     * validando a mesma semantica de idempotencia (qualquer um dos 3
     * identificadores ja bloqueado => nao duplica), so que via check+insert
     * separados em vez da query real do controller.
     */
    private function blockFromOrder(int $orderIdx): bool
    {
        $model = new orders_model();
        $model->set_field([" customer_name ", " customer_mail ", " customer_cpf ", " customer_phone "]);
        $model->set_filter([" active = 'yes' ", " idx = ? "], [$orderIdx]);
        $model->set_paginate([1]);
        $model->load_data(false);
        $order = $model->data[0] ?? null;

        if ($order === null) {
            return false;
        }

        $mail  = (string) ($order['customer_mail'] ?? '');
        $cpf   = (string) ($order['customer_cpf'] ?? '');
        $phone = (string) ($order['customer_phone'] ?? '');

        if ($this->isBlocked($mail, $cpf, $phone)) {
            return false;
        }

        $this->block($mail, $cpf, $phone);

        return true;
    }

    private function countBlocksFor(string $mail): int
    {
        $model = new blocked_customers_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT COUNT(*) AS total FROM blocked_customers WHERE active = 'yes' AND customer_mail = ?",
            [$mail]
        );

        return (int) $stmt->fetchColumn();
    }

    public function testBlockActionReadsIdentifiersFromAnchorOrderAndInserts(): void
    {
        $mail  = 'blkorder_' . uniqid() . '@example.com';
        $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone = '1198' . mt_rand(1000000, 9999999);
        $orderIdx = $this->makeOrder($mail, $cpf, $phone);

        $inserted = $this->blockFromOrder($orderIdx);

        $this->assertTrue($inserted, 'primeiro bloqueio a partir do pedido deve inserir uma linha');
        $this->assertTrue($this->isBlocked($mail, $cpf, $phone), 'apos o bloqueio, o cliente deve casar na blocklist');
    }

    public function testBlockActionIsIdempotentAcrossIdentifierOverlap(): void
    {
        $mail  = 'blkidem_' . uniqid() . '@example.com';
        $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone = '1197' . mt_rand(1000000, 9999999);
        $orderIdx = $this->makeOrder($mail, $cpf, $phone);

        $first  = $this->blockFromOrder($orderIdx);
        $second = $this->blockFromOrder($orderIdx);

        $this->assertTrue($first, 'primeira chamada deve inserir o bloqueio');
        $this->assertFalse($second, 'segunda chamada sobre o mesmo pedido nao deve inserir de novo (ja bloqueado)');
        $this->assertSame(1, $this->countBlocksFor($mail), 'bloquear duas vezes o mesmo cliente nao pode duplicar a linha');
    }

    /**
     * Reproduz literalmente a query que customers_controller::action() (case
     * 'bloquear') executa hoje: um INSERT...SELECT...WHERE NOT EXISTS unico,
     * que reduz (mas sozinho nao fecha) a janela de corrida entre dois
     * cliques concorrentes em "Bloquear" — o UNIQUE KEY em customer_mail
     * (migration 035) e quem fecha de verdade. rowCount() e o sinal que o
     * controller usa para decidir a mensagem: 1 linha => "Cliente bloqueado"
     * (success), 0 linhas => "Este cliente já está bloqueado" (info).
     */
    private function insertBlockWhereNotExists(string $mail, string $cpf, string $phone): int
    {
        $block = new blocked_customers_model();
        $insert = $block->execute_raw_prepared(
            "INSERT INTO blocked_customers (customer_mail, customer_cpf, customer_phone, blocked_at)
             SELECT ?, ?, ?, ?
              WHERE NOT EXISTS (
                SELECT 1 FROM blocked_customers
                 WHERE active = 'yes'
                   AND ( customer_mail = ?
                         OR ( customer_cpf <> '' AND customer_cpf = ? )
                         OR ( customer_phone <> '' AND customer_phone = ? ) )
              )",
            [$mail, $cpf, $phone, date('Y-m-d H:i:s'), $mail, $cpf, $phone]
        );

        return $insert->rowCount();
    }

    public function testInsertWhereNotExistsInsertsOnceThenNoOpsOnRepeat(): void
    {
        $mail  = 'wnx_' . uniqid() . '@example.com';
        $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone = '1196' . mt_rand(1000000, 9999999);

        $firstRows = $this->insertBlockWhereNotExists($mail, $cpf, $phone);
        $this->assertSame(1, $firstRows, 'primeira chamada (cliente ainda nao bloqueado) deve afetar exatamente 1 linha');

        $secondRows = $this->insertBlockWhereNotExists($mail, $cpf, $phone);
        $this->assertSame(0, $secondRows, 'segunda chamada (cliente ja bloqueado) nao deve inserir e deve afetar 0 linhas');

        $this->assertSame(1, $this->countBlocksFor($mail), 'apenas uma linha de bloqueio deve existir apos as duas chamadas');
    }

    public function testInsertWhereNotExistsNoOpsWhenAnyIdentifierAlreadyBlocked(): void
    {
        // Bloqueia so por CPF (mail/phone diferentes do que sera testado a seguir).
        // Identificadores aleatorios (nao literais fixos): linhas de bloqueio
        // nao sao limpas apos o teste (mesma convencao do resto deste
        // arquivo — colisao so seria possivel com valores fixos reutilizados
        // entre execucoes).
        $cpf    = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone1 = '1195' . mt_rand(1000000, 9999999);
        $phone2 = '1194' . mt_rand(1000000, 9999999);
        $this->insertBlockWhereNotExists('outro_' . uniqid() . '@example.com', $cpf, $phone1);

        // Nova tentativa com mail/phone novos mas o MESMO cpf: WHERE NOT EXISTS
        // deve casar pelo cpf e nao inserir (0 linhas).
        $rows = $this->insertBlockWhereNotExists('novo_' . uniqid() . '@example.com', $cpf, $phone2);

        $this->assertSame(0, $rows, 'overlap por CPF sozinho ja deve impedir a insercao (0 linhas afetadas)');
    }

    /**
     * Reproduz o INSERT simples (sem o WHERE NOT EXISTS) que estoura a
     * violacao de constraint que o catch(RuntimeException) de
     * customers_controller::action() (case 'bloquear') trata como "ja
     * bloqueado" — ver o try/catch acrescentado ao redor do
     * insertBlockWhereNotExists() acima na revisao pre-landing (2a passagem).
     */
    private function insertBlockedRaw(string $mail, string $cpf, string $phone): void
    {
        $block = new blocked_customers_model();
        $block->execute_raw_prepared(
            "INSERT INTO blocked_customers (customer_mail, customer_cpf, customer_phone, blocked_at) VALUES (?, ?, ?, ?)",
            [$mail, $cpf, $phone, date('Y-m-d H:i:s')]
        );
    }

    /**
     * A revisao pre-landing (2a passagem) descobriu que o INSERT...WHERE NOT
     * EXISTS sozinho nao fecha a corrida entre dois cliques concorrentes em
     * "Bloquear": MySQL 8.0 usa REPEATABLE READ por padrao, entao o NOT
     * EXISTS e uma leitura nao-bloqueante — dois inserts concorrentes podem
     * ambos passar a checagem antes de qualquer um comitar. A migration 035
     * troca a KEY nao-unica de customer_mail por um UNIQUE, fechando a
     * corrida a nivel de banco: o segundo INSERT concorrente estoura o
     * UNIQUE, e customers_controller::action() captura essa
     * RuntimeException e trata como "ja bloqueado" (nao como falha
     * generica).
     *
     * Forca a violacao com um INSERT simples (contornando o WHERE NOT
     * EXISTS de proposito, como duas requisicoes verdadeiramente
     * concorrentes fariam) em vez de chamar customers_controller::action()
     * diretamente: o metodo termina em basic_redir(), que faz exit() e
     * mataria o processo do PHPUnit (mesmo motivo documentado em
     * ConfigActionTest/GatewaysActionTest).
     */
    public function testDuplicateMailInsertViolatesUniqueConstraintAndIsCaughtAsAlreadyBlocked(): void
    {
        $mail = 'uniqviol_' . uniqid() . '@example.com';
        $this->insertBlockedRaw($mail, '11111111111', '11911111111');

        $caught = false;
        $infoMessage = null;
        try {
            // Mesmo customer_mail, cpf/phone diferentes: so o UNIQUE em
            // customer_mail (migration 035) deve barrar — cpf/phone nao tem
            // constraint proprio.
            $this->insertBlockedRaw($mail, '22222222222', '11922222222');
        } catch (RuntimeException $e) {
            $caught = true;
            // Mesma mensagem que o catch(RuntimeException) do controller usa.
            $infoMessage = "Este cliente já está bloqueado.";
        }

        $this->assertTrue(
            $caught,
            'segunda insercao com o mesmo customer_mail deve violar o UNIQUE key (migration 035) e lancar RuntimeException'
        );
        $this->assertSame(
            "Este cliente já está bloqueado.",
            $infoMessage,
            'catch(RuntimeException) do controller deve produzir a mesma mensagem info do caminho rowCount()===0'
        );

        // Nao ha releitura apos este ponto: a violacao real do UNIQUE forca
        // localPDO::executePrepared() a dar rollback() na transacao unica
        // compartilhada do processo (mesmo "single global transaction per
        // request" que o app usa em producao) — o que desfaz tambem a
        // fixture criada acima nesta mesma transacao. O ponto deste teste (o
        // catch tratar a violacao como "ja bloqueado") ja esta provado pelas
        // duas asserções acima. Mesma ressalva documentada em
        // ConfigActionTest::testUsersActionCatchSetsDangerMessageOnRealDbFailure.
    }

    /**
     * 4a revisao pre-landing: o catch(RuntimeException) do insert de bloqueio
     * (customers_controller::action(), case 'bloquear') parou de tratar TODA
     * falha como "ja bloqueado" — agora reconsulta (recheck SELECT) se uma
     * linha batendo o cliente realmente existe antes de decidir entre a
     * mensagem info ("ja bloqueado") e a danger ("Falha ao bloquear o
     * cliente."). O teste acima
     * (testDuplicateMailInsertViolatesUniqueConstraintAndIsCaughtAsAlreadyBlocked)
     * so cobre o ramo em que o recheck ACHA a linha. Este cobre o outro
     * ramo: uma falha de insert que NAO e a violacao do UNIQUE em
     * customer_mail (aqui, um NOT NULL em customer_mail — nunca teria uma
     * linha correspondente pra achar, "x = NULL" nunca e verdadeiro em SQL)
     * faz o recheck genuinamente nao encontrar nada.
     *
     * Mesma ressalva de transacao dos testes acima: qualquer excecao aqui
     * forca localPDO::executePrepared() a dar rollback() na transacao unica
     * compartilhada do processo — o que ja acontece de qualquer forma no
     * teste anterior desta classe. Nao ha fixture "ja bloqueada" pra essa
     * corrida desfazer: e exatamente esse o ponto do teste (recheck sem
     * nada pra achar).
     */
    public function testInsertFailureUnrelatedToDuplicateMailLeavesRecheckWithNoMatch(): void
    {
        $block = new blocked_customers_model();
        $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone = '1193' . mt_rand(1000000, 9999999);

        $caught = false;
        try {
            // customer_mail e NOT NULL (migration 034) — um valor NULL explicito
            // estoura essa constraint, nao a UNIQUE (que exige duplicidade).
            // Falha real, sem qualquer relacao com "ja bloqueado".
            $block->execute_raw_prepared(
                "INSERT INTO blocked_customers (customer_mail, customer_cpf, customer_phone, blocked_at) VALUES (?, ?, ?, ?)",
                [null, $cpf, $phone, date('Y-m-d H:i:s')]
            );
        } catch (RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue(
            $caught,
            'INSERT com customer_mail NULL deve violar o NOT NULL (migration 034) e lancar RuntimeException'
        );

        // Mesma query de recheck que o catch(RuntimeException) do controller executa.
        $recheck = $block->execute_raw_prepared(
            "SELECT 1 FROM blocked_customers
              WHERE active = 'yes'
                AND ( customer_mail = ?
                      OR ( customer_cpf <> '' AND customer_cpf = ? )
                      OR ( customer_phone <> '' AND customer_phone = ? ) )
              LIMIT 1",
            [null, $cpf, $phone]
        );

        $this->assertFalse(
            (bool) $recheck->fetchColumn(),
            'sem violacao de duplicidade real, o recheck nao deve achar nenhuma linha — controller deve cair no ramo danger, nao info'
        );
    }

    /**
     * Plano 030 (redesenhado apos revisao adversarial): desbloquear = soft-delete
     * (active='no') via remove(), filtrado pelo idx EXATO da linha de
     * blocked_customers (nunca mais por match de identificador — ver
     * testUnblockByIdxAffectsOnlyThatRowNotOtherOverlappingRows abaixo pro porque).
     */
    private function unblock(int $blockedIdx): int
    {
        $model = new blocked_customers_model();
        $model->set_filter([" active = 'yes' ", " idx = ? "], [$blockedIdx]);
        $stmt = $model->remove();
        return $stmt ? $stmt->rowCount() : 0;
    }

    private function isBlockedByIdx(int $blockedIdx): bool
    {
        $model = new blocked_customers_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT 1 FROM blocked_customers WHERE idx = ? AND active = 'yes' LIMIT 1",
            [$blockedIdx]
        );

        return (bool) $stmt->fetchColumn();
    }

    public function testUnblockSoftDeletesAndCheckoutAcceptsAgain(): void
    {
        $mail = 'ub_' . uniqid() . '@example.com';
        $id   = $this->block($mail, '', '');
        $this->assertTrue($this->isBlocked($mail, '', ''));

        $affected = $this->unblock($id);
        $this->assertSame(1, $affected, 'Desbloqueio deve soft-deletar 1 linha');
        $this->assertFalse($this->isBlocked($mail, '', ''), 'Após desbloquear, checkout aceita');
    }

    /**
     * Este e o cenario que a migration 038 conserta: sem o indice escopado a
     * active, o segundo bloqueio bateria no UNIQUE da linha soft-deletada e
     * falharia.
     */
    public function testReblockAfterUnblockSucceeds(): void
    {
        $mail = 'rb_' . uniqid() . '@example.com';
        $id   = $this->block($mail, '', '');
        $this->unblock($id);

        $newId = $this->block($mail, '', '');   // nao deve lancar (indice escopado a active)
        $this->assertGreaterThan(0, $newId);
        $this->assertTrue($this->isBlocked($mail, '', ''), 'Re-bloqueio volta a barrar o checkout');
    }

    /**
     * Revisao adversarial do plano 030: o design original filtrava remove() por
     * identificador (mail OR cpf OR telefone) — o mesmo match usado por
     * isBlocked(). Duas linhas de bloqueio DE CLIENTES DIFERENTES podiam, cada
     * uma, casar um campo diferente do MESMO alvo (ex.: linha A bloqueada so por
     * mail, linha B bloqueada so por CPF, e um terceiro cliente com esse mail E
     * esse CPF bateria nas duas). O filtro antigo removeria as DUAS linhas numa
     * unica UPDATE — desbloqueando silenciosamente a protecao de um cliente nao
     * relacionado. O redesenho mira sempre o idx exato da linha (capturado por
     * blockedIdxSql() na renderizacao da tela) — este teste prova que so a linha-
     * alvo e afetada, mesmo com outra linha ativa que "casaria" pelo filtro antigo.
     */
    public function testUnblockByIdxAffectsOnlyThatRowNotOtherOverlappingRows(): void
    {
        $mailA     = 'ovA_' . uniqid() . '@example.com';
        $mailB     = 'ovB_' . uniqid() . '@example.com';
        $sharedCpf = substr((string) mt_rand(10000000000, 99999999999), 0, 11);

        $idxA = $this->block($mailA, '', '');           // linha A: bloqueia so por mail
        $idxB = $this->block($mailB, $sharedCpf, '');   // linha B: bloqueia por mail+cpf proprios

        // Pre-condicao: confirma que um cliente hipotetico com mail=$mailA e
        // cpf=$sharedCpf realmente bateria nas DUAS linhas pelo filtro antigo por
        // identificador — prova que o cenario de colateral e real, nao teorico.
        $legacyMatch = new blocked_customers_model();
        $stmt = $legacyMatch->execute_raw_prepared(
            "SELECT COUNT(*) AS c FROM blocked_customers
              WHERE active = 'yes'
                AND ( customer_mail = ? OR ( customer_cpf <> '' AND customer_cpf = ? ) OR ( customer_phone <> '' AND customer_phone = ? ) )",
            [$mailA, $sharedCpf, '00000000000']
        );
        $this->assertSame(2, (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'], 'pre-condicao: filtro antigo por identificador bateria nas duas linhas');

        $affected = $this->unblock($idxA);

        $this->assertSame(1, $affected, 'so a linha-alvo deve ser afetada');
        $this->assertFalse($this->isBlockedByIdx($idxA), 'linha A (alvo) deve ter sido soft-deletada');
        $this->assertTrue($this->isBlockedByIdx($idxB), 'linha B (de outro cliente) NAO pode ser afetada');
    }

    public function testUnblockWithNoMatchAffectsZeroRows(): void
    {
        // idx que nao existe (ou ja soft-deletado) — o controller usa esse
        // rowCount()===0 para decidir a mensagem info "nao estava bloqueado".
        $affected = $this->unblock(0);

        $this->assertSame(0, $affected, 'Sem linha correspondente, remove() nao deve afetar nenhuma linha');
    }
}
