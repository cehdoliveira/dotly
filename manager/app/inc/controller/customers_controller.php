<?php
class customers_controller
{
    /**
     * Plano 023: tela /clientes. "Cliente" NAO e uma entidade no banco — as tabelas
     * customers/orders_customers foram removidas no plano 022 (migration 030). Aqui
     * o cliente e um agregado de `orders` agrupado por `customer_mail` (o unico
     * identificador com indice, migration 029): o pedido mais recente de cada e-mail
     * fornece nome/telefone/cidade/UF e a data da ultima compra. Por consequencia o
     * usuario Admin (que vive em `users`, nunca em `orders`) nunca aparece nesta lista.
     *
     * O identificador da rota /clientes/{idx} e o idx do pedido mais recente do
     * cliente — numerico (casa com o dispatcher) e serve tambem de alvo do atalho
     * "Último Pedido" (/pedidos/{idx}). show() resolve o customer_mail a partir dele.
     */
    private const PER_PAGE = 25;

    /**
     * Minimo de digitos do sufixo de telefone aceito pelo filtro — mesmo piso
     * pratico de orders_controller (o admin ve so os ultimos digitos).
     */
    private const PHONE_FILTER_MIN_DIGITS = 4;

    /**
     * Whitelist de ordenacao das colunas de /clientes: chave da querystring ->
     * expressao SQL crua do ORDER BY. So chaves desta lista viram ORDER BY (uma
     * chave forjada cai no default), mesmo contrato de orders_controller::SORTABLE.
     * Todas apontam para o pedido-ancora `o` (o mais recente do cliente), que e a
     * linha que a listagem exibe — a ordenacao casa com o valor mostrado.
     */
    private const SORTABLE = [
        'nome'          => ' o.customer_name ',
        'email'         => ' o.customer_mail ',
        'telefone'      => ' o.customer_phone ',
        'cidade'        => ' o.ship_city ',
        'ultima_compra' => ' o.created_at ',
    ];

    /** Coluna de ordenacao padrao quando nao ha `sort` (ou ele e invalido). */
    private const DEFAULT_SORT = 'ultima_compra';

