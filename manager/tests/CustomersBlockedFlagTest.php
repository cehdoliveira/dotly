<?php

declare(strict_types=1);

/**
 * Cobre a coluna `is_blocked` tal como ela realmente aparece nas duas queries
 * de leitura de customers_controller (index() e show()): ambas embutem
 * blockedExistsSql() como coluna calculada. CustomerBlockTest ja cobre o
 * MATCH da blocklist isoladamente (uma query solta, sem o restante do SELECT);
 * CustomersViewTest/CustomerDetailViewTest cobrem a view com `is_blocked` ja
 * pronto como fixture. Nenhum dos dois prova que blockedExistsSql() encaixa
 * corretamente como subquery correlacionada dentro do SELECT real de
 * index()/show() (aliases 'o' e 'orders' respectivamente) — e essa integracao
 * que esta suite fecha.
 */
final class CustomersBlockedFlagTest extends DBTestCase
{
    private function blockedExistsSql(string $alias): string
    {
        $controller = new customers_controller();
        $method     = new ReflectionMethod($controller, 'blockedExistsSql');
        $method->setAccessible(true);

        return $method->invoke($controller, $alias);
    }

    /**
     * Plano 030: mesma integracao de blockedExistsSql acima, so que para
     * blockedIdxSql() — o scalar subquery que o botao Desbloquear usa pra mirar o
     * idx exato da linha de blocked_customers (em vez de um match por
     * identificador no submit, que poderia atingir a linha de outro cliente).
     */
    private function blockedIdxSql(string $alias): string
    {
        $controller = new customers_controller();
        $method     = new ReflectionMethod($controller, 'blockedIdxSql');
        $method->setAccessible(true);

        return $method->invoke($controller, $alias);
    }

    private function makeOrder(string $mail, string $cpf, string $phone): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => 'pago',
            'customer_name'  => 'Cliente Flag',
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

    private function block(string $mail, string $cpf, string $phone): void
    {
        $model = new blocked_customers_model();
        $model->populate([
            'customer_mail'  => $mail,
            'customer_cpf'   => $cpf,
            'customer_phone' => $phone,
            'blocked_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $model->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de bloqueio deve retornar um ID valido');
    }

    public function testIndexListingFlagsBlockedCustomerAsBlocked(): void
    {
        $blockedMail = 'blkflag_' . uniqid() . '@example.com';
        $cleanMail   = 'cleanflag_' . uniqid() . '@example.com';

        $this->makeOrder($blockedMail, '11111111111', '11911111111');
        $this->makeOrder($cleanMail, '22222222222', '11922222222');
        $this->block($blockedMail, '', '');

        // Mesma forma da query de listagem de customers_controller::index(): o
        // pedido-ancora e aliasado 'o', e blockedExistsSql('o') vira coluna.
        $model = new orders_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT o.customer_mail, " . $this->blockedExistsSql('o') . " AS is_blocked
               FROM orders o
              WHERE o.active = 'yes' AND o.customer_mail IN (?, ?)",
            [$blockedMail, $cleanMail]
        );

        $byMail = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byMail[$row['customer_mail']] = (bool) $row['is_blocked'];
        }

        $this->assertTrue($byMail[$blockedMail] ?? null, 'cliente bloqueado deve vir com is_blocked=1 na listagem');
        $this->assertFalse($byMail[$cleanMail] ?? null, 'cliente sem bloqueio deve vir com is_blocked=0 na listagem');
    }

    public function testShowAnchorFlagsBlockedCustomer(): void
    {
        $blockedMail = 'blkshow_' . uniqid() . '@example.com';
        $orderIdx    = $this->makeOrder($blockedMail, '33333333333', '11933333333');
        $this->block($blockedMail, '', '');

        // Mesma forma da query-ancora de customers_controller::show(): SEM
        // alias (a tabela e referenciada pelo proprio nome), filtrada por idx.
        $model = new orders_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT customer_mail, " . $this->blockedExistsSql('orders') . " AS is_blocked
               FROM orders
              WHERE active = 'yes' AND idx = ?
              LIMIT 1",
            [$orderIdx]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertTrue((bool) $row['is_blocked'], 'ancora de /clientes/{idx} deve refletir o bloqueio do cliente');
    }

    public function testIndexListingExposesBlockedIdxForBlockedCustomer(): void
    {
        // Identificadores aleatorios, nunca literais fixos: blocked_customers
        // acumula linhas de teste nao limpas entre execucoes (mesma convencao de
        // CustomerBlockTest::testInsertWhereNotExistsNoOpsWhenAnyIdentifierAlreadyBlocked).
        $blockedMail = 'blkidx_' . uniqid() . '@example.com';
        $cleanMail   = 'cleanidx_' . uniqid() . '@example.com';
        $blockedCpf  = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $cleanCpf    = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $blockedPhone = '1193' . mt_rand(1000000, 9999999);
        $cleanPhone   = '1192' . mt_rand(1000000, 9999999);

        $this->makeOrder($blockedMail, $blockedCpf, $blockedPhone);
        $this->makeOrder($cleanMail, $cleanCpf, $cleanPhone);
        $this->block($blockedMail, '', '');

        $model = new orders_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT o.customer_mail, " . $this->blockedIdxSql('o') . " AS blocked_idx
               FROM orders o
              WHERE o.active = 'yes' AND o.customer_mail IN (?, ?)",
            [$blockedMail, $cleanMail]
        );

        $byMail = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byMail[$row['customer_mail']] = $row['blocked_idx'];
        }

        $this->assertArrayHasKey($blockedMail, $byMail);
        $this->assertArrayHasKey($cleanMail, $byMail);
        $this->assertNotNull($byMail[$blockedMail], 'cliente bloqueado deve vir com blocked_idx preenchido na listagem');
        $this->assertNull($byMail[$cleanMail], 'cliente sem bloqueio deve vir com blocked_idx NULL na listagem');
    }

    public function testShowAnchorExposesBlockedIdxMatchingCpfOrPhoneOnly(): void
    {
        // Bloqueio casa por CPF (mail/telefone do pedido sao outros) — mesma
        // semantica de match de blockedExistsSql, agora provando que
        // blockedIdxSql() tambem resolve o idx correto nesses casos.
        $cpf      = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        // Telefone aleatorio, nunca literal fixo — blocked_customers acumula linhas
        // de teste nao limpas entre execucoes (mesma convencao documentada em
        // CustomerBlockTest::testInsertWhereNotExistsNoOpsWhenAnyIdentifierAlreadyBlocked);
        // um literal fixo colidiria com uma linha antiga e quebraria o LIMIT 1 sem ORDER BY.
        $phone    = '1194' . mt_rand(1000000, 9999999);
        $orderIdx = $this->makeOrder('anyone_' . uniqid() . '@example.com', $cpf, $phone);

        $blockModel = new blocked_customers_model();
        $blockModel->populate([
            'customer_mail'  => 'outromail_' . uniqid() . '@example.com',
            'customer_cpf'   => $cpf,
            'customer_phone' => '',
            'blocked_at'     => date('Y-m-d H:i:s'),
        ]);
        $blockedIdx = (int) $blockModel->save();
        $this->assertGreaterThan(0, $blockedIdx);

        $model = new orders_model();
        $stmt  = $model->execute_raw_prepared(
            "SELECT " . $this->blockedIdxSql('orders') . " AS blocked_idx
               FROM orders
              WHERE active = 'yes' AND idx = ?
              LIMIT 1",
            [$orderIdx]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame($blockedIdx, (int) $row['blocked_idx'], 'blockedIdxSql deve resolver o idx da linha que casa por CPF');
    }
}
