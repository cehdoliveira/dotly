<?php

declare(strict_types=1);

/**
 * Cobre os caminhos de escrita e relacionamento do DOLModel que
 * UsersModelTest nao exercita: save() no ramo UPDATE, remove() (soft
 * delete), save_attach() e attach()/join() (many-to-many via users_profiles
 * e profiles, seedados pelas migrations 002-004).
 *
 * Nota sobre isolamento: DOLModel usa localPDO::getInstance() (conexao
 * singleton compartilhada pelo processo de teste inteiro), nao a conexao
 * `$this->con` criada por DBTestCase::setUp(). Verificacoes de estado
 * portanto usam localPDO::getInstance()->getPdo() diretamente — uma conexao
 * separada nao enxergaria as escritas ainda nao commitadas. A limpeza final
 * acontece via rollback implicito no __destruct() do singleton ao fim do
 * processo do PHPUnit (mesmo padrao ja usado por UsersModelTest).
 */
final class DolModelWriteTest extends DBTestCase
{
    private function newTestUser(): int
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => 'Write Test User',
            'mail'     => 'dolmodel_write_' . uniqid() . '@example.com',
            'login'    => 'dolmodel_write_' . uniqid(),
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = $insert->save();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    public function testSaveUpdatesExistingRowAndTouchesModifiedAt(): void
    {
        $id = $this->newTestUser();

        $beforeUpdate = new users_model();
        $beforeUpdate->set_field([" idx ", " name ", " modified_at "]);
        $beforeUpdate->set_filter(["idx = ?"], [$id]);
        $beforeUpdate->set_paginate([1]);
        $beforeUpdate->load_data();
        $this->assertNull($beforeUpdate->data[0]['modified_at'], 'insert nao deve preencher modified_at');

        $update = new users_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate(["name" => "Updated Name"]);
        $update->save();

        $reload = new users_model();
        $reload->set_field([" idx ", " name ", " modified_at "]);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame('Updated Name', $reload->data[0]['name']);
        $this->assertNotNull($reload->data[0]['modified_at'], 'save() no ramo UPDATE deve preencher modified_at');
    }

    public function testRemoveSoftDeletesRow(): void
    {
        $id = $this->newTestUser();

        $toRemove = new users_model();
        $toRemove->set_filter(["idx = ?"], [$id]);
        $toRemove->remove();

        $reloadIgnoringActive = new users_model();
        $reloadIgnoringActive->set_field([" idx ", " active "]);
        $reloadIgnoringActive->set_filter(["idx = ?"], [$id]);
        $reloadIgnoringActive->set_paginate([1]);
        $reloadIgnoringActive->load_data();

        $this->assertCount(1, $reloadIgnoringActive->data, 'remove() e soft-delete: a linha continua existindo');
        $this->assertSame('no', $reloadIgnoringActive->data[0]['active']);

        $reloadDefaultFilter = new users_model();
        $reloadDefaultFilter->set_field([" idx "]);
        $reloadDefaultFilter->set_filter(["active = 'yes'", "idx = ?"], [$id]);
        $reloadDefaultFilter->set_paginate([1]);
        $reloadDefaultFilter->load_data();

        $this->assertCount(0, $reloadDefaultFilter->data, 'usuario removido nao deve aparecer sob o filtro active=yes');
    }

    public function testSaveAttachLinksProfileAndAttachReadsIt(): void
    {
        $userId = $this->newTestUser();

        $profiles = new profiles_model();
        $profiles->set_field([" idx "]);
        $profiles->set_filter(["slug = ?"], ['user']);
        $profiles->set_paginate([1]);
        $profiles->load_data();
        $this->assertNotEmpty($profiles->data, 'fixture da migration 003 deve conter profiles.slug = user');
        $profileId = (int) $profiles->data[0]['idx'];

        $attachModel = new users_model();
        $attachModel->save_attach(
            ["idx" => $userId, "post" => ["profiles_id" => $profileId]],
            ["profiles"]
        );

        $pdo = localPDO::getInstance()->getPdo();
        $stmt = $pdo->prepare(
            "SELECT active FROM users_profiles WHERE users_id = ? AND profiles_id = ?"
        );
        $stmt->execute([$userId, $profileId]);
        $this->assertSame('yes', $stmt->fetchColumn(), 'save_attach() deve inserir a linha de juncao ativa');

        $reload = new users_model();
        $reload->set_field([" idx "]);
        $reload->set_filter(["idx = ?"], [$userId]);
        $reload->set_paginate([1]);
        $reload->load_data();
        $reload->attach(["profiles"]);

        $this->assertArrayHasKey('profiles_attach', $reload->data[0]);
        $attachedIds = array_map('intval', array_column($reload->data[0]['profiles_attach'], 'idx'));
        $this->assertContains($profileId, $attachedIds, 'attach() deve trazer o profile vinculado por save_attach()');
    }

    public function testJoinBatchAttachesAcrossMultipleParents(): void
    {
        $profiles = new profiles_model();
        $profiles->set_field([" idx "]);
        $profiles->load_data();
        $this->assertGreaterThanOrEqual(
            2,
            count($profiles->data),
            'fixture das migrations 003/004 deve conter ao menos 2 profiles para exercitar o batch de join()'
        );

        $profiles->join("assignments", "users_profiles", ["profiles_id" => "idx"]);

        foreach ($profiles->data as $row) {
            $this->assertArrayHasKey('assignments_attach', $row);
            $this->assertIsArray($row['assignments_attach']);
        }

        $totalAssignments = array_sum(array_map(
            static fn(array $row): int => count($row['assignments_attach']),
            $profiles->data
        ));
        $this->assertGreaterThan(
            0,
            $totalAssignments,
            'seed da migration 004 vincula o profile admin ao usuario admin — join() deve encontrar essa linha'
        );
    }
}
