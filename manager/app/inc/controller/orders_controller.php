<?php
class orders_controller
{
    /**
     * Plano 003: leitura de `orders`. Sem acoes de escrita sobre `status` — quem
     * transiciona o status de pagamento e o webhook e o job de reconciliacao
     * (plano 002); ver escape hatch no plano se pedirem "marcar como pago" manual.
     * Plano 016 adiciona a 1a acao de escrita do controller (ship()), mas ela so
     * grava tracking_code/shipped_at — nunca `status`.
     */
    private const VALID_STATUSES = ['aguardando_pagamento', 'pago', 'cancelado', 'expirado'];

    /**
     * Pseudo-opcao do multi-select de status. "Enviado" NAO e um status de
     * pagamento (fica fora de VALID_STATUSES): o estado de envio e derivado de
     * `shipped_at IS NOT NULL` (ver docblock da classe e markAsShipped()). Como
     * eixo ortogonal ao pagamento, entra no filtro com AND, nunca no `status IN`.
     */
    private const SHIPPED_FILTER = 'enviado';

    /**
     * Teto de linhas da export CSV. `orders` e uma tabela de pagamentos ao vivo;
     * sem teto, um filtro amplo faz table scan completo com a transacao da
     * requisicao aberta o tempo todo (localPDO abre 1 transacao por requisicao).
     */
    private const EXPORT_ROW_LIMIT = 50000;

    /**
     * Mantem paridade com `orders.tracking_code VARCHAR(64)`
     * (migrations/028_add_tracking_to_orders.sql). Publica: reusada pelo
     * atributo maxlength do form em order_detail.php.
     */
    public const TRACKING_CODE_MAX_LENGTH = 64;

    /**
     * Minimo de digitos do sufixo de telefone aceito pelo filtro — o admin so
     * ve os ultimos 4 digitos na maioria das telas, entao 4 e o piso pratico.
     * Compartilhado entre buildFilter() e o placeholder do input em orders.php.
     */
    private const PHONE_FILTER_MIN_DIGITS = 4;

    /**
     * Whitelist de ordenacao das colunas de /pedidos: chave da querystring ->
     * expressao SQL crua do ORDER BY. So chaves desta lista viram ORDER BY —
     * o DOLModel injeta set_order() cru no SQL (rootOBJ: " order by " . implode),
     * entao uma chave forjada NUNCA pode chegar la (resolveSort cai no default).
     *
     * `gateway` espelha attachGatewayNames (a cobranca mais recente vence): uma
     * subquery correlacionada pega o nome do gateway da cobranca ativa mais nova
     * de cada pedido, para a coluna ordenar pelo mesmo valor que exibe.
     */
    private const SORTABLE = [
        'id'      => ' idx ',
        'token'   => ' token ',
        'cliente' => ' customer_name ',
        'status'  => ' status ',
        'gateway' => "(SELECT pg.name FROM pix_charges pc INNER JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id WHERE pc.active = 'yes' AND pc.orders_id = orders.idx ORDER BY pc.created_at DESC, pc.idx DESC LIMIT 1)",
        'total'   => ' total_cents ',
        'criado'  => ' created_at ',
        'pago'    => ' paid_at ',
    ];

    /** Coluna de ordenacao padrao quando nao ha `sort` (ou ele e invalido). */
    private const DEFAULT_SORT = 'criado';

