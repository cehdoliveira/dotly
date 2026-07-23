<?php

declare(strict_types=1);

/**
 * Cobre a escrita real de customers_controller::tryBlockCustomer() (plano 036):
 * o refactor de INSERT...SELECT...WHERE NOT EXISTS para pre-checagem SELECT +
 * insert() do DOLModel. action() nao pode ser chamado diretamente (termina em
 * basic_redir() -> exit(), mesmo motivo documentado em GatewaysActionTest), mas
 * tryBlockCustomer() foi extraido justamente para isolar a escrita do fluxo de
 * mensagens/redirect e permitir teste direto via ReflectionMethod — mesmo padrao
 * de CustomersBlockedFlagTest/CheckoutCustomerBlockTest.
 *
 * CustomerBlockTest ja cobre o MATCH da blocklist (mail/cpf/telefone) isoladamente
 * via SQL reproduzido; esta suite fecha a lacuna de que ninguem provava que a
 * pre-checagem + insert() reais do controller escrevem a linha certa e respeitam
 * o "ja bloqueado" sem duplicar.
 */
final class CustomerBlockWriteTest extends DBTestCase
{
    private function tryBlockCustomer(string $mail, string $cpf, string $phone, int $orderIdx): string
    {
        $controller = new customers_controller();
        $method     = new ReflectionMethod($controller, 'tryBlockCustomer');
        $method->setAccessible(true);

        return $method->invoke($controller, $mail, $cpf, $phone, $orderIdx);
    }

    private function countBlocksFor(string $mail, string $cpf, string $phone): int
    {
        $model = new blocked_customers_model();
        $stmt  = $model->select(
            [" COUNT(*) AS total "],
            "WHERE active = 'yes'
                AND ( customer_mail = ?
                      OR ( customer_cpf <> '' AND customer_cpf = ? )
                      OR ( customer_phone <> '' AND customer_phone = ? ) )",
            [$mail, $cpf, $phone]
        );

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    public function testBlocksNewCustomerAndPersistsIdentifiers(): void
    {
        $mail  = 'blkwrite_' . uniqid() . '@example.com';
        $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone = '1195' . mt_rand(1000000, 9999999);

        $outcome = $this->tryBlockCustomer($mail, $cpf, $phone, 777);

        $this->assertSame('blocked', $outcome);

        $model = new blocked_customers_model();
        $stmt  = $model->select(
            [" customer_mail ", " customer_cpf ", " customer_phone ", " blocked_at ", " created_by "],
            "WHERE active = 'yes' AND customer_mail = ? LIMIT 1",
            [$mail]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'insert() deve gravar a linha de bloqueio');
        $this->assertSame($cpf, $row['customer_cpf']);
        $this->assertSame($phone, $row['customer_phone']);
        $this->assertNotNull($row['blocked_at']);
    }

    public function testPreCheckReturnsAlreadyBlockedWithoutDuplicating(): void
    {
        $mail  = 'blkwrite_dup_' . uniqid() . '@example.com';
        $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
        $phone = '1196' . mt_rand(1000000, 9999999);

        $first = $this->tryBlockCustomer($mail, $cpf, $phone, 778);
        $this->assertSame('blocked', $first);

        // Mesmo pedido (idx diferente, mesmo cliente) tentando bloquear de novo —
        // a pre-checagem deve pegar antes de qualquer insert.
        $second = $this->tryBlockCustomer($mail, $cpf, $phone, 779);
        $this->assertSame('already_blocked', $second);

        $this->assertSame(
            1,
            $this->countBlocksFor($mail, $cpf, $phone),
            'segunda chamada nao deve criar uma segunda linha de bloqueio'
        );
    }

    public function testPreCheckMatchesByCpfAloneWhenMailDiffers(): void
    {
        $cpf = substr((string) mt_rand(10000000000, 99999999999), 0, 11);

        $first = $this->tryBlockCustomer('original_' . uniqid() . '@example.com', $cpf, '', 780);
        $this->assertSame('blocked', $first);

        // E-mail diferente, mesmo CPF — a pre-checagem casa por qualquer
        // identificador, nao so e-mail.
        $second = $this->tryBlockCustomer('outro_' . uniqid() . '@example.com', $cpf, '', 781);
        $this->assertSame('already_blocked', $second);
    }
}