    /**
     * EXISTS de bloqueio compartilhado por index/show/action: um cliente esta
     * bloqueado se e-mail, CPF ou telefone bater na blocklist (blocked_customers).
     * CPF/telefone vazios ('') nunca casam entre si (guarda "<> '' AND = ?"). Alias
     * do pedido interpolado ({$o}) — nunca recebe entrada do usuario, so um literal
     * definido no chamador.
     */
    private function blockedExistsSql(string $o): string
    {
        return "EXISTS (
            SELECT 1 FROM blocked_customers b
             WHERE b.active = 'yes'
               AND ( b.customer_mail = {$o}.customer_mail
                     OR ( b.customer_cpf <> '' AND b.customer_cpf = {$o}.customer_cpf )
                     OR ( b.customer_phone <> '' AND b.customer_phone = {$o}.customer_phone ) )
        )";
    }

    /**
     * Plano 030: idx da linha exata de blocked_customers que casa este pedido-ancora
     * (mesmo EXISTS de blockedExistsSql, so trocando o EXISTS por um scalar subquery
     * com o idx da linha). Usado para o botao Desbloquear mirar exatamente a linha
     * que causou o bloqueio — nunca um novo match por identificador no momento do
     * submit, que poderia atingir uma linha diferente (ex.: telefone compartilhado
     * por dois clientes reais). LIMIT 1 e deterministico: o indice funcional da
     * migration 038 garante no maximo uma linha ativa por identificador.
     */
    private function blockedIdxSql(string $o): string
    {
        return "(
            SELECT b.idx FROM blocked_customers b
             WHERE b.active = 'yes'
               AND ( b.customer_mail = {$o}.customer_mail
                     OR ( b.customer_cpf <> '' AND b.customer_cpf = {$o}.customer_cpf )
                     OR ( b.customer_phone <> '' AND b.customer_phone = {$o}.customer_phone ) )
             LIMIT 1
        )";
    }

    /**
     * Unico ponto de verdade dos filtros de /clientes — o COUNT e a pagina herdam
     * daqui, para as duas consultas nunca divergirem (mesmo padrao de
     * orders_controller::buildFilter). Todas as condicoes recaem sobre o pedido-
     * ancora `o` (o mais recente do cliente): nome/telefone/data casam com o valor
     * exibido na listagem; e-mail casa com a chave de agrupamento (o.customer_mail).
     *
     * @return array{0: string[], 1: array<int,mixed>} [conditions, params]
     */
    private function buildFilter(array $info): array
    {
        $conds  = [];
        $params = [];

        $name = trim((string)($info['get']['nome'] ?? ''));
        if ($name !== '') {
            $conds[]  = " o.customer_name LIKE ? ";
            $params[] = '%' . $name . '%';
        }

        $mail = trim((string)($info['get']['email'] ?? ''));
        if ($mail !== '') {
            $conds[]  = " o.customer_mail LIKE ? ";
            $params[] = '%' . $mail . '%';
        }

        $phoneParam = $info['get']['telefone'] ?? '';
        $phone = is_string($phoneParam) ? (preg_replace('/\D+/', '', $phoneParam) ?? '') : '';
        if (strlen($phone) >= self::PHONE_FILTER_MIN_DIGITS) {
            $conds[]  = " o.customer_phone LIKE ? ";
            $params[] = '%' . $phone;
        }

        // Intervalo da data da ultima compra (= created_at do pedido-ancora),
        // inclusivo nas duas pontas; cada ponta opcional.
        $dateStart = $this->normalizeDate($info['get']['data_inicio'] ?? '');
        if ($dateStart !== null) {
            $conds[]  = " o.created_at >= ? ";
            $params[] = $dateStart . ' 00:00:00';
        }
        $dateEnd = $this->normalizeDate($info['get']['data_fim'] ?? '');
        if ($dateEnd !== null) {
            $conds[]  = " o.created_at <= ? ";
            $params[] = $dateEnd . ' 23:59:59';
        }

        return [$conds, $params];
    }

    /**
     * Resolve a ordenacao clicavel do cabecalho: mapeia `sort`/`dir` da
     * querystring para a tripla [chave validada, direcao, expressao ORDER BY]. A
     * chave passa pela whitelist SORTABLE antes de virar SQL — valor forjado cai no
     * default. O `o.idx` no fim e desempate estavel para a paginacao.
     *
     * @return array{0:string,1:string,2:string} [chave, direcao(asc|desc), ORDER BY]
     */
    private function resolveSort(array $info): array
    {
        $rawKey = $info['get']['sort'] ?? '';
        $key    = is_string($rawKey) ? $rawKey : '';
        $dir    = (($info['get']['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';

        if (!isset(self::SORTABLE[$key])) {
            $key = self::DEFAULT_SORT;
            $dir = 'DESC';
        }

        $expr = self::SORTABLE[$key] . ' ' . $dir . ', o.idx ' . $dir;

        return [$key, strtolower($dir), $expr];
    }

    /**
     * Valida uma data `YYYY-MM-DD` e devolve normalizada, ou null se ausente/
     * invalida — rejeita datas impossiveis (ex.: 2026-02-30). Mesmo helper de
     * orders_controller::normalizeDate.
     *
     * @param mixed $raw
     */
    private function normalizeDate($raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $raw;
    }

    public function index(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $page = (int)($info['get']['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * self::PER_PAGE;

        // Valores atuais dos filtros — devolvidos a view para repovoar os campos e
        // propagar em links de ordenacao/paginacao.
        $currentName      = trim((string)($info['get']['nome'] ?? ''));
        $currentEmail     = trim((string)($info['get']['email'] ?? ''));
        $phoneParam       = $info['get']['telefone'] ?? '';
        $currentPhone     = is_string($phoneParam) ? (preg_replace('/\D+/', '', $phoneParam) ?? '') : '';
        $currentDateStart = $this->normalizeDate($info['get']['data_inicio'] ?? '') ?? '';
        $currentDateEnd   = $this->normalizeDate($info['get']['data_fim'] ?? '') ?? '';
        $phoneFilterMinDigits = self::PHONE_FILTER_MIN_DIGITS;

        [$currentSort, $currentDir, $orderExpr] = $this->resolveSort($info);

        $customers       = [];
        $total_customers = 0;

        try {
            $model = new orders_model();

            [$conds, $params] = $this->buildFilter($info);
            // As condicoes recaem sobre o pedido-ancora `o`, entao entram no WHERE
            // externo (nao na subquery de agrupamento). Sem filtro, WHERE vira "1".
            $where = empty($conds) ? '1' : implode(' AND ', $conds);

            // Cada grupo contribui com exatamente uma linha `o` (via max_idx), entao
            // COUNT(*) do join filtrado = numero de clientes que casam o filtro.
            $countStmt = $model->select(
                [" COUNT(*) AS total "],
                "WHERE {$where}",
                $params,
                "o",
                "INNER JOIN (
                     SELECT customer_mail, MAX(idx) AS max_idx
                       FROM orders
                      WHERE active = 'yes'
                      GROUP BY customer_mail
                    ) g ON g.max_idx = o.idx"
            );
            $total_customers = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            // O pedido mais recente de cada cliente = MAX(idx) por customer_mail (idx
            // cresce com o tempo). O JOIN traz os campos denormalizados desse pedido +
            // a contagem total de pedidos do cliente. LIMIT/OFFSET sao inteiros
            // calculados no servidor (cast), nunca entrada crua — seguro interpolar.
            $limit  = (int)self::PER_PAGE;
            $offset = (int)$offset;
            $stmt = $model->select(
                [" o.idx AS last_order_idx ", " o.customer_name ", " o.customer_mail ",
                 " o.customer_phone ", " o.customer_cpf ", " o.ship_city ", " o.ship_uf ",
                 " o.created_at AS last_purchase ", " g.orders_count ",
                 $this->blockedExistsSql('o') . " AS is_blocked",
                 $this->blockedIdxSql('o') . " AS blocked_idx"],
                "WHERE {$where}
                  ORDER BY {$orderExpr}
                  LIMIT {$limit} OFFSET {$offset}",
                $params,
                "o",
                "INNER JOIN (
                     SELECT customer_mail, MAX(idx) AS max_idx, COUNT(*) AS orders_count
                       FROM orders
                      WHERE active = 'yes'
                      GROUP BY customer_mail
                    ) g ON g.max_idx = o.idx"
            );
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (RuntimeException $e) {
            $customers       = [];
            $total_customers = 0;
        }

        $totalPages = (int)ceil($total_customers / self::PER_PAGE);

        $alpineControllers = ['customers'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/customers.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function show(array $info): void
    {
        global $customers_url;

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $idx = (int)($info[1] ?? 0);
        if ($idx <= 0) {
            basic_redir($customers_url);
        }

        $customer = null;
        $orders   = [];
        $summary  = ['orders_count' => 0, 'paid_cents' => 0, 'first_purchase' => null, 'last_purchase' => null];
        $isBlocked = false;

        try {
            $model = new orders_model();

            // Ancora: o pedido da rota identifica o cliente (customer_mail) e fornece
            // o CPF/telefone usados no snapshot de bloqueio.
            $anchorStmt = $model->select(
                [" customer_name ", " customer_mail ", " customer_phone ", " customer_cpf ", " ship_city ", " ship_uf ",
                 $this->blockedExistsSql('orders') . " AS is_blocked",
                 $this->blockedIdxSql('orders') . " AS blocked_idx"],
                "WHERE active = 'yes' AND idx = ? LIMIT 1",
                [$idx]
            );
            $customer = $anchorStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($customer === null) {
                $_SESSION["messages_app"]["danger"] = ["Cliente não encontrado."];
                basic_redir($customers_url);
            }

            $isBlocked  = (bool)$customer['is_blocked'];
            $blockedIdx = (int)($customer['blocked_idx'] ?? 0);
            $mail = (string)$customer['customer_mail'];

            $histStmt = $model->select(
                [" idx ", " token ", " status ", " total_cents ", " created_at ", " paid_at ", " shipped_at "],
                "WHERE active = 'yes' AND customer_mail = ? ORDER BY created_at DESC, idx DESC",
                [$mail]
            );
            $orders = $histStmt->fetchAll(PDO::FETCH_ASSOC);

            $sumStmt = $model->select(
                [" COUNT(*) AS orders_count ",
                 " COALESCE(SUM(CASE WHEN status = 'pago' THEN total_cents ELSE 0 END), 0) AS paid_cents ",
                 " MIN(created_at) AS first_purchase ", " MAX(created_at) AS last_purchase "],
                "WHERE active = 'yes' AND customer_mail = ?",
                [$mail]
            );
            $summary = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("customers_show failed", [
                "error" => $e->getMessage(),
                "idx"   => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao carregar o cliente."];
            basic_redir($customers_url);
        }

        $alpineControllers = ['customers'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/customer_detail.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    /**
     * Bloqueia ou desbloqueia o cliente ancorado por um idx de pedido: le
     * mail/CPF/telefone do pedido e grava/soft-deleta uma linha na blocklist.
     * Bloquear e idempotente — nao duplica se ja houver bloqueio casando qualquer
     * um dos tres identificadores. Desbloquear e soft-delete (active='no') via
     * remove(), casando pelos mesmos tres identificadores. So escrita desta tela.
     */
    public function action(array $info): void
    {
        global $customers_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';
        $idx    = (int)($post['idx'] ?? 0);

        validate_csrf($post['_csrf_token'] ?? null, $customers_url);

        if (($action !== 'bloquear' && $action !== 'desbloquear') || $idx <= 0) {
            basic_redir($customers_url);
        }

        $rollback = false;

        try {
            $model = new orders_model();
            $model->set_field([" customer_name ", " customer_mail ", " customer_cpf ", " customer_phone "]);
            $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
            $model->set_paginate([1]);
            $model->load_data(false);
            $order = $model->data[0] ?? null;

            if ($order === null) {
                $_SESSION["messages_app"]["danger"] = ["Cliente não encontrado."];
                basic_redir($customers_url);
            }

            $mail  = (string)($order['customer_mail'] ?? '');
            $cpf   = (string)($order['customer_cpf'] ?? '');
            $phone = (string)($order['customer_phone'] ?? '');

            if ($action === 'desbloquear') {
                // Plano 030 (revisao adversarial): mira a linha exata de
                // blocked_customers pelo seu proprio idx (capturado na tela via
                // blockedIdxSql(), no momento da renderizacao) — nunca um novo match
                // por mail/cpf/telefone no submit. Um match por identificador poderia
                // atingir a linha de OUTRO cliente que compartilhe telefone/CPF/e-mail
                // com este; mirar o idx exato garante que so a linha exibida como
                // "causa do bloqueio" desta tela e afetada.
                $blockedIdx = (int)($post['blocked_idx'] ?? 0);
                if ($blockedIdx <= 0) {
                    $_SESSION["messages_app"]["info"] = ["Este cliente não estava bloqueado."];
                    basic_redir($customers_url);
                }

                $block = new blocked_customers_model();
                $block->set_filter([" active = 'yes' ", " idx = ? "], [$blockedIdx]);
                try {
                    $stmt = $block->remove();
                    $affected = $stmt ? $stmt->rowCount() : 0;

                    if ($affected > 0) {
                        $_SESSION["messages_app"]["success"] = [
                            "Cliente " . htmlspecialchars((string)($order['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8') . " desbloqueado. Novos pedidos serão aceitos no checkout.",
                        ];
                    } else {
                        $_SESSION["messages_app"]["info"] = ["Este cliente não estava bloqueado."];
                    }
                } catch (RuntimeException $e) {
                    // Catch proprio: sem isso, uma falha aqui cairia no catch externo
                    // (linha ~450), que sempre mostra "Falha ao bloquear o cliente." —
                    // mensagem errada para uma falha de desbloqueio.
                    Logger::getInstance()->error("customers_unblock failed", [
                        "error"    => $e->getMessage(),
                        "order_id" => $idx,
                    ]);
                    $_SESSION["messages_app"]["danger"] = ["Falha ao desbloquear o cliente."];
                }
                basic_redir($customers_url);
            }

            $outcome = $this->tryBlockCustomer($mail, $cpf, $phone, $idx);
            if ($outcome === 'already_blocked') {
                $_SESSION["messages_app"]["info"] = ["Este cliente já está bloqueado."];
                basic_redir($customers_url);
            }
            if ($outcome === 'db_error') {
                $_SESSION["messages_app"]["danger"] = ["Falha ao bloquear o cliente."];
                basic_redir($customers_url);
            }

            $_SESSION["messages_app"]["success"] = [
                "Cliente " . htmlspecialchars((string)($order['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8') . " bloqueado. Novos pedidos serão recusados no checkout.",
            ];
        } catch (RuntimeException $e) {
            $rollback = true;
            Logger::getInstance()->error("customers_block failed", [
                "error"    => $e->getMessage(),
                "order_id" => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao bloquear o cliente."];
        }

        basic_redir($customers_url, rollback: $rollback);
    }

    /**
     * Pre-checagem + insert() do bloqueio, isolados do fluxo de mensagens/redirect
     * de action() para permitir teste direto via ReflectionMethod (basic_redir()
     * termina o processo, mesmo motivo da extracao de isBlocked() no checkout_controller).
     * Retorna 'blocked' (insert novo), 'already_blocked' (pre-check ou corrida via
     * UNIQUE key uniq_blocked_customers_active_mail, migration 038) ou 'db_error'
     * (falha real, sem corrida detectada).
     */
    private function tryBlockCustomer(string $mail, string $cpf, string $phone, int $orderIdx): string
    {
        $block = new blocked_customers_model();
        try {
            // Pre-checagem substitui o WHERE NOT EXISTS do INSERT antigo. A corrida
            // entre dois "Bloquear" concorrentes por e-mail continua fechada pelo
            // indice unico funcional uniq_blocked_customers_active_mail (migration
            // 038) — a violacao cai no catch abaixo e vira "ja bloqueado". Para
            // colisao apenas por CPF/telefone a janela e um pouco maior que a do
            // INSERT...SELECT (duas statements em vez de uma), aceito por decisao
            // de convencao: admin-UI, corrida improvavel e inofensiva.
            if ($this->customerMatchesBlocklist($block, $mail, $cpf, $phone)) {
                return 'already_blocked';
            }

            $block->insert([
                'customer_mail'  => $mail,
                'customer_cpf'   => $cpf,
                'customer_phone' => $phone,
                'blocked_at'     => date('Y-m-d H:i:s'),
            ]);

            return 'blocked';
        } catch (RuntimeException $e) {
            // localPDO normaliza qualquer PDOException em "Database error" generico
            // (SQLSTATE nao chega ate aqui) — nao da pra distinguir a violacao do
            // UNIQUE KEY (a corrida esperada) de uma falha de banco de verdade so
            // pelo tipo da excecao. Reconsulta pra confirmar: se already existe uma
            // linha batendo o cliente, foi a corrida (info, nao falha); senao, e uma
            // falha real e cai no mesmo tratamento do catch externo (log + danger).
            Logger::getInstance()->error("customers_block insert failed", [
                "error"    => $e->getMessage(),
                "order_id" => $orderIdx,
            ]);

            return $this->customerMatchesBlocklist($block, $mail, $cpf, $phone) ? 'already_blocked' : 'db_error';
        }
    }

    /**
     * Match da blocklist por mail/cpf/telefone — mesma query usada na
     * pre-checagem e na reconsulta de corrida de tryBlockCustomer(); extraida
     * pra nao duplicar o WHERE nos dois pontos.
     */
    private function customerMatchesBlocklist(blocked_customers_model $block, string $mail, string $cpf, string $phone): bool
    {
        $stmt = $block->select(
            [" 1 "],
            "WHERE active = 'yes'
                AND ( customer_mail = ?
                      OR ( customer_cpf <> '' AND customer_cpf = ? )
                      OR ( customer_phone <> '' AND customer_phone = ? ) )
              LIMIT 1",
            [$mail, $cpf, $phone]
        );

        return (bool) $stmt->fetchColumn();
    }
}
