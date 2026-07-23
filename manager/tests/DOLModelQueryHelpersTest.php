<?php

declare(strict_types=1);

/**
 * Cobre os helpers select()/update()/insert() do DOLModel: alias/join
 * opcionais no select() e no update(), guard contra placeholder em $join,
 * ordem de bind em update() com join+joinParams, e dedupe via insert()
 * com $suffix (ON DUPLICATE KEY UPDATE).
 */
final class DOLModelQueryHelpersTest extends DBTestCase
{
    private function createProduct(array $overrides = []): int
    {
        $model = new products_model();
        $model->populate(array_merge([
            'name'             => 'Produto Helpers ' . uniqid(),
            'slug'             => 'produto-helpers-' . uniqid(),
            'category'         => 'peptideos',
            'is_infinity'      => 'no',
            'price_unit_cents' => 5000,
            'box_qty'          => 10,
            'stock'            => 100,
        ], $overrides));
        $id = $model->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function createProductImage(int $productId): int
    {
        $model = new product_images_model();
        $model->populate([
            'products_id' => $productId,
            'path'        => 'products/helpers-' . uniqid() . '.webp',
        ]);
        $id = $model->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    public function testSelectSimpleReturnsMatchingRows(): void
    {
        $id1 = $this->createProduct();
        $id2 = $this->createProduct();

        $stmt = (new products_model())->select(
            [" idx ", " name "],
            "WHERE active = 'yes' AND idx IN (?, ?)",
            [$id1, $id2]
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
    }

    public function testSelectWithAliasAndJoin(): void
    {
        $productId = $this->createProduct(['name' => 'Produto Com Imagem ' . uniqid()]);
        $this->createProductImage($productId);

        $stmt = (new product_images_model())->select(
            [" pi.idx ", " p.name "],
            "WHERE pi.products_id = ?",
            [$productId],
            "pi",
            "JOIN products p ON p.idx = pi.products_id"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
    }

    public function testSelectRejectsPlaceholderInJoin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new product_images_model())->select(
            [" pi.idx "],
            "WHERE pi.products_id = 1",
            null,
            "pi",
            "JOIN products p ON p.idx = ?"
        );
    }

    public function testUpdateSimpleStampsModifiedAt(): void
    {
        $id = $this->createProduct(['stock' => 100]);

        $result = (new products_model())->update(
            [" stock = stock - ? "],
            "WHERE idx = ?",
            [30, $id]
        );
        $this->assertSame(1, $result->rowCount());

        $stmt = (new products_model())->select(
            [" stock ", " modified_at "],
            "WHERE idx = ?",
            [$id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(70, (int) $row['stock']);
        $this->assertNotNull($row['modified_at']);
    }

    public function testUpdateWithJoinAndJoinParamsBindsInOrder(): void
    {
        $targetId = $this->createProduct(['stock' => 100]);
        $otherId  = $this->createProduct(['stock' => 100]);
        $this->createProductImage($targetId);

        (new products_model())->update(
            [" stock = stock + img.bump "],
            "WHERE 1=1",
            null,
            "p",
            "JOIN ( SELECT products_id, COUNT(*) AS bump FROM product_images WHERE products_id = ? GROUP BY products_id ) img ON img.products_id = p.idx",
            [$targetId]
        );

        $stmtTarget = (new products_model())->select([" stock "], "WHERE idx = ?", [$targetId]);
        $rowTarget = $stmtTarget->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(101, (int) $rowTarget['stock']);

        $stmtOther = (new products_model())->select([" stock "], "WHERE idx = ?", [$otherId]);
        $rowOther = $stmtOther->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(100, (int) $rowOther['stock']);
    }

    public function testInsertWithSuffixDedupesOnDuplicateKey(): void
    {
        // Aleatorio, nunca literal fixo: o rollback de localPDO::__destruct() nao e
        // confiavel neste ambiente (phpunit por processo via docker, mesma nota em
        // GatewaysActionTest/CustomerBlockTest) -- um literal fixo colide com residuo
        // de execucoes anteriores e faz o "primeiro" insert daqui virar um dedupe
        // no-op sem querer.
        $orderId = random_int(100000, 999999999);
        $data = [
            'active'       => 'yes',
            'event_type'   => 'order_paid',
            'orders_id'    => $orderId,
            'to_mail'      => 't@t.com',
            'subject'      => 's',
            'body'         => 'b',
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => 5,
        ];

        $first  = (new email_queue_model())->insert($data, "ON DUPLICATE KEY UPDATE idx = idx");
        $second = (new email_queue_model())->insert($data, "ON DUPLICATE KEY UPDATE idx = idx");

        $stmt = (new email_queue_model())->select(
            [" COUNT(*) AS total "],
            "WHERE orders_id = ? AND event_type = ?",
            [$orderId, 'order_paid']
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(1, (int) $row['total']);

        // Verificado empiricamente via PDO::lastInsertId() real (nao SELECT
        // LAST_INSERT_ID() solto, que e um valor de sessao e pode enganar): o
        // self-assign "idx = idx" nao conta como tocar a coluna auto_increment
        // pro driver, entao o no-op retorna 0 -- so o insert novo retorna o id.
        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'no-op do ON DUPLICATE deve retornar 0 (idx = idx nao conta como tocar a auto_increment)');
    }

    public function testUpdateRejectsMissingWhere(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new products_model())->update([" stock = 1 "], null);
    }

    public function testUpdateRejectsEmptyFields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new products_model())->update([], "WHERE idx = 1");
    }

    public function testInsertRejectsEmptyData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new email_queue_model())->insert([]);
    }

    public function testInsertRejectsPlaceholderInSuffix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new email_queue_model())->insert(
            ['active' => 'yes'],
            'ON DUPLICATE KEY UPDATE idx = ?'
        );
    }
}