    /**
     * Unico ponto de verdade do filtro de pedidos — index() e export() herdam daqui.
     * Qualquer filtro futuro (data, busca por cliente) deve ser adicionado aqui, nunca
     * duplicado inline em index() ou export(), para as duas visoes nao divergirem.
     *
     * @return array{0: string[], 1: array<int,mixed>} [conditions, params]
     */
    private function buildFilter(array $info): array
    {
        $conds  = [" active = 'yes' "];
        $params = [];

        // Status agora e multi-selecao: aceita `status[]` (form) ou uma string
        // unica (links antigos). Cada valor e checado contra VALID_STATUSES antes
        // de virar placeholder, entao um valor forjado nunca e bindado.
        // O filtro casa com o badge EXIBIDO na listagem: um pedido enviado aparece
        // como "Enviado", nunca com o badge de pagamento. Entao cada status de
        // pagamento so pega os NAO enviados (shipped_at IS NULL) e "Enviado" pega
        // os enviados. Como sao categorias de exibicao mutuamente exclusivas, os
        // ramos selecionados se unem por OR.
        $statuses = $this->normalizeStatuses($info['get']['status'] ?? '');
        $branches = [];
        if (!empty($statuses)) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $branches[]   = "status IN ({$placeholders}) AND shipped_at IS NULL";
            foreach ($statuses as $status) {
                $params[] = $status;
            }
        }
        if ($this->wantsShipped($info['get']['status'] ?? '')) {
            $branches[] = "shipped_at IS NOT NULL";
        }
        if (!empty($branches)) {
            // Grupo inteiro entre parenteses: ele e ANDeado com os demais filtros
            // (cpf, data, gateway), entao o OR interno nao pode vazar precedencia.
            $conds[] = ' ((' . implode(') OR (', $branches) . ')) ';
        }

        $cpfParam = $info['get']['cpf'] ?? '';
        $cpf = is_string($cpfParam) ? (preg_replace('/\D+/', '', $cpfParam) ?? '') : '';
        if (strlen($cpf) === 11) {
            $conds[]  = " customer_cpf = ? ";
            $params[] = $cpf;
        }

        $phoneParam = $info['get']['telefone'] ?? '';
        $phone = is_string($phoneParam) ? (preg_replace('/\D+/', '', $phoneParam) ?? '') : '';
        if (strlen($phone) >= self::PHONE_FILTER_MIN_DIGITS) {
            $conds[]  = " customer_phone LIKE ? ";
            $params[] = '%' . $phone;
        }

        // Intervalo de Data de Criacao — inclusivo nas duas pontas. Cada ponta e
        // opcional: so o inicio, so o fim, ou ambos. `created_at` tem indice
        // dedicado (idx_orders_created), entao o range nao vira table scan.
        $dateStart = $this->normalizeDate($info['get']['data_inicio'] ?? '');
        if ($dateStart !== null) {
            $conds[]  = " created_at >= ? ";
            $params[] = $dateStart . ' 00:00:00';
        }
        $dateEnd = $this->normalizeDate($info['get']['data_fim'] ?? '');
        if ($dateEnd !== null) {
            $conds[]  = " created_at <= ? ";
            $params[] = $dateEnd . ' 23:59:59';
        }

        // Gateway vive em `pix_charges`, nao em `orders` — filtramos por subquery
        // pelos pedidos que tem ao menos uma cobranca naquele gateway. O id vai
        // bindado; a subquery cobre pedidos com mais de uma cobranca sem duplicar
        // linhas (IN, nao JOIN).
        $gatewayId = $this->normalizeGatewayId($info['get']['gateway'] ?? '');
        if ($gatewayId !== null) {
            $conds[]  = " idx IN (SELECT orders_id FROM pix_charges WHERE active = 'yes' AND payment_gateways_id = ?) ";
            $params[] = $gatewayId;
        }

