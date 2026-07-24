<?php

declare(strict_types=1);

/**
 * Cobre o plano 006: auth_controller::check_login() passa a revalidar a credencial
 * no banco a cada request (active='yes', enabled='yes', perfil com adm='yes') — os
 * mesmos tres criterios que login() exige. Sem isto, "Inativar" e "Remover" em
 * /config so valiam para o proximo login: um admin revogado seguia com acesso
 * total ate a sessao expirar por inatividade.
 *
 * Molde estrutural: LoginActiveFilterTest (mesma base DBTestCase, fixtures via
 * users_model + populate/save, sufixo uniqid() nos logins).
 */
final class SessionRevalidationTest extends DBTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // self::$revalidated e estatico: sem reset, o segundo teste do processo
        // reaproveita o resultado do primeiro.
        auth_controller::reset_revalidation_cache();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        auth_controller::reset_revalidation_cache();
        $_SESSION = [];
        parent::tearDown();
    }

    /** Id do perfil com adm='yes', descoberto via query (nao hardcoded). */
    private function adminProfileId(): int
    {
        $profiles = new profiles_model();
        $profiles->set_field([" idx "]);
        $profiles->set_filter([" active = 'yes' ", " adm = 'yes' "]);
        $profiles->set_paginate([1]);
        $profiles->load_data(false);

        $id = $profiles->data[0]['idx'] ?? null;
        $this->assertNotNull($id, 'Ambiente de teste precisa de um perfil com adm=\'yes\' (seed de migrations/003)');

        return (int)$id;
    }

    /** Id de um perfil sem adm='yes' (o seed 'user' de migrations/003). */
    private function nonAdminProfileId(): int
    {
        $profiles = new profiles_model();
        $profiles->set_field([" idx "]);
        $profiles->set_filter([" active = 'yes' ", " adm = 'no' "]);
        $profiles->set_paginate([1]);
        $profiles->load_data(false);

        $id = $profiles->data[0]['idx'] ?? null;
        $this->assertNotNull($id, 'Ambiente de teste precisa de um perfil com adm=\'no\' (seed de migrations/003)');

        return (int)$id;
    }

    private function createUser(string $active, string $enabled): int
    {
        $login = 'session_revalidation_' . uniqid();
        $model = new users_model();
        $model->populate([
            'name'     => 'Session Revalidation Test',
            'mail'     => $login . '@example.com',
            'login'    => $login,
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'active'   => $active,
            'enabled'  => $enabled,
        ]);
        $id = (int)$model->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de usuario deve retornar um ID valido');

        return $id;
    }

    private function setSessionCredential(int $idx): void
    {
        $_SESSION[constant("cAppKey")]["credential"]["idx"] = $idx;
    }

    public function testAdminAtivoEHabilitadoComPerfilAdmPassa(): void
    {
        $id = $this->createUser('yes', 'yes');
        $model = new users_model();
        $model->save_attach(["idx" => $id, "post" => ["profiles_id" => $this->adminProfileId()]], ["profiles"]);

        $this->setSessionCredential($id);

        $this->assertTrue(auth_controller::check_login());
    }

    public function testUsuarioInativadoNaoPassa(): void
    {
        $id = $this->createUser('yes', 'no');
        $model = new users_model();
        $model->save_attach(["idx" => $id, "post" => ["profiles_id" => $this->adminProfileId()]], ["profiles"]);

        $this->setSessionCredential($id);

        $this->assertFalse(auth_controller::check_login());
        $this->assertSame([], $_SESSION, 'destroy_session() deve zerar $_SESSION na revalidacao que falha');
    }

    public function testUsuarioRemovidoNaoPassa(): void
    {
        $id = $this->createUser('no', 'yes');
        $model = new users_model();
        $model->save_attach(["idx" => $id, "post" => ["profiles_id" => $this->adminProfileId()]], ["profiles"]);

        $this->setSessionCredential($id);

        $this->assertFalse(auth_controller::check_login());
        $this->assertSame([], $_SESSION, 'destroy_session() deve zerar $_SESSION na revalidacao que falha');
    }

    public function testUsuarioSemPerfilAdmNaoPassa(): void
    {
        $id = $this->createUser('yes', 'yes');
        $model = new users_model();
        $model->save_attach(["idx" => $id, "post" => ["profiles_id" => $this->nonAdminProfileId()]], ["profiles"]);

        $this->setSessionCredential($id);

        $this->assertFalse(auth_controller::check_login());
        $this->assertSame([], $_SESSION, 'destroy_session() deve zerar $_SESSION na revalidacao que falha');
    }

    public function testSessaoSemCredencialNaoPassa(): void
    {
        $this->assertFalse(auth_controller::check_login());
    }

    public function testChamadaRepetidaReaproveitaCachePorRequest(): void
    {
        $id = $this->createUser('yes', 'yes');
        $model = new users_model();
        $model->save_attach(["idx" => $id, "post" => ["profiles_id" => $this->adminProfileId()]], ["profiles"]);

        $this->setSessionCredential($id);

        $this->assertTrue(auth_controller::check_login());

        // Inativa o usuario SEM chamar reset_revalidation_cache(): dentro da mesma
        // "requisicao", self::$revalidated ja resolvido nao deve bater no banco de novo.
        $update = new users_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate(["enabled" => "no"]);
        $update->save();

        $this->assertTrue(auth_controller::check_login(), 'segunda chamada na mesma requisicao deve reaproveitar o cache, nao reconsultar o banco');
    }
}
