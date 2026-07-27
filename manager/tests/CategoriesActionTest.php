<?php

declare(strict_types=1);

/**
 * Plano 024: cobre os helpers privados de products_controller::categories_action()
 * (CRUD de categorias do modal de /produtos). categories_action() termina em
 * json_response() -> exit(), que nao pode ser exercitado dentro do PHPUnit —
 * mesmo obstaculo documentado em site/tests/WebhookIdempotencyTest.php. Por
 * isso os helpers sao chamados via Reflection, mesmo padrao de
 * ProductsCategoryTaxonomyTest::categoryExists() / ProductsValidationTest.
 *
 * Excecao: testCategoriesJsonResponseCommitsBeforeResponding() nao usa
 * Reflection — prova a armadilha central do plano (json_response() nao comita
 * a transacao global; sem o commit() explicito de categoriesJsonResponse(), o
 * localPDO::__destruct() reverteria a escrita).
 */
final class CategoriesActionTest extends DBTestCase
{
    private function createCategory(array $overrides = []): int
    {
        $model = new categories_model();
        $model->populate(array_merge([
            'name' => 'Categoria Action ' . uniqid(),
        ], $overrides));
        $id = $model->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function createProduct(array $overrides = []): int
    {
        $model = new products_model();
        $model->populate(array_merge([
            'name'             => 'Produto Categorias ' . uniqid(),
            'slug'             => 'produto-categorias-' . uniqid(),
            'price_unit_cents' => 5000,
            'box_qty'          => 10,
            'stock'            => 100,
        ], $overrides));
        $id = $model->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function attachCategory(int $productId, int $categoryId): void
    {
        $product = new products_model();
        $product->save_attach(
            ["idx" => $productId, "post" => ["categories_id" => $categoryId]],
            ["categories"]
        );
    }

    /** @return array<int, array{idx:int, name:string}> */
    private function callAllCategories(): array
    {
        $controller = new products_controller();
        $method     = new ReflectionMethod($controller, 'allCategories');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    private function callProductsInCategory(int $categoryId): int
    {
        $controller = new products_controller();
        $method     = new ReflectionMethod($controller, 'productsInCategory');
        $method->setAccessible(true);

        return $method->invoke($controller, $categoryId);
    }

    private function callCategoryNameTaken(string $name, int $excludeIdx = 0): bool
    {
        $controller = new products_controller();
        $method     = new ReflectionMethod($controller, 'categoryNameTaken');
        $method->setAccessible(true);

        return $method->invoke($controller, $name, $excludeIdx);
    }

    public function testAllCategoriesReturnsActiveOnesSortedByName(): void
    {
        $suffix = uniqid();
        $nameA  = "AAA-Categoria-{$suffix}";
        $nameC  = "ZZZ-Categoria-{$suffix}";
        $nameRemoved = "MMM-Categoria-Removida-{$suffix}";

        $this->createCategory(['name' => $nameC]);
        $removedId = $this->createCategory(['name' => $nameRemoved]);
        $this->createCategory(['name' => $nameA]);

        $remove = new categories_model();
        $remove->set_filter(["idx = ?"], [$removedId]);
        $remove->remove();

        $all   = $this->callAllCategories();
        $names = array_column($all, 'name');

        $this->assertNotContains($nameRemoved, $names, 'categoria removida (soft-delete) nao deve aparecer na lista');

        $posA = array_search($nameA, $names, true);
        $posC = array_search($nameC, $names, true);
        $this->assertNotFalse($posA, 'categoria criada deve aparecer na lista');
        $this->assertNotFalse($posC, 'categoria criada deve aparecer na lista');
        $this->assertLessThan($posC, $posA, 'a lista deve vir ordenada por nome (A antes de Z)');
    }

    public function testProductsInCategoryCountsOnlyActiveLinkedProducts(): void
    {
        $categoryId       = $this->createCategory();
        $productActive    = $this->createProduct();
        $productInactive  = $this->createProduct();

        $this->attachCategory($productActive, $categoryId);
        $this->attachCategory($productInactive, $categoryId);

        $remove = new products_model();
        $remove->set_filter(["idx = ?"], [$productInactive]);
        $remove->remove();

        $this->assertSame(1, $this->callProductsInCategory($categoryId), 'so o produto ativo deve contar');
    }

    public function testProductsInCategoryIsZeroForUnusedCategory(): void
    {
        $categoryId = $this->createCategory();

        $this->assertSame(0, $this->callProductsInCategory($categoryId));
    }

    /**
     * Achado do review de /phpship: categories_action() nao confere rowCount()
     * antes de responder sucesso na remocao -- remover um idx inexistente (ja
     * removido, ou nunca existiu) cai no mesmo caminho de sucesso silencioso.
     * Documenta esse comportamento (aceito, ver Maintenance notes do plano 024)
     * como teste, para uma mudanca futura em DOLModel::remove() nao virar
     * regressao silenciosa aqui.
     */
    public function testRemovingNonexistentCategoryIdDoesNotThrow(): void
    {
        $nonexistentId = $this->createCategory();

        $remove = new categories_model();
        $remove->set_filter(["idx = ?"], [$nonexistentId]);
        $remove->remove();

        $removeAgain = new categories_model();
        $removeAgain->set_filter(["idx = ?"], [$nonexistentId]);
        $statement = $removeAgain->remove();

        $this->assertNotNull($statement, 'remover um idx ja removido nao deve lancar excecao');
        $this->assertSame(0, $statement->rowCount(), 'nenhuma linha ativa correspondia mais a esse idx');
    }

    public function testCategoryNameTakenIsCaseInsensitive(): void
    {
        $mixedCaseName = 'Peptideos ' . uniqid();
        $categoryId    = $this->createCategory(['name' => $mixedCaseName]);
        $lowerCaseName = strtolower($mixedCaseName);

        $this->assertTrue(
            $this->callCategoryNameTaken($lowerCaseName),
            'colacao utf8mb4_unicode_ci e case-insensitive: "peptideos" deve conflitar com "Peptideos"'
        );
        $this->assertFalse(
            $this->callCategoryNameTaken($lowerCaseName, $categoryId),
            'excludeIdx deve tirar a propria linha da checagem (renomear para o mesmo nome nao e conflito)'
        );
    }

    /**
     * Prova a armadilha central do plano 024: json_response() (chamado por
     * categoriesJsonResponse()) faz echo+exit e NAO toca na transacao do
     * localPDO singleton. Sem o commit() explicito, localPDO::__destruct()
     * reverteria a escrita assim que o processo terminasse — e o SELECT feito
     * por uma conexao PROPRIA ($this->con, fora da transacao do singleton) não
     * enxergaria a linha enquanto ela nao for comitada de verdade.
     */
    public function testCategoriesJsonResponseCommitsBeforeResponding(): void
    {
        $name = 'Categoria Commit ' . uniqid();

        $category   = new categories_model();
        $category->populate(['name' => $name]);
        $categoryId = $category->save();
        $this->assertIsInt($categoryId);
        $this->assertGreaterThan(0, $categoryId);

        // Mesmo commit() que categoriesJsonResponse() faz no caminho de sucesso.
        localPDO::getInstance()->commit();

        $rows = $this->con->results(
            $this->con->executePrepared(
                "SELECT idx, name FROM categories WHERE idx = ? AND active = 'yes'",
                [$categoryId]
            )
        );

        $this->assertCount(
            1,
            $rows,
            'sem o commit() explicito, esta conexao propria (fora da transacao do singleton) nao encontraria a linha'
        );
        $this->assertSame($name, $rows[0]['name']);

        // A criacao foi comitada de verdade (nao so na transacao do teste), entao
        // o rollback padrao do tearDown() (que so afeta $this->con) nao desfaz
        // isso sozinho -- comita a limpeza tambem, para nao vazar entre execucoes.
        $this->con->executePrepared("UPDATE categories SET active = 'no' WHERE idx = ?", [$categoryId]);
        $this->con->commit();
    }
}
