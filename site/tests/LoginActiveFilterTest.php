<?php

declare(strict_types=1);

/**
 * Cobre a correcao do plano 018 no filtro de login: a query passa a exigir
 * active='yes' alem de enabled='yes', para que um usuario "removido"
 * (soft-delete via DOLModel::remove(), active='no') nao consiga mais
 * autenticar mesmo com enabled='yes' e senha valida.
 *
 * O plano 021 removeu o sistema de contas do site publico inteiro (incl.
 * site/app/inc/controller/auth_controller.php) — este teste nao cobre mais
 * nenhum controller do site, so a copia site/ do model users_model
 * (byte-identica a manager/ pela convencao do framework LEGGO). A cobertura
 * do controller equivalente segue em manager/tests/LoginActiveFilterTest.php,
 * que ainda tem um auth_controller::login() real para exercitar.
 */
final class LoginActiveFilterTest extends DBTestCase
{
    /** Reproduz exatamente o set_filter de auth_controller::login() apos o plano 018. */
    private function loginQuery(string $loginOrMail): array
    {
        $users = new users_model();
        $users->set_field([" idx ", " name ", " mail ", " login ", " password "]);
        $users->set_filter([" active = 'yes' ", "enabled = 'yes'", "? IN (mail,login)"], [$loginOrMail]);
        $users->set_paginate([1]);
        $users->load_data();

        return $users->data;
    }

    public function testSoftDeletedUserIsExcludedFromLoginFilter(): void
    {
        $login = 'login_active_filter_' . uniqid();
        $model = new users_model();
        $model->populate([
            'name'     => 'Login Active Filter Test',
            'mail'     => $login . '@example.com',
            'login'    => $login,
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'active'   => 'no',
            'enabled'  => 'yes',
        ]);
        $id = (int)$model->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de usuario deve retornar um ID valido');

        $results = $this->loginQuery($login);

        $this->assertSame([], $results, 'usuario com active=no nao deve autenticar mesmo com enabled=yes');
    }

    public function testActiveUserIsReturnedByLoginFilter(): void
    {
        $login = 'login_active_filter_' . uniqid();
        $model = new users_model();
        $model->populate([
            'name'     => 'Login Active Filter Test',
            'mail'     => $login . '@example.com',
            'login'    => $login,
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'active'   => 'yes',
            'enabled'  => 'yes',
        ]);
        $id = (int)$model->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de usuario deve retornar um ID valido');

        $results = $this->loginQuery($login);

        $this->assertCount(1, $results, 'usuario com active=yes e enabled=yes deve ser encontrado pelo filtro de login');
        $this->assertSame($id, (int)$results[0]['idx']);
    }

    public function testDisabledUserIsExcludedFromLoginFilter(): void
    {
        $login = 'login_active_filter_' . uniqid();
        $model = new users_model();
        $model->populate([
            'name'     => 'Login Active Filter Test',
            'mail'     => $login . '@example.com',
            'login'    => $login,
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'active'   => 'yes',
            'enabled'  => 'no',
        ]);
        $id = (int)$model->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de usuario deve retornar um ID valido');

        $results = $this->loginQuery($login);

        $this->assertSame([], $results, 'usuario com enabled=no nao deve autenticar mesmo com active=yes');
    }
}
