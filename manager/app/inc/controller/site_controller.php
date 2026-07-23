<?php
class site_controller
{
    /**
     * Plano 011: dashboard de vendas — tela inicial pos-login (`/`, `/admin`).
     * A gestao de usuarios admin migrou para dentro de Configuracoes (/config,
     * config_controller::users_action) no plano 023.
     */
    public function salesDashboard(array $info): void
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $kpis     = $this->salesKpis();
        $byStatus = $this->ordersByStatus();
        $topProd  = $this->topProducts();
        $recent   = $this->recentOrders();
        $gateways = $this->paymentGateways();

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/sales_dashboard.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    /**
     * Factories protegidas para as models usadas pelas agregacoes abaixo —
     * unico ponto de substituicao para simular falha de banco em teste
     * (catch (RuntimeException) de cada metodo), sem introduzir DI generico
     * no framework: uma subclasse de teste sobrescreve so a factory da
     * model que quer forcar a falhar.
     */
    protected function newOrdersModel(): orders_model
    {
        return new orders_model();
    }

    protected function newOrderItemsModel(): order_items_model
    {
        return new order_items_model();
    }

    protected function newPaymentGatewaysModel(): payment_gateways_model
    {
        return new payment_gateways_model();
    }

    /**
     * Faturamento pago e pedidos pagos do mes corrente, ticket medio e
     * pedidos aguardando pagamento agora. Extraido sem include/redirect para
     * ser exercitavel diretamente por teste — mesmo padrao de
     * checkout_controller::lockAndValidateCart().
     *
     * @return array{revenue_cents:int, paid_orders:int, avg_ticket_cents:int, awaiting:int}
     */
    public function salesKpis(): array
    {
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd   = date('Y-m-01 00:00:00', strtotime('+1 month'));
        // MySQL NOW() roda no fuso do container (UTC/SYSTEM), enquanto expires_at e
        // gravado pelo PHP em America/Sao_Paulo (UTC-3, ver kernel.php). Comparar
        // contra NOW() direto tornaria "aguardando" sempre ~0 (skew de 3h contra uma
        // janela de 30min). Vincula o "agora" calculado pelo PHP em vez disso.
        $now = date('Y-m-d H:i:s');

        try {
            $model = $this->newOrdersModel();
            $stmt = $model->select(
                [" COALESCE(SUM(CASE WHEN status = 'pago' AND paid_at >= ? AND paid_at < ? THEN total_cents ELSE 0 END), 0) AS revenue_cents ",
                 " COALESCE(SUM(CASE WHEN status = 'pago' AND paid_at >= ? AND paid_at < ? THEN 1 ELSE 0 END), 0) AS paid_orders ",
                 " COALESCE(SUM(CASE WHEN status = 'aguardando_pagamento' AND expires_at > ? THEN 1 ELSE 0 END), 0) AS awaiting "],
                "WHERE active = 'yes'
                    AND (
                         (status = 'pago' AND paid_at >= ? AND paid_at < ?)
                         OR (status = 'aguardando_pagamento' AND expires_at > ?)
                    )",
                [$monthStart, $monthEnd, $monthStart, $monthEnd, $now, $monthStart, $monthEnd, $now]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['revenue_cents' => 0, 'paid_orders' => 0, 'awaiting' => 0];

            $revenue    = (int)$row['revenue_cents'];
            $paidOrders = (int)$row['paid_orders'];
            $awaiting   = (int)$row['awaiting'];
            $avgTicket  = $paidOrders > 0 ? (int)round($revenue / $paidOrders) : 0;
        } catch (RuntimeException $e) {
            $revenue    = 0;
            $paidOrders = 0;
            $awaiting   = 0;
            $avgTicket  = 0;
        }

        return [
            'revenue_cents'    => $revenue,
            'paid_orders'      => $paidOrders,
            'avg_ticket_cents' => $avgTicket,
            'awaiting'         => $awaiting,
        ];
    }

    /**
     * Contagem de pedidos por status nos ultimos 30 dias. Sempre devolve as
     * 4 chaves de status validas (0 quando nao ha dados no periodo).
     *
     * @return array<string,int>
     */
    public function ordersByStatus(): array
    {
        $counts = [
            'aguardando_pagamento' => 0,
            'pago'                 => 0,
            'cancelado'            => 0,
            'expirado'             => 0,
        ];

        $since = date('Y-m-d H:i:s', strtotime('-30 days'));

        try {
            $model = $this->newOrdersModel();
            $stmt = $model->select(
                [" status ", " COUNT(*) AS total "],
                "WHERE active = 'yes' AND created_at >= ? GROUP BY status",
                [$since]
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($counts[$row['status']])) {
                    $counts[$row['status']] = (int)$row['total'];
                }
            }
        } catch (RuntimeException $e) {
            // mantem zeros
        }

        return $counts;
    }

