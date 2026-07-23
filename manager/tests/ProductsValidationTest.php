<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre products_controller::validate() (conversao de preco em centavos, slug
 * derivado/validado). E chamada via Reflection (o metodo e private, seguindo o
 * mesmo padrao de validacao inline usado em outros controllers deste projeto).
 *
 * Plano 023: taxonomia de categorias removida — `category` volta a ser texto livre
 * validado inline (obrigatorio, <= 60 chars, o tamanho da coluna `products.category`).
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
            'category'         => 'Peptídeos',
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
            'category'         => 'Peptídeos',
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
            'category'         => 'Peptídeos',
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
            'category'         => 'Peptídeos',
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
            'category'         => 'Peptídeos',
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
            'category'         => '',
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testCategoryOverSixtyCharsIsRejected(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'category'         => str_repeat('a', 61),
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertFalse($valid);
        $this->assertSame([], $data);
    }

    public function testCategoryIsTrimmedAndAccepted(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'category'         => '  Nootrópicos  ',
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertTrue($valid);
        $this->assertSame('Nootrópicos', $data['category']);
    }

    public function testCategoryInternalWhitespaceIsCollapsed(): void
    {
        [$valid, $data] = $this->callValidate([
            'name'             => 'Produto Teste',
            'slug'             => 'produto-teste',
            'category'         => 'Testosterona   Enantato',
            'price_unit_cents' => 'R$ 70,00',
        ]);

        $this->assertTrue($valid);
        $this->assertSame('Testosterona Enantato', $data['category']);
    }
}
