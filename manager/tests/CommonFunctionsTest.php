<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/inc/lists.php';

use PHPUnit\Framework\TestCase;

final class CommonFunctionsTest extends TestCase
{
    public function testGenerateKeyDefaultSize(): void
    {
        $key = generate_key();
        $this->assertIsString($key);
        $this->assertEquals(10, strlen($key));
    }

    public function testGenerateKeyCustomSize(): void
    {
        $key = generate_key(20);
        $this->assertEquals(20, strlen($key));
    }

    public function testGenerateSlug(): void
    {
        $slug = generate_slug("São Paulo");
        $this->assertEquals("sao-paulo", $slug);
    }

    public function testGenerateSlugWithSpaces(): void
    {
        $slug = generate_slug("Hello World");
        $this->assertEquals("hello-world", $slug);
    }

    public function testRemoveAccents(): void
    {
        $text = remove_accents("José São Água");
        $this->assertEquals("Jose Sao Agua", $text);
    }

    public function testUpAccents(): void
    {
        $text = up_accents("josé");
        $this->assertEquals("JOSÉ", $text);
    }

    public function testDownAccents(): void
    {
        $text = down_accents("JOSÉ");
        $this->assertEquals("josé", $text);
    }

    public function testSanitizeString(): void
    {
        $text = sanitize_string("abc123!@");
        $this->assertEquals("abc123", $text);
    }

    public function testSanitizeStringDigitsOnly(): void
    {
        $text = sanitize_string("CPF 123.456.789-00", true);
        $this->assertEquals("12345678900", $text);
    }

    public function testSanitizeStringNullReturnsNull(): void
    {
        $this->assertNull(sanitize_string(null));
    }

    public function testSetUrlAddsParams(): void
    {
        $url = set_url("http://example.com", ["page" => "2"]);
        $this->assertStringContainsString("page=2", $url);
    }

    public function testSetUrlPreservesExistingParams(): void
    {
        $url = set_url("http://example.com?a=1", ["b" => "2"]);
        $this->assertStringContainsString("a=1", $url);
        $this->assertStringContainsString("b=2", $url);
    }

    public function testSetUrlOverridesExistingParam(): void
    {
        $url = set_url("/lista?page=1&sort=asc", ["page" => "2"]);
        $this->assertSame("/lista?sort=asc&page=2", $url);
    }

    public function test_csv_sanitize_cell_prefixes_formula_leading_chars(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", csv_sanitize_cell('=HYPERLINK("x")'));
        $this->assertSame("'+1+1", csv_sanitize_cell('+1+1'));
        $this->assertSame("'-2", csv_sanitize_cell('-2'));
        $this->assertSame("'@SUM(1)", csv_sanitize_cell('@SUM(1)'));
        // Benign values pass through untouched
        $this->assertSame('Carlos', csv_sanitize_cell('Carlos'));
        $this->assertSame('a@b.com', csv_sanitize_cell('a@b.com')); // '@' only matched at position 0
        $this->assertSame('', csv_sanitize_cell(null));
    }

    public function testSetUrlPreservesValueWithEquals(): void
    {
        $url = set_url("http://example.com?redirect=a=b", ["page" => "1"]);
        $this->assertStringContainsString("redirect=a=b", $url);
    }

    public function testSetUrlHandlesValuelessSegment(): void
    {
        // Must not emit a warning and must keep the flag
        $url = set_url("http://example.com?debug&x=1", ["y" => "2"]);
        $this->assertStringContainsString("debug=", $url);
        $this->assertStringContainsString("x=1", $url);
    }

    public function testSetUrlWithArrayValueRepeatsKeyWithBracketSuffix(): void
    {
        // Suporte a multi-selecao (ex.: filtro de status em /pedidos):
        // status[]=pago&status[]=enviado, um par por valor do array.
        $url = set_url("http://example.com", ["status" => ["pago", "enviado"]]);
        $this->assertSame("http://example.com?status[]=pago&status[]=enviado", $url);
    }

