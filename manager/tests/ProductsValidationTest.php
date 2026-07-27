<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre products_controller::validate() (conversao de preco em centavos, slug
 * derivado/validado). E chamada via Reflection (o metodo e private, seguindo o
 * mesmo padrao de validacao inline usado em outros controllers deste projeto).
 *
 * Plano 023: `category` deixou de ser texto livre — a taxonomia mora em
 * `categories` e a relacao em `products_categories`. validate() agora so exige
 * um `categories_id` > 0; a existencia da categoria e checada em action()
 * (contexto de banco), para validate() continuar puro e testavel sem DB.
 */
final class ProductsValidationTest extends TestCase
{
    /**
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function callValidate(array $post): array
    {
        $controller = new products_controller();
        $method     = new ReflectionMethod($controller, 'validate');
        $method->setAccessible(true);

        return $method->invoke($controller, $post);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['messages_app'] = [];
    }

    public function testPriceUnitCentsConvertsBrazilianCurrencyFormat(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'categories_id'    => 1,
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertTrue($valid);
        $this->assertSame(7000, $data['price_unit_cents']);
    }

    public function testInvalidSlugIsRejected(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'Slug Inválido!!',
            'categories_id'    => 1,
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testEmptySlugIsDerivedFromName(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Ipamorelin 5mg',
            'slug'             => '',
            'categories_id'    => 1,
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertTrue($valid);
        $this->assertSame('ipamorelin-5mg', $data['slug']);
    }

    public function testPriceUnitCentsZeroIsRejected(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'categories_id'    => 1,
            'price_unit_cents' => 'R$ 0,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testMissingNameIsRejected(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => '',
            'slug'             => 'produto-teste',
            'categories_id'    => 1,
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testEmptyCategoryIdIsRejected(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'categories_id'    => '',
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testMissingCategoryIsRejected(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testZeroCategoryIdIsRejected(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'categories_id'    => '0',
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testValidCategoryIdIsReturnedAsInt(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'categories_id'    => '7',
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertTrue($valid);
        $this->assertSame(7, $data['categories_id']);
        $this->assertArrayNotHasKey('category', $data, 'validate() nao devolve mais nome de categoria');
    }
}
