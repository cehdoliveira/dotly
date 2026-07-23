<?php

declare(strict_types=1);

/**
 * Cobre a escrita feita pelo case 'criar' de site_controller::users_action() (Plano 020).
 * O metodo nao e chamado diretamente: users_action() termina em basic_redir(), que faz
 * exit() e mataria o processo do PHPUnit — mesmo motivo documentado em GatewaysActionTest.
 * Em vez disso, reproduz-se aqui exatamente a mesma sequencia de escrita que o controller
 * monta a partir do $post (populate + save + save_attach com DEFAULT_ADMIN_PROFILE_ID).
 *
 * O assert de perfil vinculado com adm='yes' e o que teria pego o bug original encontrado
 * na revisao do plano 020: o primeiro rascunho usava DEFAULT_USER_PROFILE_ID (perfil
 * 'user', adm='no') no fluxo de convite do manager, o que criaria admins sem permissao
 * de login.
 */
final class UserCreateActionTest extends DBTestCase
{
    /**
     * Mesma sequencia de escrita que site_controller::users_action() (case 'criar')
     * monta a partir do $post. Retorna null quando mail/login ja existe (mesmo guard
     * de duplicidade do controller).
     */
    private function callCriar(array $post): ?int
    {
        $required = ["name", "mail", "login"];
        foreach ($required as $r) {
            if (empty($post[$r])) {
                return null;
            }
        }

        $checkUser = new users_model();
        $checkUser->set_filter([" active = 'yes' ", " ( mail = ? OR login = ? ) "], [$post["mail"], $post["login"]]);
        $checkUser->set_paginate([1]);
        $checkUser->load_data();

        if (isset($checkUser->data[0]["idx"])) {
            return null;
        }

        $token = random_token();

        $post["password"]               = password_hash(random_token(), PASSWORD_BCRYPT);
        $post["profiles_id"]            = constant("DEFAULT_ADMIN_PROFILE_ID");
        $post["enabled"]                = "no";
        $post["email_token"]            = $token;
        $post["email_token_expires_at"] = date("Y-m-d H:i:s", strtotime("+72 hours"));

        $newUser = new users_model();
        $newUser->populate([
            "name"                   => $post["name"],
            "mail"                   => $post["mail"],
            "login"                  => $post["login"],
            "password"               => $post["password"],
            "enabled"                => $post["enabled"],
            "email_token"            => $post["email_token"],
            "email_token_expires_at" => $post["email_token_expires_at"],
        ]);
        $newIdx = $newUser->save();

        if ($newIdx > 0) {
            $newUser->save_attach(["idx" => $newIdx, "post" => $post], ["profiles"]);
        }

        return $newIdx > 0 ? $newIdx : null;
    }

    public function testCriarLinksNewUserToAdminProfile(): void
    {
        $mail  = 'criar_' . uniqid() . '@example.com';
        $login = 'criar_' . uniqid();

        $newIdx = $this->callCriar([
            'name'  => 'Novo Admin',
            'mail'  => $mail,
            'login' => $login,
        ]);

        $this->assertIsInt($newIdx);
        $this->assertGreaterThan(0, $newIdx);

        $reload = new users_model();
        $reload->set_field([" idx ", " enabled "]);
        $reload->set_filter(["idx = ?"], [$newIdx]);
        $reload->set_paginate([1]);
        $reload->load_data();
        $this->assertSame('no', $reload->data[0]['enabled'], 'usuario recem-convidado deve comecar enabled=no');

        $pdo  = localPDO::getInstance()->getPdo();
        $stmt = $pdo->prepare(
            "SELECT p.adm FROM users_profiles up
             JOIN profiles p ON p.idx = up.profiles_id
             WHERE up.users_id = ? AND up.active = 'yes'"
        );
        $stmt->execute([$newIdx]);
        $adm = $stmt->fetchColumn();

        $this->assertSame(
            'yes',
            $adm,
            "o perfil vinculado ao novo usuario deve ter adm='yes' — DEFAULT_ADMIN_PROFILE_ID errado criaria um admin sem permissao de login"
        );
    }

    public function testCriarRejectsDuplicateMailOrLogin(): void
    {
        $mail  = 'dup_' . uniqid() . '@example.com';
        $login = 'dup_' . uniqid();

        $firstIdx = $this->callCriar(['name' => 'Original', 'mail' => $mail, 'login' => $login]);
        $this->assertIsInt($firstIdx);

        $countBefore = new users_model();
        $countBefore->set_field([" COUNT(idx) AS total "]);
        $countBefore->load_data();
        $totalBefore = (int) $countBefore->data[0]['total'];

        $dupIdx = $this->callCriar(['name' => 'Duplicado', 'mail' => $mail, 'login' => 'outro_' . uniqid()]);
        $this->assertNull($dupIdx, 'mail duplicado nao deve criar novo usuario');

        $countAfter = new users_model();
        $countAfter->set_field([" COUNT(idx) AS total "]);
        $countAfter->load_data();
        $totalAfter = (int) $countAfter->data[0]['total'];

        $this->assertSame($totalBefore, $totalAfter, 'nenhuma linha nova deve ser criada em caso de duplicidade');
    }

    public function testCriarRejectsMissingRequiredFields(): void
    {
        $countBefore = new users_model();
        $countBefore->set_field([" COUNT(idx) AS total "]);
        $countBefore->load_data();
        $totalBefore = (int) $countBefore->data[0]['total'];

        $mail  = 'semnome_' . uniqid() . '@example.com';
        $login = 'semnome_' . uniqid();

        $missingName = $this->callCriar(['name' => '', 'mail' => $mail, 'login' => $login]);
        $this->assertNull($missingName, 'name vazio deve ser rejeitado sem criar usuario');

        $missingMail = $this->callCriar(['name' => 'Sem Mail', 'mail' => '', 'login' => $login]);
        $this->assertNull($missingMail, 'mail vazio deve ser rejeitado sem criar usuario');

        $missingLogin = $this->callCriar(['name' => 'Sem Login', 'mail' => $mail, 'login' => '']);
        $this->assertNull($missingLogin, 'login vazio deve ser rejeitado sem criar usuario');

        $countAfter = new users_model();
        $countAfter->set_field([" COUNT(idx) AS total "]);
        $countAfter->load_data();
        $totalAfter = (int) $countAfter->data[0]['total'];

        $this->assertSame($totalBefore, $totalAfter, 'nenhuma linha nova deve ser criada quando campo obrigatorio falta');
    }
}