    public function testSetUrlWithArrayValueUrlEncodesEachEntryAndKeepsScalarParams(): void
    {
        $url = set_url("/pedidos", ["status" => ["a b", "c&d"], "page" => "2"]);

        $this->assertStringContainsString("status[]=a+b", $url, 'espaco no valor deve ser url-encoded');
        $this->assertStringContainsString("status[]=c%26d", $url, '& no valor deve ser url-encoded, nao virar novo par');
        $this->assertStringContainsString("page=2", $url, 'parametro escalar combinado com array nao deve ser perdido');
    }

    public function test_canonical_url_uses_configured_constant(): void
    {
        // Usa uma constante dedicada ao teste: SITE_CANONICAL_URL pode já vir
        // definida (vazia) pelo kernel de teste, o que dispararia o fail-closed
        // de canonical_url. Uma constante própria garante o branch "configurado".
        if (!defined('TEST_CANONICAL_URL')) {
            define('TEST_CANONICAL_URL', 'http://app.local');
        }
        $this->assertSame('http://app.local', canonical_url('TEST_CANONICAL_URL'));
    }

    /**
     * @dataProvider validSlugProvider
     */
    public function test_valid_slug_accepts_valid_formats(string $slug): void
    {
        $this->assertTrue(valid_slug($slug), "Esperava que '$slug' fosse um slug valido");
    }

    public static function validSlugProvider(): array
    {
        return [
            ['admin'],
            ['user'],
            ['meu-perfil'],
            ['a_b1'],
            ['x9'],
        ];
    }

    /**
     * @dataProvider invalidSlugProvider
     */
    public function test_valid_slug_rejects_invalid_formats(?string $slug): void
    {
        $this->assertFalse(valid_slug($slug), 'Esperava que o slug fosse invalido');
    }

    public static function invalidSlugProvider(): array
    {
        return [
            [''],
            [null],
            ['Admin'],
            ['meu perfil'],
            ['até'],
            ['-x'],
            ['x-'],
            ['a--b'],
            ['a__b'],
            ['a-'],
        ];
    }

    /**
     * a_walk() alimenta json_response() em todo endpoint JSON do app. Colunas
     * de banco opcionais (nulas) nao podem derrubar o encoding com TypeError
     * (ver plano 006 do site — bug real: GET /produto/{slug}?format=json 500'ava
     * pra qualquer produto sem description/dosage/purity_label preenchidos).
     * lib/ e byte-identico entre site/ e manager/, entao o mesmo bug existia aqui.
     */
    public function testAWalkPassesNullThrough(): void
    {
        $data = ['dosage' => null, 'purity_label' => null, 'name' => 'José'];

        $result = a_walk($data);

        $this->assertNull($result['dosage']);
        $this->assertNull($result['purity_label']);
        $this->assertSame('José', $result['name']);
    }

    public function testAWalkPassesNullThroughInNestedArray(): void
    {
        $data = ['product' => ['dosage' => null, 'name' => 'Peptídeo']];

        $result = a_walk($data);

        $this->assertNull($result['product']['dosage']);
        $this->assertSame('Peptídeo', $result['product']['name']);
    }

    /**
     * @dataProvider validCpfProvider
     */
    public function test_validate_cpf_accepts_valid_cpfs(string $cpf): void
    {
        $this->assertTrue(validate_cpf($cpf), "Esperava que '$cpf' fosse um CPF valido");
    }

    public static function validCpfProvider(): array
    {
        return [
            'sem mascara' => ['52998224725'],
            'com mascara' => ['529.982.247-25'],
            'fixture do repo (12345678909)' => ['12345678909'],
        ];
    }

    /**
     * @dataProvider invalidCpfProvider
     */
    public function test_validate_cpf_rejects_invalid_cpfs(string $cpf): void
    {
        $this->assertFalse(validate_cpf($cpf), "Esperava que '$cpf' fosse um CPF invalido");
    }

    public static function invalidCpfProvider(): array
    {
        return [
            'digito verificador errado' => ['52998224724'],
            'sequencia repetida (1)' => ['11111111111'],
            'sequencia repetida (0)' => ['00000000000'],
            'comprimento curto (10)' => ['1234567890'],
            'comprimento longo (12)' => ['123456789012'],
            'vazio' => [''],
        ];
    }
}
