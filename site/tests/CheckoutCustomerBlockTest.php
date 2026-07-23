<?php

declare(strict_types=1);

/**
 * Cobre a rejeicao de cliente bloqueado no checkout publico (plano 023):
 * checkout_controller::validateCustomer() ganhou uma checagem contra
 * blocked_customers (gravada pelo manager em /clientes) antes de aceitar os
 * dados do comprador. Um cliente esta bloqueado se e-mail, CPF OU telefone
 * bater — mesmo contrato de CustomerBlockTest/customers_controller no
 * manager, mas exercitado aqui do lado do site, onde a rejeicao realmente
 * acontece.
 *
 * validateCustomer() e privado e nao pode ser chamado via finalize() (termina
 * em basic_redir() -> exit()) — testado via ReflectionMethod, mesmo padrao de
 * CheckoutStockTest para lockAndValidateCart() (que e publico, mas o
 * principio de "extrair para testar sem side-effect" e o mesmo).
 */
final class CheckoutCustomerBlockTest extends DBTestCase
{
    /** @var mixed */
    private $sessionBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup ?? [];
        parent::tearDown();
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

    /** @return array<string,mixed> */
    private function validPost(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'Cliente Teste',
            'mail'       => 'checkout_' . uniqid() . '@example.com',
            'phone'      => '11999999999',
            'cpf'        => '52998224725',
            'zip'        => '01310100',
            'street'     => 'Av. Paulista',
            'number'     => '1000',
            'complement' => '',
            'district'   => 'Bela Vista',
            'city'       => 'São Paulo',
            'uf'         => 'SP',
        ], $overrides);
    }

    /** @return array<string,mixed>|null */
    private function validateCustomer(array $post): ?array
    {
        $controller = new checkout_controller();
        $method     = new ReflectionMethod($controller, 'validateCustomer');
        $method->setAccessible(true);

        return $method->invoke($controller, $post);
    }

    public function testBlockedByMailIsRejected(): void
    {
        $mail = 'blk_mail_' . uniqid() . '@example.com';
        $this->block($mail, '00000000000', '11000000000');

        $result = $this->validateCustomer($this->validPost(['mail' => $mail]));

        $this->assertNull($result, 'cliente com e-mail bloqueado nao pode fechar pedido');
        $this->assertSame(
            ["Não foi possível concluir o pedido com estes dados. Entre em contato com o nosso suporte."],
            $_SESSION["messages_app"]["danger"] ?? null
        );
    }

    public function testBlockedByCpfIsRejected(): void
    {
        $cpf = '11144477735';
        $this->block('outro_' . uniqid() . '@example.com', $cpf, '11000000000');

        $result = $this->validateCustomer($this->validPost(['cpf' => $cpf]));

        $this->assertNull($result, 'cliente com CPF bloqueado nao pode fechar pedido');
    }

    public function testBlockedByPhoneIsRejected(): void
    {
        $phone = '1198' . mt_rand(1000000, 9999999);
        $this->block('outro_' . uniqid() . '@example.com', '00000000000', $phone);

        $result = $this->validateCustomer($this->validPost(['phone' => $phone]));

        $this->assertNull($result, 'cliente com telefone bloqueado nao pode fechar pedido');
    }

    public function testNonBlockedCustomerPassesValidation(): void
    {
        // Regressao: a checagem de bloqueio nao pode derrubar o fluxo normal de
        // checkout para um cliente que nunca foi bloqueado.
        $result = $this->validateCustomer($this->validPost());

        $this->assertIsArray($result, 'cliente sem bloqueio deve passar pela validacao normalmente');
        $this->assertArrayHasKey('mail', $result);
    }

    public function testEmptyBlockedIdentifiersDoNotCrossMatch(): void
    {
        // Bloqueio so por e-mail (cpf/telefone gravados como ''): um cliente
        // diferente que tambem tenha cpf/telefone vazios no POST nao pode casar
        // por essa lacuna — mesmo guard "<> '' AND = ?" coberto no manager por
        // CustomerBlockTest::testEmptyIdentifiersDoNotCrossMatch.
        $this->block('blk_only_mail_' . uniqid() . '@example.com', '', '');

        $result = $this->validateCustomer($this->validPost([
            'mail' => 'diferente_' . uniqid() . '@example.com',
        ]));

        $this->assertIsArray($result, 'CPF/telefone vazios do bloqueio nao devem casar outro cliente');
    }
}
