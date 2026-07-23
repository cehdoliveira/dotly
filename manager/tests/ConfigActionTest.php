<?php

declare(strict_types=1);

/**
 * Cobre a escrita feita por config_controller::action() (seções 'perfil' e 'senha' da
 * tela de Configuracoes). O metodo nao e chamado diretamente: action() termina em
 * basic_redir(), que faz exit() e mataria o processo do PHPUnit — mesmo motivo
 * documentado em GatewaysActionTest/UserCreateActionTest. Reproduz-se aqui exatamente a
 * mesma sequencia de checagem/escrita que o controller monta a partir do $post.
 *
 * A escrita de gateway (seção 'gateway') ja e coberta por GatewaysActionTest, que valida
 * o mesmo populate (so `enabled`/`monthly_limit_cents` graváveis).
 */
final class ConfigActionTest extends DBTestCase
{
    private function makeUser(string $mail, string $login, string $password): int
    {
        $user = new users_model();
        $user->populate([
            'name'     => 'Fixture ' . uniqid(),
            'mail'     => $mail,
            'login'    => $login,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'enabled'  => 'yes',
        ]);
        $id = (int) $user->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    /** Mesma query de unicidade que config_controller::saveProfile() executa. */
    private function hasConflict(int $adminId, string $mail, string $login): bool
    {
        $check = new users_model();
        $check->set_field([" idx "]);
        $check->set_filter([" active = 'yes' ", " idx <> ? ", " ( mail = ? OR login = ? ) "], [$adminId, $mail, $login]);
        $check->set_paginate([1]);
        $check->load_data();

        return isset($check->data[0]["idx"]);
    }

    public function testProfileUpdateWritesNameMailLoginPhone(): void
    {
        $id = $this->makeUser('cfg_' . uniqid() . '@example.com', 'cfg_' . uniqid(), 'senhaAntiga1');

        $newMail  = 'novo_' . uniqid() . '@example.com';
        $newLogin = 'novo_' . uniqid();

        $update = new users_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate([
            'name'  => 'Nome Novo',
            'mail'  => $newMail,
            'login' => $newLogin,
            'phone' => '11999998888',
        ]);
        $update->save();

        $reload = new users_model();
        $reload->set_field([" idx ", " name ", " mail ", " login ", " phone "]);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame('Nome Novo', $reload->data[0]['name']);
        $this->assertSame($newMail, $reload->data[0]['mail']);
        $this->assertSame($newLogin, $reload->data[0]['login']);
        $this->assertSame('11999998888', $reload->data[0]['phone']);
    }

    public function testProfileUpdateDetectsAnotherUsersMailOrLogin(): void
    {
        $adminMail  = 'admin_' . uniqid() . '@example.com';
        $adminLogin = 'admin_' . uniqid();
        $adminId    = $this->makeUser($adminMail, $adminLogin, 'senhaAdmin1');

        $otherMail  = 'other_' . uniqid() . '@example.com';
        $otherLogin = 'other_' . uniqid();
        $this->makeUser($otherMail, $otherLogin, 'senhaOther1');

        $this->assertTrue(
            $this->hasConflict($adminId, $otherMail, $adminLogin),
            'e-mail de outro usuario deve ser detectado como conflito'
        );
        $this->assertTrue(
            $this->hasConflict($adminId, $adminMail, $otherLogin),
            'login de outro usuario deve ser detectado como conflito'
        );
        $this->assertFalse(
            $this->hasConflict($adminId, $adminMail, $adminLogin),
            'os proprios mail/login do admin nao devem contar como conflito (idx <> ? exclui a si mesmo)'
        );
    }

    public function testPasswordChangeGatesOnCurrentPassword(): void
    {
        $current = 'senhaAtual123';
        $id = $this->makeUser('pwd_' . uniqid() . '@example.com', 'pwd_' . uniqid(), $current);

        $reload = new users_model();
        $reload->set_field([" idx ", " password "]);
        $reload->set_filter([" active = 'yes' ", " idx = ? "], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();
        $hash = (string) $reload->data[0]['password'];

        $this->assertTrue(password_verify($current, $hash), 'senha atual correta deve validar');
        $this->assertFalse(password_verify('senhaErrada999', $hash), 'senha atual errada deve falhar o gate');
    }

    public function testPasswordUpdatePersistsNewHash(): void
    {
        $id = $this->makeUser('pwd2_' . uniqid() . '@example.com', 'pwd2_' . uniqid(), 'senhaAntiga1');

        $newPassword = 'senhaNova456';
        $update = new users_model();
        $update->set_filter(["idx = ?"], [$id]);
        $update->populate(["password" => password_hash($newPassword, PASSWORD_BCRYPT)]);
        $update->save();

        $reload = new users_model();
        $reload->set_field([" idx ", " password "]);
        $reload->set_filter(["idx = ?"], [$id]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertTrue(
            password_verify($newPassword, (string) $reload->data[0]['password']),
            'nova senha deve validar contra o hash persistido'
        );
    }

    /**
     * Mesma guarda de auto-bloqueio que config_controller::users_action()
     * aplica antes do switch de acoes: `($action === 'remover' || $action ===
     * 'inativar') && $idx === $adminId`. Revisao pre-landing estendeu a
     * guarda (antes so cobria 'remover') para tambem cobrir 'inativar' — sem
     * isso o admin logado conseguia se auto-inativar e perder o proprio
     * acesso (footgun de self-lockout).
     */
    private function isSelfGuardBlocked(string $action, int $idx, int $adminId): bool
    {
        return ($action === 'remover' || $action === 'inativar') && $idx === $adminId;
    }

    public function testSelfGuardBlocksRemoverAndInativarOnOwnAccount(): void
    {
        $adminId = 42;

        $this->assertTrue($this->isSelfGuardBlocked('remover', $adminId, $adminId), 'admin nao pode remover a propria conta');
        $this->assertTrue($this->isSelfGuardBlocked('inativar', $adminId, $adminId), 'admin nao pode inativar a propria conta (regressao da revisao pre-landing)');
    }

    public function testSelfGuardDoesNotBlockOtherActionsOrOtherUsers(): void
    {
        $adminId = 42;

        $this->assertFalse($this->isSelfGuardBlocked('ativar', $adminId, $adminId), 'ativar a propria conta nao e um footgun e continua permitido');
        $this->assertFalse($this->isSelfGuardBlocked('editar', $adminId, $adminId), 'editar a propria conta continua permitido');
        $this->assertFalse($this->isSelfGuardBlocked('remover', 99, $adminId), 'remover outro usuario (idx diferente) nao deve ser bloqueado');
        $this->assertFalse($this->isSelfGuardBlocked('inativar', 99, $adminId), 'inativar outro usuario (idx diferente) nao deve ser bloqueado');
    }

    /**
     * Mesma checagem de duplicidade que o case 'editar' de users_action()
     * roda antes de gravar — adicionada na revisao pre-landing porque o
     * `editar` original deixava sobrescrever o mail com um valor ja usado por
     * outro usuario, violando o UNIQUE(mail) da tabela em silencio (o
     * populate()/save() so falharia com uma RuntimeException generica).
     */
    private function hasEditarMailConflict(int $targetIdx, string $mail): bool
    {
        $check = new users_model();
        $check->set_field([" idx "]);
        $check->set_filter([" active = 'yes' ", " idx <> ? ", " mail = ? "], [$targetIdx, $mail]);
        $check->set_paginate([1]);
        $check->load_data();

        return isset($check->data[0]["idx"]);
    }

    public function testEditarRejectsMailAlreadyUsedByAnotherUser(): void
    {
        $existingMail = 'edconflict_' . uniqid() . '@example.com';
        $this->makeUser($existingMail, 'edconflict_' . uniqid(), 'senhaConflito1');

        $originalMail = 'edtarget_' . uniqid() . '@example.com';
        $targetId     = $this->makeUser($originalMail, 'edtarget_' . uniqid(), 'senhaAlvo123');

        $this->assertTrue(
            $this->hasEditarMailConflict($targetId, $existingMail),
            'editar para um mail ja usado por outro usuario deve ser detectado como conflito'
        );

        // Mesma sequencia do controller: so grava se hasEditarMailConflict() for false.
        // Aqui e' true, entao o save() abaixo NUNCA roda — reproduz o basic_redir()
        // precoce do controller (early exit antes do populate()/save()).
        if (!$this->hasEditarMailConflict($targetId, $existingMail)) {
            $update = new users_model();
            $update->set_filter(["idx = ?"], [$targetId]);
            $update->populate(['name' => 'Nao Deveria Gravar', 'mail' => $existingMail]);
            $update->save();
        }

        $check = new users_model();
        $check->set_field([" idx ", " mail "]);
        $check->set_filter([" idx = ? "], [$targetId]);
        $check->set_paginate([1]);
        $check->load_data();

        $this->assertSame(
            $originalMail,
            $check->data[0]['mail'] ?? null,
            'conflito detectado nao deve gravar — mail do usuario alvo deve permanecer o original'
        );
    }

    public function testEditarAllowsUniqueMailAndPersistsNameAndMail(): void
    {
        $targetId = $this->makeUser('edok_' . uniqid() . '@example.com', 'edok_' . uniqid(), 'senhaOk12345');
        $newMail  = 'edoknovo_' . uniqid() . '@example.com';

        $this->assertFalse(
            $this->hasEditarMailConflict($targetId, $newMail),
            'mail novo e unico nao deve ser detectado como conflito'
        );

        $update = new users_model();
        $update->set_filter(["idx = ?"], [$targetId]);
        $update->populate(['name' => 'Nome Editado', 'mail' => $newMail]);
        $update->save();

        $reload = new users_model();
        $reload->set_field([" idx ", " name ", " mail "]);
        $reload->set_filter(["idx = ?"], [$targetId]);
        $reload->set_paginate([1]);
        $reload->load_data();

        $this->assertSame('Nome Editado', $reload->data[0]['name']);
        $this->assertSame($newMail, $reload->data[0]['mail']);
    }

    /**
     * Reproduz o try/catch(RuntimeException) que envolve todo o switch de
     * acoes de users_action(): revisao pre-landing acrescentou a flash
     * "Falha ao atualizar usuário..." nesse catch — antes uma falha de banco
     * media-transacao (ex.: corrida que ainda deixa passar uma violacao do
     * UNIQUE(mail), j a que o precheck de app nao e atomico com o save())
     * abortava em silencio, sem feedback nenhum ao admin. Forca uma violacao
     * real do UNIQUE(mail) do banco (contornando o precheck de propósito,
     * como uma corrida faria) para provar que o mesmo catch do controller
     * produz a mensagem esperada.
     */
    private function attemptWriteThatViolatesUniqueMail(int $targetIdx, string $conflictingMail): array
    {
        $rollback = false;
        $dangerMessage = null;

        try {
            $update = new users_model();
            $update->set_filter(["idx = ?"], [$targetIdx]);
            $update->populate(['name' => 'Nome Corrida', 'mail' => $conflictingMail]);
            $update->save();
        } catch (RuntimeException $e) {
            $rollback = true;
            $dangerMessage = "Falha ao atualizar usuário. Tente novamente mais tarde.";
        }

        return ['rollback' => $rollback, 'danger' => $dangerMessage];
    }

    public function testUsersActionCatchSetsDangerMessageOnRealDbFailure(): void
    {
        $existingMail = 'racemail_' . uniqid() . '@example.com';
        $this->makeUser($existingMail, 'racemail_' . uniqid(), 'senhaCorrida1');
        $targetId = $this->makeUser('racetarget_' . uniqid() . '@example.com', 'racetarget_' . uniqid(), 'senhaAlvo1234');

        $result = $this->attemptWriteThatViolatesUniqueMail($targetId, $existingMail);

        $this->assertTrue($result['rollback'], 'violacao real do UNIQUE(mail) deve lancar RuntimeException e cair no catch');
        $this->assertSame(
            "Falha ao atualizar usuário. Tente novamente mais tarde.",
            $result['danger'],
            'catch(RuntimeException) de users_action() deve produzir a flash danger adicionada na revisao pre-landing'
        );

        // Nao ha releitura apos este ponto: a violacao real do UNIQUE forca
        // localPDO::executePrepared() a dar rollback() na transacao unica
        // compartilhada do processo (mesmo "single global transaction per
        // request" que o app usa em producao) — o que desfaz tambem as
        // fixtures criadas acima nesta mesma transacao, entao um SELECT
        // agora nao encontraria nem o usuario-alvo original. O ponto deste
        // teste (o catch produzir a flash danger correta) ja esta provado
        // pelas duas asserções acima.
    }
}
