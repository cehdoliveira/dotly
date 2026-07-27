<?php

declare(strict_types=1);

/**
 * Plano 023: cobre a taxonomia de categorias de ponta a ponta contra o banco —
 * products_model::CATEGORY_NAME_FIELD resolvendo o nome via subquery escalar,
 * produto sem ligacao lendo como string vazia, save_attach() trocando a
 * categoria (desativando a anterior em vez de deletar), e
 * products_model::CATEGORY_NAME_FILTER casando so os produtos ligados.
 */
final class ProductsCategoryTaxonomyTest extends DBTestCase
{
    private function createCategory(array $overrides = []): int
    {
        $model = new categories_model();
        $model->populate(array_merge([
            'name' => 'Categoria Teste ' . uniqid(),
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
            'name'             => 'Produto Taxonomia ' . uniqid(),
            'slug'             => 'produto-taxonomia-' . uniqid(),
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

    public function testCategoryNameFieldResolvesLinkedCategory(): void
    {
        $categoryName = 'Peptideos ' . uniqid();
        $categoryId   = $this->createCategory(['name' => $categoryName]);
        $productId    = $this->createProduct();

        $this->attachCategory($productId, $categoryId);

        $stmt = (new products_model())->select(
            [" idx ", products_model::CATEGORY_NAME_FIELD],
            "WHERE idx = ?",
            [$productId]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($categoryName, $row['category']);
    }

    public function testProductWithoutCategoryReadsAsEmptyString(): void
    {
        $productId = $this->createProduct();

        $stmt = (new products_model())->select(
            [" idx ", products_model::CATEGORY_NAME_FIELD],
            "WHERE idx = ?",
            [$productId]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('', (string)$row['category']);
    }

    public function testSaveAttachReplacesPreviousCategory(): void
    {
        $categoryAId = $this->createCategory(['name' => 'Categoria A ' . uniqid()]);
        $categoryBName = 'Categoria B ' . uniqid();
        $categoryBId = $this->createCategory(['name' => $categoryBName]);
        $productId   = $this->createProduct();

        $this->attachCategory($productId, $categoryAId);
        $this->attachCategory($productId, $categoryBId);

        $stmt = (new products_model())->select(
            [" idx ", products_model::CATEGORY_NAME_FIELD],
            "WHERE idx = ?",
            [$productId]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($categoryBName, $row['category']);

        // Consulta direta a tabela de attach (select() do products_model e sempre
        // FROM products — nao serve para contar linhas de products_categories).
        // Usa a conexao singleton do model (DOLModel::getCon()), nao uma nova
        // localPDO(): os models gravam via localPDO::getInstance() e a transacao
        // ainda esta aberta (nao commitada), entao uma conexao separada nao
        // enxergaria essas linhas ainda.
        $con = (new products_model())->getCon();
        $r = $con->executePrepared(
            "SELECT COUNT(*) AS total FROM products_categories WHERE products_id = ? AND active = 'yes'",
            [$productId]
        );
        $row2 = $con->results($r)[0] ?? ['total' => 0];

        $this->assertSame(1, (int)$row2['total'], 'save_attach() desativa a ligacao anterior em vez de acumular');
    }

    public function testCategoryNameFilterMatchesOnlyLinkedProducts(): void
    {
        $categoryAName = 'Filtro A ' . uniqid();
        $categoryAId   = $this->createCategory(['name' => $categoryAName]);
        $categoryBId   = $this->createCategory(['name' => 'Filtro B ' . uniqid()]);

        $productA = $this->createProduct();
        $productB = $this->createProduct();
        $this->attachCategory($productA, $categoryAId);
        $this->attachCategory($productB, $categoryBId);

        $stmt = (new products_model())->select(
            [" idx "],
            "WHERE " . products_model::CATEGORY_NAME_FILTER . " AND idx IN (?, ?)",
            [$categoryAName, $productA, $productB]
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame($productA, (int)$rows[0]['idx']);
    }
}
