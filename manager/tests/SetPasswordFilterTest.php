<?php

declare(strict_types=1);

/**
 * Cobre a query de lookup por token usada por auth_controller::display_set_password()
 * e auth_controller::set_password() (Plano 020).
 *
 * O plano removeu a condicao ` enabled = 'no' ` do set_filter() dos dois metodos,
 * mantendo apenas active='yes' + email_token=? + email_token_expires_at > NOW().
 * Motivo: antes da mudanca, um usuario ja ativo (enabled='yes') pedindo redefinicao
 * de senha (case 'reset-senha' de site_controller::users_action()) nunca conseguia
 * usar o link de /definir-senha/{token}, porque o filtro exigia enabled='no'.
 *
 * REGRESSAO a proteger: o fluxo de convite original (usuario recem-criado com
 * enabled='no' e email_token valido) precisa continuar funcionando depois que a
 * condicao ficou mais permissiva — o metodo nao pode ter passado a exigir
 * enabled='yes' nem quebrado a checagem de expiracao/active por engano.
 *
 * O controller nao e chamado diretamente (set_password() termina em basic_redir(),
 * que faz exit() — mesmo motivo documentado em GatewaysActionTest e
 * UserCreateActionTest). Os testes abaixo reproduzem a mesma query de lookup e a
 * mesma sequencia de escrita que set_password() executa.
 */
final class SetPasswordFilterTest extends DBTestCase
{
    private function makeUser(string $enabled, string $token, string $expiresAt): int
    {
        $user = new users_model();
        $user->populate([
            'name'                   => 'Set Password Teste',
            'mail'                   => 'setpwd_' . uniqid() . '@example.com',
            'login'                  => 'setpwd_' . uniqid(),
            'password'               => password_hash(random_token(), PASSWORD_BCRYPT),
            'enabled'                => $enabled,
            'email_token'            => $token,
            'email_token_expires_at' => $expiresAt,
        ]);
        $id = (int) $user->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    /**
     * Mesma query de lookup usada por display_set_password() e pelo inicio de
     * set_password() apos a remocao de ` enabled = 'no' ` do filtro. Compara
     * expires_at contra um "agora" calculado em PHP (nao o NOW() do MySQL) pelo
     * mesmo motivo documentado no controller: MySQL roda em UTC/SYSTEM enquanto
     * expires_at e gravado pelo PHP em America/Sao_Paulo — comparar contra
     * NOW() direto derrubaria a janela de 2h do reset por um skew de ~3h.
     */
    private function lookupByToken(string $token): ?int
    {
        $now = date("Y-m-d H:i:s");

        $users = new users_model();
        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " email_token = ? ", " email_token_expires_at > ? "], [$token, $now]);
        $users->set_paginate([1]);
        $users->load_data();

        return isset($users->data[0]["idx"]) ? (int) $users->data[0]["idx"] : null;
    }

    /**
     * Mesma sequencia de escrita que set_password() executa uma vez que o token
     * foi validado: enabled=yes, email_verified_at=now, senha nova, email_token=null.
     */
    private function completeSetPassword(int $userIdx, string $newPassword): void
    {
        $users = new users_model();
        $users->set_filter(["idx = ?"], [$userIdx]);
        $users->populate([
            "enabled"           => "yes",
            "email_verified_at" => date("Y-m-d H:i:s"),
            "password"          => password_hash($newPassword, PASSWORD_BCRYPT),
            "email_token"       => null,
        ]);
        $users->save();
    }

    public function testInviteFlowStillWorksForBrandNewDisabledUser(): void
    {
        $token = random_token();
        $userIdx = $this->makeUser('no', $token, date("Y-m-d H:i:s", strtotime("+72 hours")));

        $found = $this->lookupByToken($token);

        $this->assertSame(
            $userIdx,
            $found,
            "regressao: usuario recem-convidado (enabled='no') com token valido deve continuar" .
            " encontravel pelo filtro apos a remocao da condicao enabled='no'"
        );

        $this->completeSetPassword($found, 'nova-senha-123');

        $reload = new users_model();
        $reload->set_field([" idx ", " enabled ", " email_token ", " email_token_expires_at ", " password "]);
        $reload->set_filter(["idx = ?"], [$userIdx]);
        $reload->set_paginate([1]);
        $reload->load_data();
        $row = $reload->data[0];

        $this->assertSame('yes', $row['enabled'], 'usuario deve ficar habilitado apos definir a senha');
        $this->assertNull($row['email_token'], 'token deve ser consumido (null) apos o uso');
        $this->assertTrue(password_verify('nova-senha-123', $row['password']), 'nova senha deve ter sido persistida');
    }

    public function testResetFlowNowWorksForAlreadyEnabledUser(): void
    {
        $token = random_token();
        $userIdx = $this->makeUser('yes', $token, date("Y-m-d H:i:s", strtotime("+2 hours")));

        $found = $this->lookupByToken($token);

        $this->assertSame(
            $userIdx,
            $found,
            "usuario ja ativo (enabled='yes') pedindo redefinicao de senha deve ser encontrado pelo" .
            " filtro agora que a condicao enabled='no' foi removida — antes da correcao do plano 020" .
            " esse usuario nunca conseguia usar o link de /definir-senha/{token}"
        );

        $this->completeSetPassword($found, 'outra-senha-456');

        $reload = new users_model();
        $reload->set_field([" idx ", " enabled ", " email_token ", " password "]);
        $reload->set_filter(["idx = ?"], [$userIdx]);
        $reload->set_paginate([1]);
        $reload->load_data();
        $row = $reload->data[0];

        $this->assertSame('yes', $row['enabled']);
        $this->assertNull($row['email_token']);
        $this->assertTrue(password_verify('outra-senha-456', $row['password']));
    }

    public function testExpiredTokenIsNotFoundRegardlessOfEnabledState(): void
    {
        $token = random_token();
        $this->makeUser('no', $token, date("Y-m-d H:i:s", strtotime("-1 hour")));

        $found = $this->lookupByToken($token);

        $this->assertNull($found, 'token expirado nao deve ser encontrado, mesmo para usuario enabled=no');
    }

    public function testUnknownTokenIsNotFound(): void
    {
        $found = $this->lookupByToken('token-que-nao-existe-' . uniqid());

        $this->assertNull($found);
    }
}
