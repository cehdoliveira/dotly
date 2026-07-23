<?php
class auth_controller
{
    public static function check_login(): bool
    {
        if (!isset($_SESSION[constant("cAppKey")]["credential"]["idx"])) {
            return false;
        } else {
            return true;
        }
    }

	public function logout(array $info): never
	{
		validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["home_url"]);
		$_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        basic_redir($GLOBALS["login_url"]);
    }

    public function login(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["login_url"]);

        if (empty($info["post"]["login"]) || empty($info["post"]["password"])) {
            $_SESSION["messages_app"]["danger"] = ["Login e/ou Senha são obrigatórios para realizar o login"];
            basic_redir($GLOBALS["login_url"]);
        }

        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "login_attempts:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 5, 60)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde um momento antes de tentar novamente."];
            basic_redir($GLOBALS["login_url"]);
        }

        $users = new users_model();

        $users->set_field([" idx ", " name ", " mail ", " login ", " password "]);
        $users->set_filter([" active = 'yes' ", "enabled = 'yes'", "? IN (mail,login)"], [$info["post"]["login"]]);
        $users->set_paginate([1]);
        $users->load_data();
        $users->attach(["profiles"]);

        $user   = $users->data[0] ?? null;
        $userId = $user["idx"] ?? null;

        if ($userId) {
            $authenticated = verify_password_with_migration($user["password"] ?? '', $info["post"]["password"], $userId);
        } else {
            // Always run password_verify to prevent timing-based username enumeration
            password_verify($info["post"]["password"], '$2y$10$invalidhashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXe');
            $authenticated = false;
        }

        if ($authenticated && is_array($user)) {
            session_regenerate_id(true);

            $isAdmin = false;
            foreach (($user["profiles_attach"] ?? []) as $profile) {
                if (($profile["adm"] ?? 'no') === 'yes') {
                    $isAdmin = true;
                    break;
                }
            }

            if (!$isAdmin) {
                $_SESSION["messages_app"]["danger"] = ["Acesso não autorizado. Este painel é restrito a administradores."];
                basic_redir($GLOBALS["login_url"]);
            }

            $credential = $user;
            unset($credential["password"]);
            $_SESSION[constant("cAppKey")] = ["credential" => $credential];

            reset_rate_limit($redis, $rateKey);

            $update = new users_model();
            $update->set_filter(["idx = ?"], [(int)$credential["idx"]]);
            $update->populate(["last_login" => date("Y-m-d H:i:s")]);
            $update->save();
        } else {
            $_SESSION["messages_app"]["danger"] = ["Login e/ou Senha informados não conferem"];
        }

        basic_redir($authenticated ? $GLOBALS["home_url"] : $GLOBALS["login_url"]);
    }

    public function display_set_password(array $info): void
    {
        $token = $info[1] ?? null;

        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido."];
            basic_redir($GLOBALS["login_url"]);
        }

        // MySQL NOW() roda no fuso do container (UTC/SYSTEM), enquanto expires_at e
        // gravado pelo PHP em America/Sao_Paulo (UTC-3, ver kernel.php). Comparar
        // contra NOW() direto tornaria o token de reset (janela de 2h) sempre expirado
        // (skew de ~3h). Mesmo padrao de site_controller::salesKpis().
        $now = date("Y-m-d H:i:s");

        $users = new users_model();
        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " email_token = ? ", " email_token_expires_at > ? "], [$token, $now]);
        $users->set_paginate([1]);
        $users->load_data();

        if (!isset($users->data[0]["idx"])) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido ou expirado."];
            basic_redir($GLOBALS["login_url"]);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }
        $alpineControllers = ['setPassword'];
        $set_password_token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/set_password.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function set_password(array $info): never
    {
        validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["login_url"]);

        $token    = $info[1] ?? null;
        $password = $info["post"]["password"] ?? '';
        $confirm  = $info["post"]["password_confirm"] ?? '';

        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido."];
            basic_redir($GLOBALS["login_url"]);
        }

        if (empty($password) || strlen($password) < 6) {
            $_SESSION["messages_app"]["danger"] = ["Senha deve ter pelo menos 6 caracteres."];
            basic_redir(sprintf($GLOBALS["set_password_url"], $token));
        }

        if ($password !== $confirm) {
            $_SESSION["messages_app"]["danger"] = ["As senhas não conferem."];
            basic_redir(sprintf($GLOBALS["set_password_url"], $token));
        }

        // Ver comentario em display_set_password() sobre o skew NOW() (MySQL, UTC) vs
        // expires_at (PHP, America/Sao_Paulo).
        $now = date("Y-m-d H:i:s");

        $users = new users_model();
        $users->set_field([" idx "]);
        $users->set_filter([" active = 'yes' ", " email_token = ? ", " email_token_expires_at > ? "], [$token, $now]);
        $users->set_paginate([1]);
        $users->load_data();

        $userIdx = $users->data[0]["idx"] ?? null;

        if (!$userIdx) {
            $_SESSION["messages_app"]["danger"] = ["Link inválido ou expirado."];
            basic_redir($GLOBALS["login_url"]);
        }

        $users->set_filter(["idx = ?"], [$userIdx]);
        $users->populate([
            "enabled"            => "yes",
            "email_verified_at"  => date("Y-m-d H:i:s"),
            "password"           => password_hash($password, PASSWORD_BCRYPT),
            "email_token"        => null,
        ]);
        $users->save();

        session_regenerate_id(true);

        $_SESSION["messages_app"]["success"] = ["Senha definida! Você já pode fazer login."];
        basic_redir($GLOBALS["login_url"]);
    }

    public function display(array $info): void
    {
        if (self::check_login()) {
            basic_redir($GLOBALS["home_url"]);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }
        $alpineControllers = ['login'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/login.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }
}
