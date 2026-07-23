<?php

declare(strict_types=1);

final class UsersModelTest extends DBTestCase
{
    public function testModelCanLoadData(): void
    {
        $model = new users_model();
        $model->set_field([" idx ", " name "]);
        $model->set_paginate([5]);
        $model->load_data();

        $this->assertIsArray($model->data);
    }

    public function testModelDefaultFilterOnlyActive(): void
    {
        $model = new users_model();
        $model->set_field([" COUNT(idx) AS total "]);
        $model->set_paginate([1]);
        $model->load_data();

        $this->assertNotEmpty($model->data);
        $this->assertArrayHasKey('total', $model->data[0]);
    }

    public function testInsertAndRollbackDoesNotPersist(): void
    {
        $model = new users_model();
        $model->populate([
            'name'     => 'Test User',
            'mail'     => 'test_' . uniqid() . '@example.com',
            'login'    => 'testuser_' . uniqid(),
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = $model->save();

        $this->assertGreaterThan(0, $id, 'Insert deve retornar um ID valido');

        // O rollback no tearDown vai desfazer este insert
    }

    public function testSoftDeleteSetFilter(): void
    {
        $model = new users_model();
        $model->set_filter(["idx = ?"], [0]);
        $model->set_paginate([1]);
        $model->load_data();

        // Nao deve encontrar usuario idx=0
        $this->assertCount(0, $model->data);
    }

    public function testSetFilterWithParams(): void
    {
        $model = new users_model();
        $model->set_filter(["active = 'yes'", "enabled = ?"], ['yes']);
        $model->set_paginate([10]);
        $model->load_data();

        $this->assertIsArray($model->data);
    }

    public function testStaticFilterWithoutParamsStillLoads(): void
    {
        // Filtro estatico legado (sem params) deve continuar funcionando
        // pelo caminho unico preparado, apos remocao do branch else cru.
        $model = new users_model();
        $model->set_field([" idx ", " name "]);
        $model->set_filter(["active = 'yes'"]);
        $model->set_paginate([10]);
        $model->load_data();

        $this->assertIsArray($model->data);
    }

    public function testLoadDataDefaultPopulatesRecordset(): void
    {
        $model = new users_model();
        $model->set_field([" idx "]);
        $model->set_filter(["active = 'yes'"]);
        $model->set_paginate([5]);
        $model->load_data();                 // default: real count

        $this->assertIsInt($model->get_recordset());
    }

    public function testLoadDataWithoutCountUsesRowCount(): void
    {
        $model = new users_model();
        $model->set_field([" idx "]);
        $model->set_filter(["active = 'yes'"]);
        $model->set_paginate([3]);
        $model->load_data(false);            // opt-out: recordset == rows fetched

        $this->assertSame(count($model->data), $model->get_recordset());
    }

    public function testPopulateWithNullClearsColumn(): void
    {
        $insert = new users_model();
        $insert->populate([
            'name'         => 'Test User',
            'mail'         => 'test_' . uniqid() . '@example.com',
            'login'        => 'testuser_' . uniqid(),
            'password'     => password_hash('secret', PASSWORD_BCRYPT),
            'email_token'  => 'some-token-value',
        ]);
        $id = $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert deve retornar um ID valido');

        $update = new users_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate(["email_token" => null]);
        $update->save();

        $reload = new users_model();
        $reload->set_field([" idx ", " email_token "]);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertNull($reload->data[0]['email_token'], 'email_token deve ser gravado como NULL apos populate(null)');
    }

    public function testPopulateStillDropsEmptyString(): void
    {
        $insert = new users_model();
        $insert->populate([
            'name'     => 'Test User',
            'mail'     => 'test_' . uniqid() . '@example.com',
            'login'    => 'testuser_' . uniqid(),
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        $id = $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert deve retornar um ID valido');

        $update = new users_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate(["name" => ""]);
        $update->save();

        $reload = new users_model();
        $reload->set_field([" idx ", " name "]);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame('Test User', $reload->data[0]['name'], 'string vazia deve continuar sendo descartada');
    }
}
