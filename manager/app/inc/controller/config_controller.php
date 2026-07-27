<?php
class config_controller
{
    /**
     * Chaves de `settings` do endereco de remetente da loja (plano 025).
     * Ordem = ordem dos placeholders da query de leitura em index().
     * ATENCAO: esta lista existe DUPLICADA em orders_controller::loadSenderAddress()
     * e como default da view ui/page/config.php. Nao ha helper compartilhado de
     * proposito: app/inc/lib e app/inc/model tem que ser byte-identicos com
     * site/ (bin/check-shared-sync.sh), e o site nao usa remetente.
     *
     * @var list<string>
     */
    private const SENDER_KEYS = [
        'sender_name', 'sender_zip', 'sender_street', 'sender_number',
        'sender_complement', 'sender_district', 'sender_city', 'sender_uf',
    ];

    /** Campos que precisam estar preenchidos quando o form nao vem 100% vazio. */
    private const SENDER_REQUIRED_KEYS = [
        'sender_name', 'sender_zip', 'sender_street', 'sender_number',
        'sender_city', 'sender_uf',
    ];

    /** svalue e VARCHAR(255): trunca em vez de estourar o INSERT. */
    private const SENDER_FIELD_MAX_LENGTH = 255;

    /**
     * Tela de Configurações do manager: dados da conta do Admin logado + ajuste de
     * `enabled`/`monthly_limit_cents` por gateway. As Keys/credenciais dos gateways NAO
     * sao geridas aqui — permanecem em constantes do kernel.php (MP_ACCESS_TOKEN,
     * PAGBANK_TOKEN, INFINITEPAY_HANDLE, etc.). `slug` e `mode` sao sempre somente
     * leitura, mesmo tratamento do antigo gateways_controller (amarram ao adapter e a
     * tela de pagamento; editaveis viram bug de roteamento silencioso).
     */
    public function index(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $adminId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

        $perPage = 25;
        $page    = (int)($info['get']['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;

        $user     = [];
        $gateways = [];

        $salesSettings = [
            'sales_override'        => '',
            'sales_window_start_at' => '',
            'sales_window_end_at'   => '',
        ];
        $salesStatus = ['open' => true, 'reopens_at' => null, 'reason' => null];

        // Plano 025: remetente da loja (etiqueta de envio). Default vazio =
        // nao cadastrado; a view e a etiqueta lidam com isso.
        $senderSettings = array_fill_keys(self::SENDER_KEYS, '');

        // Gestao de usuarios admin — migrou de /usuarios (plano 023). Carregada num
        // try proprio para uma falha aqui nao derrubar dados da conta + gateways.
        $users         = [];
        $total_users   = 0;
        $active_users  = 0;
        $enabled_users = 0;
        $removed_users = 0;
        try {
            $usersModel = new users_model();

            $countStmt = $usersModel->select(
                [" COUNT(*) AS total ", " SUM(active = 'yes') AS ativos ", " SUM(active = 'yes' AND enabled = 'yes') AS habilitados "],
                "WHERE idx > 0"
            );
            $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'ativos' => 0, 'habilitados' => 0];

            $total_users   = (int)$counts['total'];
            $active_users  = (int)$counts['ativos'];
            $enabled_users = (int)$counts['habilitados'];
            $removed_users = $total_users - $active_users;

            $usersModel->set_field([" idx ", " name ", " mail ", " login ", " active ", " enabled ", " created_at ", " last_login ", " email_verified_at "]);
            $usersModel->set_filter([" idx > 0 "]);
            $usersModel->set_order([" created_at DESC "]);
            $usersModel->set_paginate([$offset, $perPage]);
            $usersModel->load_data(false);
            $users = $usersModel->data;
        } catch (RuntimeException $e) {
            $users         = [];
            $total_users   = 0;
            $active_users  = 0;
            $enabled_users = 0;
            $removed_users = 0;
        }
        $totalPages = (int)ceil($total_users / $perPage);

        try {
            $userModel = new users_model();
            $userModel->set_field([" idx ", " name ", " mail ", " login ", " phone "]);
            $userModel->set_filter([" active = 'yes' ", " idx = ? "], [$adminId]);
            $userModel->set_paginate([1]);
            $userModel->load_data();
            $user = $userModel->data[0] ?? [];

            $model = new payment_gateways_model();
            $model->set_field([" idx ", " name ", " slug ", " mode ", " enabled ", " monthly_limit_cents ", " max_order_cents "]);
            $model->set_filter([" active = 'yes' "]);
            $model->set_order([" name ASC "]);
            $model->load_data(false);
            $gateways = $model->data;

            $monthStart = date('Y-m-01 00:00:00');
            $chargesModel = new pix_charges_model();
            $stmt = $chargesModel->select(
                [" c.payment_gateways_id AS g ", " COALESCE(SUM(o.total_cents), 0) AS mtd "],
                "WHERE c.active = 'yes' AND o.status = 'pago' AND o.paid_at >= ? GROUP BY c.payment_gateways_id",
                [$monthStart],
                "c",
                "JOIN orders o ON o.idx = c.orders_id"
            );

            $mtdByGateway = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $mtdByGateway[(int)$row['g']] = (int)$row['mtd'];
            }

            foreach ($gateways as &$gateway) {
                $mtd   = $mtdByGateway[(int)$gateway['idx']] ?? 0;
                $limit = (int)$gateway['monthly_limit_cents'];

                $gateway['mtd_cents'] = $mtd;
                $gateway['usage_pct'] = $mtd / max(1, $limit) * 100;
            }
            unset($gateway);

            $settingsModel = new settings_model();
            $stmt = $settingsModel->select(
                [" skey ", " svalue "],
                "WHERE active = 'yes' AND skey IN (?, ?, ?)",
                array_keys($salesSettings)
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $salesSettings[$row['skey']] = (string) $row['svalue'];
            }

            $senderStmt = $settingsModel->select(
                [" skey ", " svalue "],
                "WHERE active = 'yes' AND skey IN (?, ?, ?, ?, ?, ?, ?, ?)",
                self::SENDER_KEYS
            );
            foreach ($senderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (array_key_exists($row['skey'], $senderSettings)) {
                    $senderSettings[$row['skey']] = (string) $row['svalue'];
                }
            }
            $salesStatus = SalesWindow::status();
        } catch (RuntimeException $e) {
            $user     = $user ?: [];
            $gateways = [];
        }

        $alpineControllers = ['dashboard'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/config.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function action(array $info): void
    {
        global $config_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';

        validate_csrf($post['_csrf_token'] ?? null, $config_url);

        $adminId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
        if ($adminId <= 0) {
            basic_redir($config_url);
        }

        if ($action === 'perfil') {
            $this->saveProfile($post, $adminId, $config_url);
        } elseif ($action === 'senha') {
            $this->savePassword($post, $adminId, $config_url);
        } elseif ($action === 'gateway') {
            $this->saveGateway($post, $config_url);
        } elseif ($action === 'janela') {
            $this->saveSalesWindow($post, $adminId, $config_url);
        } elseif ($action === 'remetente') {
            $this->saveSenderAddress($post, $adminId, $config_url);
        }

        basic_redir($config_url);
    }

    private function saveProfile(array $post, int $adminId, string $config_url): never
    {
        $name  = trim((string)($post['name'] ?? ''));
        $mail  = trim((string)($post['mail'] ?? ''));
        $login = trim((string)($post['login'] ?? ''));
        $phone = trim((string)($post['phone'] ?? ''));

        if ($name === '' || $mail === '' || $login === '') {
            $_SESSION["messages_app"]["danger"] = ["Nome, e-mail e login são obrigatórios."];
            basic_redir($config_url);
        }

        $rollback = false;

        try {
            if ($this->userConflictExists($adminId, $mail, $login)) {
                $_SESSION["messages_app"]["danger"] = ["Já existe outro usuário com esse e-mail/login."];
                basic_redir($config_url);
            }

            $update = new users_model();
            $update->set_filter(["idx = ?"], [$adminId]);
            $update->populate([
                'name'  => $name,
                'mail'  => $mail,
                'login' => $login,
                'phone' => $phone,
            ]);
            $update->save();

            $_SESSION[constant("cAppKey")]["credential"]["name"]  = $name;
            $_SESSION[constant("cAppKey")]["credential"]["mail"]  = $mail;
            $_SESSION[constant("cAppKey")]["credential"]["login"] = $login;

            $_SESSION["messages_app"]["success"] = ["Dados da conta atualizados com sucesso."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("config_action(perfil) failed", ["error" => $e->getMessage(), "idx" => $adminId]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao atualizar os dados da conta."];
        }

        basic_redir($config_url, rollback: $rollback);
    }

    private function savePassword(array $post, int $adminId, string $config_url): never
    {
        $current = (string)($post['senha_atual'] ?? '');
        $new     = (string)($post['senha_nova'] ?? '');
        $confirm = (string)($post['senha_confirma'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $_SESSION["messages_app"]["danger"] = ["Preencha a senha atual, a nova senha e a confirmação."];
            basic_redir($config_url);
        }

        if ($new !== $confirm) {
            $_SESSION["messages_app"]["danger"] = ["A nova senha e a confirmação não conferem."];
            basic_redir($config_url);
        }

        if (strlen($new) < 8) {
            $_SESSION["messages_app"]["danger"] = ["A nova senha deve ter ao menos 8 caracteres."];
            basic_redir($config_url);
        }

        $rollback = false;

        try {
            $userModel = new users_model();
            $userModel->set_field([" idx ", " password "]);
            $userModel->set_filter([" active = 'yes' ", " idx = ? "], [$adminId]);
            $userModel->set_paginate([1]);
            $userModel->load_data();
            $hash = (string)($userModel->data[0]["password"] ?? '');

            if ($hash === '' || !password_verify($current, $hash)) {
                $_SESSION["messages_app"]["danger"] = ["Senha atual incorreta."];
                basic_redir($config_url);
            }

            $update = new users_model();
            $update->set_filter(["idx = ?"], [$adminId]);
            $update->populate(["password" => password_hash($new, PASSWORD_BCRYPT)]);
            $update->save();

            $_SESSION["messages_app"]["success"] = ["Senha alterada com sucesso."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("config_action(senha) failed", ["error" => $e->getMessage(), "idx" => $adminId]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao alterar a senha."];
        }

        basic_redir($config_url, rollback: $rollback);
    }

    private function saveGateway(array $post, string $config_url): never
    {
        $idx = (int)($post['idx'] ?? 0);
        if ($idx <= 0) {
            basic_redir($config_url);
        }

        $enabled    = (($post['enabled'] ?? 'no') === 'yes') ? 'yes' : 'no';
        $limitCents = (int)preg_replace('/\D/', '', (string)($post['monthly_limit_cents'] ?? ''));

        // Teto por pedido: input vazio = NULL (sem teto / valor ilimitado). Vem
        // formatado em reais (mesma normalizacao do monthly_limit_cents).
        $maxOrderRaw   = trim((string)($post['max_order_cents'] ?? ''));
        $maxOrderCents = $maxOrderRaw === '' ? null : (int)preg_replace('/\D/', '', $maxOrderRaw);

        // Invariante (decisao do dono, 2026-07-22): entre os gateways HABILITADOS,
        // pelo menos 1 precisa ficar sem teto — o roteamento nunca pode ficar sem
        // rota para pedido de valor alto. Valida o estado RESULTANTE deste save
        // (o proprio gateway editado entra na conta com os valores novos).
        if ($this->violatesUnlimitedInvariant($idx, $enabled, $maxOrderCents)) {
            $_SESSION["messages_app"]["danger"] = ["Pelo menos um gateway habilitado precisa ficar sem limite por pedido (campo vazio)."];
            basic_redir($config_url);
        }

        $rollback = false;

        try {
            $update = new payment_gateways_model();
            $update->set_filter(["idx = ?"], [$idx]);
            $update->populate([
                'enabled'             => $enabled,
                'monthly_limit_cents' => $limitCents,
                'max_order_cents'     => $maxOrderCents,
            ]);
            $update->save();

            $_SESSION["messages_app"]["success"] = ["Gateway atualizado com sucesso."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("config_action(gateway) failed", ["error" => $e->getMessage(), "idx" => $idx]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao atualizar gateway."];
        }

        basic_redir($config_url, rollback: $rollback);
    }

    /**
     * True se, apos aplicar (enabled, maxOrderCents) ao gateway $idx, NENHUM
     * gateway habilitado ficaria sem teto (max_order_cents NULL). Sem nenhum
     * gateway habilitado no estado resultante, nao ha o que violar (false) —
     * desabilitar todos ja era possivel antes e continua sendo.
     */
    private function violatesUnlimitedInvariant(int $idx, string $enabled, ?int $maxOrderCents): bool
    {
        $model = new payment_gateways_model();
        $model->set_field([" idx ", " enabled ", " max_order_cents "]);
        $model->set_filter([" active = 'yes' "]);
        $model->load_data(false);

        $hasEnabled = false;
        foreach ($model->data as $g) {
            $gEnabled = (string)$g['enabled'];
            $gMax     = $g['max_order_cents'];
            if ((int)$g['idx'] === $idx) {           // aplica o estado pendente
                $gEnabled = $enabled;
                $gMax     = $maxOrderCents;
            }
            if ($gEnabled !== 'yes') {
                continue;
            }
            $hasEnabled = true;
            if ($gMax === null || $gMax === '') {    // achou 1 habilitado sem teto
                return false;
            }
        }

        return $hasEnabled;                          // habilitados existem e todos com teto
    }

    private function saveSalesWindow(array $post, int $adminId, string $config_url): never
    {
        $override = (string)($post['sales_override'] ?? '');
        if (!in_array($override, ['', 'open', 'closed'], true)) {
            $override = '';
        }
        $start = $this->normalizeLocalDatetime((string)($post['sales_window_start_at'] ?? ''));
        $end   = $this->normalizeLocalDatetime((string)($post['sales_window_end_at'] ?? ''));

        if ($start === null || $end === null) {
            $_SESSION["messages_app"]["danger"] = ["Data/hora inválida na janela de vendas."];
            basic_redir($config_url);
        }
        if ($start !== '' && $end !== '' && $end <= $start) {
            $_SESSION["messages_app"]["danger"] = ["O fim da janela deve ser depois do início."];
            basic_redir($config_url);
        }

        $rollback = false;
        try {
            $model = new settings_model();
            foreach ([
                'sales_override'        => $override,
                'sales_window_start_at' => $start,
                'sales_window_end_at'   => $end,
            ] as $key => $value) {
                // Upsert em 2 passos: INSERT IGNORE cobre base sem o seed 044;
                // UPDATE grava valor, reativa soft-delete (UNIQUE de skey abrange
                // linhas removidas) e carimba modified_at em PHP — fuso da conexao
                // ja alinhado ao do PHP em localPDO (plans/005), mas o carimbo
                // explicito continua por ser mais direto/estavel.
                $model->execute_raw_prepared(
                    "INSERT IGNORE INTO settings (created_at, created_by, active, skey, svalue) VALUES (?, ?, 'yes', ?, '')",
                    [date('Y-m-d H:i:s'), $adminId, $key]
                );
                $model->execute_raw_prepared(
                    "UPDATE settings SET svalue = ?, active = 'yes', modified_at = ?, modified_by = ? WHERE skey = ?",
                    [$value, date('Y-m-d H:i:s'), $adminId, $key]
                );
            }
            $_SESSION["messages_app"]["success"] = ["Janela de vendas atualizada."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("config_action(janela) failed", ["error" => $e->getMessage()]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao atualizar a janela de vendas."];
        }

        basic_redir($config_url, rollback: $rollback);
    }

    /**
     * Plano 025: endereco de remetente da loja, usado na 2a etiqueta de
     * /pedidos/{idx}/etiqueta. Regra: tudo-ou-nada. Form 100% vazio limpa o
     * remetente (etiqueta volta a imprimir so o destinatario); qualquer campo
     * preenchido exige o conjunto minimo de SENDER_REQUIRED_KEYS valido.
     *
     * `sender_zip` e normalizado para SO DIGITOS e `sender_uf` para maiusculas —
     * a view da etiqueta formata o CEP na hora de imprimir.
     */
    private function saveSenderAddress(array $post, int $adminId, string $config_url): never
    {
        $values = [];
        foreach (self::SENDER_KEYS as $key) {
            $values[$key] = mb_substr(trim((string)($post[$key] ?? '')), 0, self::SENDER_FIELD_MAX_LENGTH);
        }

        $values['sender_zip'] = preg_replace('/\D+/', '', $values['sender_zip']) ?? '';
        $values['sender_uf']  = mb_strtoupper($values['sender_uf']);

        $filled = array_filter($values, static fn(string $v): bool => $v !== '');

        if ($filled !== []) {
            foreach (self::SENDER_REQUIRED_KEYS as $key) {
                if ($values[$key] === '') {
                    $_SESSION["messages_app"]["danger"] = ["Preencha nome, CEP, logradouro, número, cidade e UF do remetente (ou deixe todos os campos vazios para remover o remetente)."];
                    basic_redir($config_url);
                }
            }
            if (strlen($values['sender_zip']) !== 8) {
                $_SESSION["messages_app"]["danger"] = ["CEP do remetente inválido: informe 8 dígitos."];
                basic_redir($config_url);
            }
            if (preg_match('/^[A-Z]{2}$/', $values['sender_uf']) !== 1) {
                $_SESSION["messages_app"]["danger"] = ["UF do remetente inválida: informe 2 letras (ex.: SP)."];
                basic_redir($config_url);
            }
        }

        $rollback = false;

        try {
            $model = new settings_model();
            foreach ($values as $key => $value) {
                // Mesmo upsert 2-passos de saveSalesWindow(): INSERT IGNORE cobre
                // base sem o seed da migration 015; UPDATE grava, reativa
                // soft-delete (UNIQUE de skey abrange linha removida) e carimba
                // modified_at em PHP.
                $model->execute_raw_prepared(
                    "INSERT IGNORE INTO settings (created_at, created_by, active, skey, svalue) VALUES (?, ?, 'yes', ?, '')",
                    [date('Y-m-d H:i:s'), $adminId, $key]
                );
                $model->execute_raw_prepared(
                    "UPDATE settings SET svalue = ?, active = 'yes', modified_at = ?, modified_by = ? WHERE skey = ?",
                    [$value, date('Y-m-d H:i:s'), $adminId, $key]
                );
            }

            $_SESSION["messages_app"]["success"] = $filled === []
                ? ["Endereço de remetente removido."]
                : ["Endereço de remetente atualizado."];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("config_action(remetente) failed", ["error" => $e->getMessage()]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao atualizar o endereço de remetente."];
        }

        basic_redir($config_url, rollback: $rollback);
    }

    /** '' => ''; 'Y-m-d\TH:i' (datetime-local) => 'Y-m-d H:i:00'; inválido => null. */
    private function normalizeLocalDatetime(string $value): ?string
    {
        if ($value === '') {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
        if ($dt === false || $dt->format('Y-m-d\TH:i') !== $value) {
            return null;
        }
        return $dt->format('Y-m-d H:i:00');
    }

    /**
     * Gestao de usuarios admin (criar/editar/ativar/inativar/remover/reset-senha/
     * export). Migrou de site_controller::users_action (/usuarios) para dentro de
     * Configuracoes no plano 023. Posta em /config/usuarios e sempre retorna a
     * /config (a pagina que lista os usuarios).
     */
    public function users_action(array $info): void
    {
        global $config_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';
        $idx    = (int)($post['idx'] ?? 0);

        validate_csrf($post['_csrf_token'] ?? null, $config_url);

        if ($action === 'export-csv') {
            $model = new users_model();
            $model->set_field([" idx ", " name ", " mail ", " login ", " enabled ", " active ", " created_at ", " last_login "]);
            $model->set_filter([" idx > 0 "]);
            $model->set_order([" created_at DESC "]);
            $model->load_data();

            $headers = ['idx', 'name', 'mail', 'login', 'enabled', 'active', 'created_at', 'last_login'];
            array_to_csv($model->data, 'usuarios_' . date('Y-m-d') . '.csv', $headers);
        }

        if ($action === 'criar') {
            $required = ["name", "mail", "login"];
            foreach ($required as $r) {
                if (empty($post[$r])) {
                    $_SESSION["messages_app"]["danger"] = ["Campo $r é obrigatório"];
                    basic_redir($config_url);
                }
            }

            try {
                if ($this->userConflictExists(null, $post["mail"], $post["login"])) {
                    $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login"];
                    basic_redir($config_url);
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

                    $mailSent = false;
                    $setPasswordLink = '';
                    try {
                        $name             = $post["name"];
                        $login            = $post["login"];
                        $canonicalBase    = canonical_url('MANAGER_CANONICAL_URL');
                        $loginLink        = $canonicalBase . '/login';
                        $setPasswordLink  = $canonicalBase . '/definir-senha/' . $token;
                        $subject          = "Seus dados de acesso — " . constant('cTitle');
                        ob_start();
                        include(constant("cRootServer") . "ui/mail/new_admin_credentials.php");
                        $body = ob_get_clean();

                        if (class_exists("EmailProducer")) {
                            $producer = EmailProducer::getInstance();
                            $mailSent = $producer->send($post["mail"], $subject, $body);
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao enviar email de cadastro: " . $e->getMessage());
                    }

                    if ($mailSent) {
                        $_SESSION["messages_app"]["success"] = ["Usuário criado com sucesso. Um email foi enviado com as instruções para definir a senha."];
                    } elseif ($setPasswordLink !== '') {
                        // O e-mail NAO saiu (rdkafka ausente, broker fora, erro de
                        // template). O usuario existe com enabled='no' e senha
                        // aleatoria: sem o link abaixo ele fica inacessivel e o token
                        // expira em 72h. Mostrar o link e o unico caminho de
                        // recuperacao sem acesso ao banco. Quem ve a mensagem e o
                        // admin que acabou de criar a conta — nao ha escalada de
                        // privilegio em exibir a ele o link que ele mesmo gerou.
                        Logger::getInstance()->error('config_controller: e-mail de credenciais de admin nao foi enviado', [
                            'mail'      => $post["mail"],
                            'kafka'     => EmailProducer::isAvailable(),
                        ]);
                        $_SESSION["messages_app"]["warning"] = [
                            "Usuário criado, mas o e-mail NÃO pôde ser enviado. Entregue este link ao novo usuário (validade 72h): " . $setPasswordLink,
                        ];
                    } else {
                        // canonical_url() lancou antes de montar o link (kernel.php sem
                        // MANAGER_CANONICAL_URL nem ALLOWED_HOSTS configurados, fail-closed
                        // — ver CommonFunctions.php). Sem link nenhum pra mostrar, o aviso
                        // tem que dizer isso em vez de prometer um link vazio.
                        Logger::getInstance()->error('config_controller: link de definicao de senha nao pode ser gerado (canonical_url falhou)', [
                            'mail' => $post["mail"],
                        ]);
                        $_SESSION["messages_app"]["warning"] = [
                            "Usuário criado, mas não foi possível gerar o link de definição de senha nem enviar o e-mail (configuração de URL canônica ausente). Verifique MANAGER_CANONICAL_URL/ALLOWED_HOSTS no kernel.php.",
                        ];
                    }
                    basic_redir($config_url);
                } else {
                    $_SESSION["messages_app"]["danger"] = ["Falha ao criar usuário. Tente novamente mais tarde."];
                    basic_redir($config_url);
                }
            } catch (Exception $e) {
                error_log("Erro ao criar usuário: " . $e->getMessage());
                $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login ou ocorreu um erro. Tente novamente."];
                basic_redir($config_url, rollback: true);
            }
        }

        if ($idx <= 0) {
            basic_redir($config_url);
        }

        $adminId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

        if (($action === 'remover' || $action === 'inativar') && $idx === $adminId) {
            $_SESSION["messages_app"]["danger"] = ["Você não pode remover ou inativar a própria conta."];
            basic_redir($config_url);
        }

        $rollback = false;

        try {
            $update  = new users_model();
            $update->set_filter(["idx = ?"], [$idx]);

            if ($action === 'inativar') {
                $update->populate(["enabled" => "no"]);
                $update->save();
            } elseif ($action === 'ativar') {
                $update->populate(["enabled" => "yes"]);
                $update->save();
            } elseif ($action === 'remover') {
                $update->remove();
            } elseif ($action === 'editar') {
                $name = trim($post['name'] ?? '');
                $mail = trim($post['mail'] ?? '');
                if ($name !== '' && $mail !== '') {
                    if ($this->userConflictExists($idx, $mail)) {
                        $_SESSION["messages_app"]["danger"] = ["Já existe outro usuário com esse e-mail."];
                        basic_redir($config_url);
                    }

                    $update->populate(['name' => $name, 'mail' => $mail]);
                    $update->save();
                }
            } elseif ($action === 'reset-senha') {
                $resetUser = new users_model();
                $resetUser->set_field([" idx ", " name ", " mail "]);
                $resetUser->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
                $resetUser->set_paginate([1]);
                $resetUser->load_data();
                $user = $resetUser->data[0] ?? null;

                if ($user) {
                    $token   = random_token();
                    $expires = date("Y-m-d H:i:s", strtotime("+2 hours"));

                    $resetUser->set_filter(["idx = ?"], [$idx]);
                    $resetUser->populate([
                        "email_token"           => $token,
                        "email_token_expires_at" => $expires,
                    ]);
                    $resetUser->save();

                    $resetLink = canonical_url('MANAGER_CANONICAL_URL') . '/definir-senha/' . $token;
                    $name      = $user['name'];
                    $subject   = "Redefinição de senha — " . constant('cTitle');
                    ob_start();
                    include(constant("cRootServer") . "ui/mail/reset_password.php");
                    $body = ob_get_clean();

                    $mailSent = false;
                    try {
                        if (class_exists("EmailProducer")) {
                            $producer = EmailProducer::getInstance();
                            $mailSent = $producer->send($user['mail'], $subject, $body);
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao enviar reset de senha: " . $e->getMessage());
                    }

                    if ($mailSent) {
                        $_SESSION["messages_app"]["success"] = ["Link de redefinição de senha enviado para " . $user['mail'] . "."];
                    } else {
                        Logger::getInstance()->error('config_controller: e-mail de reset de senha nao foi enviado', [
                            'mail'      => $user['mail'],
                            'kafka'     => EmailProducer::isAvailable(),
                        ]);
                        $_SESSION["messages_app"]["warning"] = [
                            "Link de redefinição gerado, mas o e-mail NÃO pôde ser enviado. Entregue este link ao usuário (validade 2h): " . $resetLink,
                        ];
                    }
                }
            }
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("users_action failed", [
                "error"   => $e->getMessage(),
                "action"  => $action,
                "user_id" => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao atualizar usuário. Tente novamente mais tarde."];
        }

        basic_redir($config_url, rollback: $rollback);
    }

    /**
     * Existe outro usuario ativo com esse mail (e, se $login for informado,
     * tambem esse login)? $excludeIdx exclui o proprio registro da checagem
     * (edicao); null = sem exclusao (criacao).
     */
    private function userConflictExists(?int $excludeIdx, string $mail, ?string $login = null): bool
    {
        $conds  = [" active = 'yes' "];
        $params = [];

        if ($excludeIdx !== null) {
            $conds[]  = " idx <> ? ";
            $params[] = $excludeIdx;
        }

        if ($login !== null) {
            $conds[]  = " ( mail = ? OR login = ? ) ";
            $params[] = $mail;
            $params[] = $login;
        } else {
            $conds[]  = " mail = ? ";
            $params[] = $mail;
        }

        $check = new users_model();
        $check->set_field([" idx "]);
        $check->set_filter($conds, $params);
        $check->set_paginate([1]);
        $check->load_data();

        return isset($check->data[0]["idx"]);
    }
}
