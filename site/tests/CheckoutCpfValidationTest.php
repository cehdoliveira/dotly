<?php

declare(strict_types=1);

/**
 * Cobre a validacao de digito verificador de CPF (modulo 11) no checkout
 * publico (plano 039): checkout_controller::validateCustomer() passou a
 * usar validate_cpf() em vez de checar apenas o comprimento (strlen === 11).
 * CPF estruturalmente invalido (ex.: 11111111111, ou digito verificador
 * errado) e flag direto nos sistemas antifraude do Mercado Pago/PagBank.
 *
 * validateCustomer() e privado e nao pode ser chamado via finalize() (termina
 * em basic_redir() -> exit()) — testado via ReflectionMethod, mesmo padrao de
 * CheckoutCustomerBlockTest. Estende DBTestCase porque validateCustomer()
 * chama isBlocked() (toca DB).
 */
final class CheckoutCpfValidationTest extends DBTestCase
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

    public function testValidCpfPassesValidation(): void
    {
        $result = $this->validateCustomer($this->validPost());

        $this->assertIsArray($result, 'CPF valido pelo modulo 11 deve passar pela validacao');
        $this->assertSame('52998224725', $result['cpf']);
    }

    public function testRepeatedDigitsCpfIsRejected(): void
    {
        $result = $this->validateCustomer($this->validPost(['cpf' => '11111111111']));

        $this->assertNull($result, 'sequencia de digitos repetidos nao e CPF valido');
        $this->assertSame(
            ["Informe um CPF válido."],
            $_SESSION["messages_app"]["danger"] ?? null
        );
    }

    public function testWrongCheckDigitCpfIsRejected(): void
    {
        $result = $this->validateCustomer($this->validPost(['cpf' => '52998224724']));

        $this->assertNull($result, 'digito verificador incorreto deve reprovar o CPF');
    }
}