    /**
     * Top 5 produtos por quantidade vendida (pedidos pagos, ultimos 30 dias).
     *
     * @return array<int, array{products_id:int, product_name:string, total_qty:int}>
     */
    public function topProducts(): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));

        try {
            $model = $this->newOrderItemsModel();
            $stmt = $model->select(
                [" oi.products_id ", " oi.product_name ", " SUM(oi.qty) AS total_qty "],
                "WHERE oi.active = 'yes' AND o.status = 'pago' AND o.paid_at >= ?
                  GROUP BY oi.products_id, oi.product_name
                  ORDER BY total_qty DESC
                  LIMIT 5",
                [$since],
                "oi",
                "JOIN orders o ON o.idx = oi.orders_id AND o.active = 'yes'"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (RuntimeException $e) {
            $rows = [];
        }

        return array_map(static fn(array $r): array => [
            'products_id'  => (int)$r['products_id'],
            'product_name' => $r['product_name'],
            'total_qty'    => (int)$r['total_qty'],
        ], $rows);
    }

    /**
     * Ultimos 10 pedidos (qualquer status), mais recentes primeiro.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentOrders(): array
    {
        try {
            $model = $this->newOrdersModel();
            $model->set_field([" idx ", " customer_name ", " total_cents ", " status ", " created_at "]);
            $model->set_filter([" active = 'yes' "]);
            $model->set_order([" created_at DESC "]);
            $model->set_paginate([10]);
            $model->load_data(false);
            return $model->data;
        } catch (RuntimeException $e) {
            return [];
        }
    }

    /**
     * Gateways de pagamento ativos com o faturamento efetivamente pago do mes
     * corrente por gateway. So conta pagamentos 'pago' (orders.status = 'pago',
     * paid_at no mes), agregados via pix_charges.payment_gateways_id — mesma
     * fonte da tela de Configuracoes. Ordenado por faturamento desc, depois nome.
     *
     * @return array<int, array{name:string, enabled:string, mtd_cents:int}>
     */
    public function paymentGateways(): array
    {
        $monthStart = date('Y-m-01 00:00:00');

        try {
            $model = $this->newPaymentGatewaysModel();
            $model->set_field([" idx ", " name ", " enabled "]);
            $model->set_filter([" active = 'yes' "]);
            $model->set_order([" name ASC "]);
            $model->load_data(false);
            $gateways = $model->data;

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
        } catch (RuntimeException $e) {
            return [];
        }

        $result = array_map(static fn(array $g): array => [
            'name'      => (string)($g['name'] ?? ''),
            'enabled'   => ($g['enabled'] ?? 'no') === 'yes' ? 'yes' : 'no',
            'mtd_cents' => $mtdByGateway[(int)$g['idx']] ?? 0,
        ], $gateways);

        usort($result, static fn(array $a, array $b): int => ($b['mtd_cents'] <=> $a['mtd_cents']) ?: strcmp($a['name'], $b['name']));

        return $result;
    }
}
