<?php

/**
 * Plano 032: expira pedidos `aguardando_pagamento` cujo `expires_at` ja
 * passou e devolve o estoque reservado no checkout. Extraida para
 * app/inc/lib/ (mesmo padrao de EmailQueueDispatcher, plano 016) para ser
 * testavel — site/cgi-bin/expire_orders.php e so a casca do cron.
 *
 * Guarda de corrida: a transicao de status usa um UPDATE condicional
 * (WHERE status = 'aguardando_pagamento'). Se o webhook ja tiver marcado o
 * pedido como 'pago' entre o SELECT dos candidatos e a expiracao, o UPDATE
 * nao afeta nenhuma linha e o pedido e pulado sem tocar no estoque — e o
 * que impede overselling por corrida com o webhook.
 */
class OrderExpirer
{
    private const BATCH_SIZE = 200;

    /**
     * Varre um lote de pedidos vencidos e expira cada um numa unidade
     * transacional propria (commit por pedido, nao um so no fim — mesmo
     * motivo de dispatch_emails.php: uma falha isolada nao derruba o
     * commit ja feito de pedidos anteriores no mesmo lote).
     *
     * $now vem do PHP (America/Sao_Paulo), nunca de NOW() do MySQL — evita
     * o skew de fuso ja conhecido no repo.
     *
     * @return array{expired:int, restocked_units:int, skipped:int, errored:int}
     */
    public function expireDueOrders(?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $pdo = localPDO::getInstance();

        $ordersModel = new orders_model();
        $ordersModel->set_field([" idx "]);
        $ordersModel->set_filter(
            [" active = 'yes' ", " status = 'aguardando_pagamento' ", " expires_at < ? "],
            [$now]
        );
        $ordersModel->set_order([" idx ASC "]);
        $ordersModel->set_paginate([0, self::BATCH_SIZE]);
        $ordersModel->load_data(false);

        // 'skipped' e 'errored' sao contadores separados de proposito: skip
        // e o resultado SAUDAVEL da guarda de corrida (pedido ja resolvido
        // por outro caminho), errored e uma falha de verdade (SQL, deadlock,
        // bug). Conflar os dois faria uma falha recorrente parecer operacao
        // normal no resumo impresso pelo cron — achado da revisao
        // adversarial do /ship.
        $summary = ['expired' => 0, 'restocked_units' => 0, 'skipped' => 0, 'errored' => 0];

        foreach ($ordersModel->data as $row) {
            $ordersId = (int)$row['idx'];

            try {
                $restockedUnits = $this->expireOne($ordersId, $now);

                $pdo->commit();
                $pdo->beginTransaction();

                if ($restockedUnits === null) {
                    // Guarda de corrida: o pedido ja tinha saido de
                    // 'aguardando_pagamento' (ex. webhook marcou 'pago')
                    // entre o SELECT acima e agora. Nada foi tocado.
                    $summary['skipped']++;
                } else {
                    $summary['expired']++;
                    $summary['restocked_units'] += $restockedUnits;
                }
            } catch (\Throwable $e) {
                $pdo->rollback();
                $pdo->beginTransaction();
                error_log("OrderExpirer: falha ao expirar pedido {$ordersId} — " . $e->getMessage());
                $summary['errored']++;
            }
        }

        return $summary;
    }

    /**
     * Expira 1 pedido dentro da transacao ja aberta na conexao singleton
     * (localPDO::getInstance()). Nao comita — quem comita e o chamador
     * (expireDueOrders(), para manter o commit por pedido no mesmo lugar
     * que decide o resultado do lote).
     *
     * Retorna as unidades devolvidas ao estoque se o pedido foi expirado
     * agora, ou null se a guarda de corrida encontrou o pedido ja resolvido
     * por outro caminho (nesse caso nada e escrito).
     */
    public function expireOne(int $ordersId, string $now): ?int
    {
        $orderUpdate = new orders_model();

        // Transicao atomica com guarda de corrida: so afeta a linha se ela
        // AINDA estiver aguardando pagamento.
        //
        // modified_at explicito (nao o now() automatico do update()): achado
        // do red-team (/ship) -- OrderReconciler::alertRecentlyExpiredPaidCharges()
        // compara orders.modified_at contra uma janela calculada em PHP. O fuso da
        // conexao MySQL ja e alinhado ao do PHP em localPDO (plans/005), mas este
        // carimbo explicito continua por ser mais direto/estavel do que depender do
        // now() automatico do update(). A ultima atribuicao a uma coluna repetida no
        // SET vence no MySQL (verificado empiricamente), entao este modified_at = ?
        // sobrescreve o now() que update() sempre injeta primeiro.
        $result = $orderUpdate->update(
            [" status = 'expirado' ", " modified_at = ? "],
            "WHERE idx = ? AND status = 'aguardando_pagamento'",
            [$now, $ordersId]
        );
        if ($result->rowCount() !== 1) {
            return null;
        }

        // Unidades a devolver por item: box => qty * box_qty ; unit => qty.
        $itemsModel = new order_items_model();
        $sumResult = $itemsModel->select(
            [" COALESCE(SUM(IF(oi.variant = 'box', oi.qty * p.box_qty, oi.qty)), 0) AS units "],
            "WHERE oi.orders_id = ? AND oi.active = 'yes'",
            [$ordersId],
            "oi",
            "JOIN products p ON p.idx = oi.products_id"
        );
        $restockedUnits = (int)($sumResult->fetch(\PDO::FETCH_ASSOC)['units'] ?? 0);

        // Pre-agrega por products_id ANTES de aplicar ao estoque: um UPDATE
        // multi-tabela do MySQL aplica o SET uma unica vez por linha alvo,
        // mesmo quando ela casa com VARIAS linhas do JOIN — nao soma entre
        // os matches. Um pedido com unidade solta + caixa do MESMO produto
        // (linhas distintas de carrinho, ver Cart.php) teria estoque
        // subestimado sem este agrupamento. Confirmado empiricamente contra
        // MySQL 8.0 na revisao adversarial do /ship.
        $productsModel = new products_model();
        $productsModel->update(
            [" p.stock = p.stock + agg.units "],
            "WHERE 1=1",
            null,
            "p",
            "JOIN (
                 SELECT oi.products_id,
                        SUM(IF(oi.variant = 'box', oi.qty * p2.box_qty, oi.qty)) AS units
                   FROM order_items oi
                   JOIN products p2 ON p2.idx = oi.products_id
                  WHERE oi.orders_id = ? AND oi.active = 'yes'
                  GROUP BY oi.products_id
                ) agg ON agg.products_id = p.idx",
            [$ordersId]
        );

        // modified_at explicito pelo mesmo motivo do update de orders acima
        // (achado do adversarial review, /ship: o codigo raw anterior tambem
        // carimbava este em PHP, nao so o de orders — continua assim mesmo com o
        // fuso da conexao alinhado em localPDO, plans/005).
        $chargesModel = new pix_charges_model();
        $chargesModel->update(
            [" status = 'expirado' ", " modified_at = ? "],
            "WHERE orders_id = ? AND status = 'pendente' AND active = 'yes'",
            [$now, $ordersId]
        );

        return $restockedUnits;
    }
}
