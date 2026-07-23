<?php

declare(strict_types=1);

/**
 * Cobre a correcao do plano 018: a query de login (auth_controller::login())
 * passa a exigir active='yes' alem de enabled='yes', para que um usuario
 * "removido" (soft-delete via DOLModel::remove(), active='no') nao consiga
 * mais autenticar mesmo com enabled='yes' e senha valida.
 *
 * login() termina em basic_redir()->exit() e nao e exercitavel diretamente em
 * PHPUnit (mesma limitacao documentada em CustomerSearchTest/OrdersExportTest).
 * O teste reproduz exatamente o mesmo set_filter que o controller usa
 * (manager/app/inc/controller/auth_controller.php:52), contra fixtures reais.
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