        return [$conds, $params];
    }

    /**
     * Valida o id de gateway do filtro: devolve um inteiro positivo ou null
     * (ausente/invalido). ctype_digit rejeita negativos, decimais e qualquer
     * coisa nao numerica antes de virar bind.
     *
     * @param mixed $raw
     */
    private function normalizeGatewayId($raw): ?int
    {
        $value = is_string($raw) ? trim($raw) : (is_int($raw) ? (string)$raw : '');
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $id = (int)$value;

        return $id > 0 ? $id : null;
    }

    /**
     * Resolve a ordenacao clicavel do cabecalho: mapeia `sort`/`dir` da
     * querystring para a tripla [chave validada, direcao, expressao ORDER BY].
     * A chave passa pela whitelist SORTABLE antes de virar SQL — valor forjado
     * cai no default (DEFAULT_SORT DESC), nunca e injetado. A direcao so aceita
     * 'asc'; qualquer outra coisa vira DESC. O `idx` no fim e desempate estavel,
     * para a paginacao nao embaralhar linhas de mesmo valor entre paginas.
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

        $expr = self::SORTABLE[$key] . ' ' . $dir . ', idx ' . $dir;

        return [$key, strtolower($dir), $expr];
    }

    /**
     * Normaliza o parametro de status para uma lista de status validos, sem
     * duplicatas. Aceita array (`status[]` do multi-select) ou string unica
     * (compatibilidade com links/bookmarks antigos de status unico). Qualquer
     * valor fora de VALID_STATUSES e descartado — nunca chega a virar bind.
     *
     * @param mixed $raw
     * @return string[]
     */
    private function normalizeStatuses($raw): array
    {
        if (is_string($raw)) {
            $raw = ($raw === '') ? [] : [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if (in_array($value, self::VALID_STATUSES, true) && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Detecta a pseudo-opcao SHIPPED_FILTER ("enviado") no parametro de status.
     * Mesma forma de entrada de normalizeStatuses (array do multi-select ou
     * string unica de link antigo), mas devolve so um booleano — "enviado" e um
     * eixo separado, nao um status de pagamento.
     *
     * @param mixed $raw
     */
    private function wantsShipped($raw): bool
    {
        if (is_string($raw)) {
            $raw = ($raw === '') ? [] : [$raw];
        }
        if (!is_array($raw)) {
            return false;
        }

        foreach ($raw as $value) {
            if (is_string($value) && trim($value) === self::SHIPPED_FILTER) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valida uma data `YYYY-MM-DD` e devolve normalizada, ou null se ausente/
     * invalida. Rejeita datas impossiveis (ex.: 2026-02-30) que o
     * createFromFormat rolaria para o mes seguinte.
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

    /**
     * Anexa o nome do gateway usado em cada pedido (via pix_charges ->
     * payment_gateways). Um pedido pode ter mais de uma cobranca (ex.: expirada
     * + nova); a ordenacao ASC faz a mais recente sobrescrever no map, entao a
     * coluna mostra o gateway da cobranca vigente. Resiliente por design: se a
     * consulta falhar, os pedidos voltam sem gateway_name (a view mostra "—"),
     * nunca esvaziando a listagem inteira.
     *
     * @param array<int,array<string,mixed>> $orders
     * @return array<int,array<string,mixed>>
     */
    private function attachGatewayNames(array $orders): array
    {
        foreach ($orders as $key => $order) {
            $orders[$key]['gateway_name'] = null;
        }
        if (empty($orders)) {
            return $orders;
        }

        try {
            $ids = array_map(static fn(array $o): int => (int)$o['idx'], $orders);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = (new pix_charges_model())->select(
                [" pc.orders_id AS orders_id ", " pg.name AS gateway_name "],
                "WHERE pc.active = 'yes' AND pc.orders_id IN ({$placeholders})
                  ORDER BY pc.orders_id ASC, pc.created_at ASC, pc.idx ASC",
                $ids,
                "pc",
                "INNER JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id"
            );
            $map  = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int)$row['orders_id']] = $row['gateway_name']; // mais recente vence
            }

            foreach ($orders as $key => $order) {
                $orders[$key]['gateway_name'] = $map[(int)$order['idx']] ?? null;
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->error("orders_gateway_attach failed", [
                "error" => $e->getMessage(),
            ]);
        }

        return $orders;
    }

    public function index(array $info): void
    {
        $perPage = 25;
        $page    = (int)($info['get']['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;

        $currentStatuses = $this->normalizeStatuses($info['get']['status'] ?? '');
        // "enviado" nao e status de pagamento (normalizeStatuses o descarta), mas
        // a UI precisa dele para manter o checkbox marcado, o rotulo do botao e a
        // propagacao em status[] nos links de exportar/paginar.
        if ($this->wantsShipped($info['get']['status'] ?? '')) {
            $currentStatuses[] = self::SHIPPED_FILTER;
        }

        $cpfParam   = $info['get']['cpf'] ?? '';
        $currentCpf = is_string($cpfParam) ? (preg_replace('/\D+/', '', $cpfParam) ?? '') : '';

        $phoneParam   = $info['get']['telefone'] ?? '';
        $currentPhone = is_string($phoneParam) ? (preg_replace('/\D+/', '', $phoneParam) ?? '') : '';

        $currentDateStart = $this->normalizeDate($info['get']['data_inicio'] ?? '') ?? '';
        $currentDateEnd   = $this->normalizeDate($info['get']['data_fim'] ?? '') ?? '';
        $currentGateway   = $this->normalizeGatewayId($info['get']['gateway'] ?? '') ?? 0;

        $phoneFilterMinDigits = self::PHONE_FILTER_MIN_DIGITS;

        [$currentSort, $currentDir, $orderExpr] = $this->resolveSort($info);

        // Opcoes do filtro de gateway. Falha aqui so esvazia o dropdown, nunca a
        // listagem de pedidos.
        $gateways = [];
        try {
            $gwModel = new payment_gateways_model();
            $gwModel->set_field([' idx ', ' name ']);
            $gwModel->set_filter([" active = 'yes' "]);
            $gwModel->set_order([' name ASC ']);
            $gwModel->load_data(false);
            $gateways = $gwModel->data;
        } catch (RuntimeException $e) {
            $gateways = [];
        }

        try {
            $model = new orders_model();

            [$conds, $params] = $this->buildFilter($info);
            $countStmt = $model->select(
                [" COUNT(*) AS total "],
                "WHERE " . implode(" AND ", $conds),
                $params
            );
            $total_orders = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $model->set_field([" idx ", " token ", " customer_name ", " status ", " total_cents ", " created_at ", " paid_at ", " shipped_at "]);
            $model->set_filter($conds, $params);
            $model->set_order([$orderExpr]);
            $model->set_paginate([$offset, $perPage]);
            $model->load_data(false);
            $orders = $this->attachGatewayNames($model->data);
        } catch (RuntimeException $e) {
            $orders       = [];
            $total_orders = 0;
        }

        $totalPages = (int)ceil($total_orders / $perPage);

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/orders.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function export(array $info): void
    {
        global $orders_url;

        try {
            [$conds, $params] = $this->buildFilter($info);
            $model = new orders_model();
            $model->set_field([" idx ", " token ", " customer_name ", " customer_mail ",
                " customer_phone ", " status ", " total_cents ", " created_at ", " paid_at "]);
            $model->set_filter($conds, $params);
            $model->set_order([" created_at DESC "]);
            $model->set_paginate([0, self::EXPORT_ROW_LIMIT]); // teto: ver EXPORT_ROW_LIMIT
            $model->load_data(false);
            $orders = $model->data;
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("orders_export failed", [
                "error" => $e->getMessage(),
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao exportar pedidos."];
            basic_redir($orders_url);
        }

        $rows = array_map(static function (array $o): array {
            return [
                'idx'            => $o['idx'],
                'token'          => $o['token'],
                'cliente'        => $o['customer_name'],
                'email'          => $o['customer_mail'],
                'telefone'       => $o['customer_phone'],
                'status'         => $o['status'],
                'total'          => number_format((int)$o['total_cents'] / 100, 2, ',', '.'),
                'criado_em'      => $o['created_at'],
                'pago_em'        => $o['paid_at'] ?? '',
            ];
        }, $orders);

        while (ob_get_level() > 0) { ob_end_clean(); } // limpa o ob_start() global do index.php
        array_to_csv($rows, 'pedidos_' . date('Y-m-d') . '.csv',
            ['idx', 'token', 'cliente', 'email', 'telefone', 'status', 'total', 'criado_em', 'pago_em']);
    }

    public function show(array $info): void
    {
        global $orders_url;

        $idx = (int)($info[1] ?? 0);
        if ($idx <= 0) {
            basic_redir($orders_url);
        }

        $order       = null;
        $gatewayName = null;

        try {
            $model = new orders_model();
            $model->set_field([
                " idx ", " token ", " status ", " customer_name ", " customer_mail ", " customer_phone ",
                " customer_cpf ", " ship_zip ", " ship_street ", " ship_number ", " ship_complement ",
                " ship_district ", " ship_city ", " ship_uf ", " subtotal_cents ", " fee_percent_cents ",
                " fee_fixed_cents ", " fee_infinity_cents ", " total_cents ", " created_at ", " paid_at ",
                " expires_at ", " tracking_code ", " shipped_at ",
            ]);
            $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
            $model->set_paginate([1]);
            $model->load_data(false);

            if (empty($model->data)) {
                $_SESSION["messages_app"]["danger"] = ["Pedido não encontrado."];
                basic_redir($orders_url);
            }

            $model->join('items', 'order_items', ['orders_id' => 'idx'], null, [
                ' idx ', ' orders_id ', ' products_id ', ' product_name ', ' variant ', ' qty ',
                ' unit_price_cents ', ' line_total_cents ',
            ]);
            // Nunca inclua qr_payload / qr_image_base64 — sao o meio de pagamento do
            // comprador, sem uso no admin.
            $model->join('charges', 'pix_charges', ['orders_id' => 'idx'], null, [
                ' idx ', ' orders_id ', ' payment_gateways_id ', ' gateway_charge_id ', ' status ',
                ' amount_cents ', ' expires_at ', ' paid_at ',
            ]);

            $order  = $model->data[0];
            $charge = $order['charges_attach'][0] ?? null;

            if ($charge) {
                $gwModel = new payment_gateways_model();
                $gwModel->set_field([' idx ', ' name ']);
                $gwModel->set_filter([" active = 'yes' ", " idx = ? "], [(int)$charge['payment_gateways_id']]);
                $gwModel->set_paginate([1]);
                $gwModel->load_data(false);
                $gatewayName = $gwModel->data[0]['name'] ?? null;
            }
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("orders_show failed", [
                "error" => $e->getMessage(),
                "idx"   => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao carregar pedido."];
            basic_redir($orders_url);
        }

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        $alpineControllers = ['orders'];

        include(constant("cRootServer") . "ui/page/order_detail.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    /**
     * Gera a etiqueta de envio (endereçamento padrão Correios) de um pedido —
     * página standalone, sem o chrome do manager (nao inclui head/header/
     * footer/foot), pronta para impressao. Somente leitura, mesmo padrao de
     * show(): sem redirect no sucesso, o localPDO::__destruct faz o safety-
     * rollback da transacao vazia. O botao so aparece em pedidos ainda nao
     * enviados, mas o endpoint renderiza para qualquer pedido valido (o
     * endereco nao muda apos o envio).
     */
    public function label(array $info): void
    {
        global $orders_url;

        $idx = (int)($info[1] ?? 0);
        if ($idx <= 0) {
            basic_redir($orders_url);
        }

        $order = null;

        try {
            $model = new orders_model();
            $model->set_field([
                " idx ", " token ", " customer_name ", " customer_phone ", " ship_zip ",
                " ship_street ", " ship_number ", " ship_complement ", " ship_district ",
                " ship_city ", " ship_uf ",
            ]);
            $model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
            $model->set_paginate([1]);
            $model->load_data(false);

            if (empty($model->data)) {
                $_SESSION["messages_app"]["danger"] = ["Pedido não encontrado."];
                basic_redir($orders_url);
            }

            $order = $model->data[0];
        } catch (RuntimeException $e) {
            Logger::getInstance()->error("orders_label failed", [
                "error" => $e->getMessage(),
                "idx"   => $idx,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Falha ao gerar etiqueta."];
            basic_redir($orders_url);
        }

        include(constant("cRootServer") . "ui/page/order_label.php");
    }

    /**
     * Plano 016: primeira acao de escrita deste controller (ate aqui 100%
     * leitura — ver docblock da classe). Marca o pedido como enviado
     * (tracking_code opcional + shipped_at) e enfileira o e-mail de aviso.
     * Nao mexe em `status`: o estado "enviado" e derivado de shipped_at, a
     * maquina de status de pagamento continua exclusiva do webhook.
     */
    public function ship(array $info): void
    {
        global $orders_url, $order_url;

        $idx = (int)($info[1] ?? 0);
        if ($idx <= 0) {
            basic_redir($orders_url);
        }

        $post = $info['post'] ?? [];
        validate_csrf($post['_csrf_token'] ?? null, $orders_url);

        $tracking = trim((string)($post['tracking_code'] ?? ''));
        if (mb_strlen($tracking) > self::TRACKING_CODE_MAX_LENGTH) {
            $tracking = mb_substr($tracking, 0, self::TRACKING_CODE_MAX_LENGTH);
        }

        try {
            $this->markAsShipped($idx, $tracking);
        } catch (\RuntimeException $e) {
            $_SESSION["messages_app"]["danger"] = [$e->getMessage()];
            basic_redir($orders_url);
        }

        $_SESSION["messages_app"]["success"] = ["Pedido marcado como enviado."];
        basic_redir(sprintf($order_url, $idx));
    }

    /**
     * Grava tracking_code/shipped_at e enfileira o e-mail de "pedido enviado".
     * Extraido sem basic_redir() para ser testavel diretamente — mesmo padrao
     * de checkout_controller::lockAndValidateCart().
     *
     * @throws \RuntimeException se o pedido nao existir/estiver inativo, ou se
     *   ja estiver marcado como enviado (evita reenvio silencioso — a UI ja
     *   esconde o form nesse caso, este guard cobre POST direto/2 abas
     *   abertas; achado da revisao adversarial, plano 016: sem o guard, um
     *   2o envio sobrescreve o tracking_code em silencio e o UNIQUE da fila
     *   descarta o e-mail com o codigo corrigido, sem avisar ninguem).
     */
    public function markAsShipped(int $orderId, string $trackingCode): void
    {
        $model = new orders_model();
        $model->set_field([' idx ', ' customer_name ', ' customer_mail ', ' shipped_at ']);
        $model->set_filter([" active = 'yes' ", " idx = ? "], [$orderId]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $order = $model->data[0] ?? null;
        if (!$order) {
            throw new \RuntimeException('Pedido não encontrado.');
        }
        if (!empty($order['shipped_at'])) {
            throw new \RuntimeException('Pedido já foi marcado como enviado.');
        }

        $update = new orders_model();
        $update->set_filter(["idx = ?"], [$orderId]);
        $update->populate([
            'tracking_code' => $trackingCode,
            'shipped_at'    => date('Y-m-d H:i:s'),
        ]);
        $update->save();

        // Commit ANTES do enfileiramento do e-mail, de proposito (achado da
        // revisao adversarial, plano 016 — mesmo raciocinio aplicado em
        // webhook_controller::processEvent()). localPDO e um singleton por
        // processo: TODO model (inclusive email_queue_model, chamado no
        // enqueue abaixo) compartilha a MESMA transacao desta requisicao. Se
        // o enqueue lancasse um erro real de banco (nao so um bug de
        // template), executePrepared() reverteria a transacao INTEIRA —
        // inclusive o save() de tracking_code/shipped_at acima — antes do
        // catch fail-open engolir o erro; ship() mostraria "Pedido marcado
        // como enviado" numa gravacao que nunca aconteceu (o commit() do
        // basic_redir() no fim seria um no-op silencioso). Commitando aqui,
        // um erro no e-mail so pode custar o proprio e-mail — nunca o
        // tracking_code/shipped_at ja durabilizado.
        localPDO::getInstance()->commit();
        localPDO::getInstance()->beginTransaction();

        // Fail-open: um erro de render do template (ou o proprio enqueue, que
        // ja e fail-open por si so) nao pode propagar sem tratamento nem
        // vazar um ob_start() aberto.
        try {
            ob_start();
            $name = $order['customer_name'];
            include(constant("cRootServer") . "ui/mail/order_shipped.php");
            $body = ob_get_clean();

            OrderMailQueue::enqueue(
                $orderId,
                'order_shipped',
                $order['customer_mail'],
                "Seu pedido foi enviado — " . constant('cStoreName'),
                (string)$body
            );
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            Logger::getInstance()->error('orders_controller::markAsShipped: falha ao renderizar/enfileirar e-mail de envio', [
                'orders_id' => $orderId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
