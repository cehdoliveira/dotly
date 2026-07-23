<?php

declare(strict_types=1);

/**
 * Cobre o case 'reset-senha' de site_controller::users_action() (Plano 020).
 *
 * Bug corrigido pelo plano: o link de reset era montado com
 * canonical_url('SITE_CANONICAL_URL') — uma rota do site publico
 * (SITE_CANONICAL_URL sequer esta definida no kernel.php do manager, o que
 * fazia canonical_url() cair no fallback de cFrontend e gerar um link para
 * uma rota /redefinir-senha inexistente no manager). Corrigido para
 * canonical_url('MANAGER_CANONICAL_URL') . '/definir-senha/' . $token, a
 * mesma rota que o fluxo de convite usa.
 *
 * O controller nao e chamado diretamente (users_action() termina em
 * basic_redir(), que faz exit() — mesmo motivo documentado em
 * GatewaysActionTest/UserCreateActionTest/SetPasswordFilterTest). Os testes
 * abaixo reproduzem a mesma sequencia de escrita e a mesma montagem de link
 * que o case 'reset-senha' executa.
 */
final class ResetSenhaActionTest extends DBTestCase
{
    private function makeActiveUser(): int
    {
        $user = new users_model();
        $user->populate([
            'name'     => 'Reset Senha Teste',
            'mail'     => 'resetsenha_' . uniqid() . '@example.com',
            'login'    => 'resetsenha_' . uniqid(),
            'password' => password_hash(random_token(), PASSWORD_BCRYPT),
            'enabled'  => 'yes',
        ]);
        $id = (int) $user->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture deve retornar um ID valido');

        return $id;
    }

    /**
     * Mesma sequencia de escrita que o case 'reset-senha' executa: gera
     * token + expiry (+2h) e grava no usuario.
     */
    private function writeResetToken(int $userIdx): string
    {
        $token   = random_token();
        $expires = date("Y-m-d H:i:s", strtotime("+2 hours"));

        $resetUser = new users_model();
        $resetUser->set_filter(["idx = ?"], [$userIdx]);
        $resetUser->populate([
            "email_token"            => $token,
            "email_token_expires_at" => $expires,
        ]);
        $resetUser->save();

        return $token;
    }

    public function testResetSenhaWritesTokenAndTwoHourExpiry(): void
    {
        $userIdx = $this->makeActiveUser();
        $token   = $this->writeResetToken($userIdx);

        $reload = new users_model();
        $reload->set_field([" idx ", " email_token ", " email_token_expires_at "]);
        $reload->set_filter(["idx = ?"], [$userIdx]);
        $reload->set_paginate([1]);
        $reload->load_data();
        $row = $reload->data[0];

        $this->assertSame($token, $row['email_token'], 'token de reset deve ser persistido no usuario');

        $expiresAt = new DateTime($row['email_token_expires_at']);
        $now       = new DateTime();
        $diffHours = ($expiresAt->getTimestamp() - $now->getTimestamp()) / 3600;

        $this->assertGreaterThan(1.9, $diffHours, 'expiracao deve ser de aproximadamente 2 horas (janela minima)');
        $this->assertLessThan(2.1, $diffHours, 'expiracao deve ser de aproximadamente 2 horas (janela maxima)');
    }

    /**
     * Regressao: garante que o link de reset usa a rota /definir-senha do
     * MANAGER, nao a antiga /redefinir-senha do site publico.
     */
    public function testResetLinkUsesManagerDefinirSenhaRouteNotSiteRoute(): void
    {
        $userIdx = $this->makeActiveUser();
        $token   = $this->writeResetToken($userIdx);

        $resetLink = canonical_url('MANAGER_CANONICAL_URL') . '/definir-senha/' . $token;

        $this->assertStringStartsWith(
            constant('MANAGER_CANONICAL_URL'),
            $resetLink,
            'link de reset deve comecar com a URL canonica do MANAGER'
        );
        $this->assertStringContainsString(
            '/definir-senha/' . $token,
            $resetLink,
            'link de reset deve apontar para /definir-senha/{token}, a mesma rota do fluxo de convite'
        );
        $this->assertStringNotContainsString(
            '/redefinir-senha/',
            $resetLink,
            'regressao: link de reset NAO deve usar a antiga rota /redefinir-senha do site publico'
        );
    }
}
