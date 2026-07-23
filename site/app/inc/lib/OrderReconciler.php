<?php

/**
 * Plano 034: reconciliacao de cobrancas pendentes contra o PSP — fallback para
 * quando o webhook de pagamento falha ou atrasa (rede, PSP, janela de deploy).
 * Sem isto, um pedido PAGO pode ficar preso em `aguardando_pagamento` para
 * sempre, ja que o webhook e hoje o unico ator que transiciona
 * `aguardando_pagamento -> pago`.
 *
 * So cobre gateways com endpoint de consulta de status (mercadopago/pagbank).
 * InfinitePay fica de fora por design: `InfinitePayGateway::fetchStatus()`
 * sempre devolve 'pendente' (sem endpoint de consulta por charge id) — para
 * ele, o unico fallback e a expiracao por tempo (plano 032).
 *
 * Extraida para app/inc/lib/ (mesmo padrao de OrderExpirer, plano 032) para
 * ser testavel — site/cgi-bin/reconcile_charges.php e so a casca do cron.
 *
 * DECISAO DE ABORDAGEM (ver plano 034): o UPDATE condicional de "marcar pago"
 * abaixo (confirmOne()) ESPELHA a escrita de webhook_controller.php:194-296
 * (fonte da verdade) — mesmas colunas, mesmo e-mail. Duplicacao consciente,
 * registrada como follow-up de refatoracao ("Maintenance notes" do plano 034)
 * para nao editar o webhook (rota mais sensivel do site, fora de escopo aqui).
 */
class OrderReconciler
{
    // Fallback, nao caminho quente — nao ha necessidade de olhar pedidos mais
    // antigos que isso a cada tick; evita martelar o PSP com historico velho.
    private const WINDOW_HOURS = 24;

    // Lote pequeno; o proximo tick do cron pega o resto.
    private const BATCH_SIZE = 100;

    // Achado do red-team (/ship): OrderReconciler e OrderExpirer (plano 032)
    // rodam no mesmo cron de 5min com locks diferentes (nao sao mutuamente
    // exclusivos). Se o OrderExpirer vencer a corrida, o pedido sai de
    // aguardando_pagamento e o SELECT principal o exclui PARA SEMPRE — um
    // "pago" que o PSP confirme depois disso nunca mais seria revisto.
    // alertRecentlyExpiredPaidCharges() cobre essa janela: so ALERTA (loga +
    // marca 'erro' pra nao repetir o alerta a cada tick), nunca reverte o
    // pedido pra 'pago' sozinho — re-decrementar estoque e fora de escopo
    // deste job. Janela curta pra nao martelar o PSP com expiracoes antigas.
    //
    // LIMITACAO RESIDUAL CONHECIDA (achado do adversarial review, /ship): a
    // janela reduz a exposicao de "para sempre" pra 60min, mas nao a fecha —
    // uma confirmacao do PSP que chegue DEPOIS desses 60min (plausivel numa
    // degradacao longa do PSP, exatamente o cenario deste job) ainda cai no
    // mesmo ponto cego permanente. Risco aceito por ora (mesmo trade-off de
    // WINDOW_HOURS acima: alertar/consultar sem limite de tempo martelaria o
    // PSP com historico indefinidamente). Ver TODOS.md se quiser revisitar
    // (ex.: variar a janela ou considerar um alerta permanente).
    private const EXPIRED_ALERT_WINDOW_MINUTES = 60;

    /**
     * Slugs com endpoint de consulta de status no PSP. InfinitePay
     * EXCLUIDO de proposito — sem endpoint, fetchStatus() sempre 'pendente'.
     */
    private const ELIGIBLE_SLUGS = ['mercadopago', 'pagbank'];

    /**
     * Resolve o status de uma cobranca no PSP: (string $slug, string $gatewayChargeId): string.
     * Default: instancia o adapter real do slug e chama fetchStatus() (HTTP real).
     * Injetavel para teste (evita rede no PHPUnit) — mesmo espirito de
     * buildChargeBody()/lockAndValidateCart() serem publicos "para serem
     * testaveis sem rede".
     *
     * @var callable(string, string): string
     */
    private $statusResolver;

    public function __construct(?callable $statusResolver = null)
    {
        $this->statusResolver = $statusResolver ?? [$this, 'defaultStatusResolver'];
    }

    private function defaultStatusResolver(string $slug, string $gatewayChargeId): string
    {
        $gatewayClass = match ($slug) {
            'mercadopago' => MercadoPagoGateway::class,
            'pagbank'     => PagBankGateway::class,
            // Nunca deve acontecer — o SELECT em reconcilePending() ja filtra
            // por slug IN ('mercadopago','pagbank').
            default       => null,
        };

        if ($gatewayClass === null) {
            return 'pendente';
        }

        $gateway = new $gatewayClass();
        return $gateway->fetchStatus($gatewayChargeId);
    }

    /**
     * Varre um lote de cobrancas pendentes elegiveis (mercadopago/pagbank,
     * pedido ainda aguardando_pagamento, dentro da janela de 24h) e confirma
     * no PSP. $now vem do PHP (America/Sao_Paulo), nunca de NOW() do MySQL —
     * mesmo motivo do skew de fuso ja conhecido no repo (ver OrderExpirer).
     *
     * @return array{checked:int, confirmed:int, skipped:int, errored:int, alerted:int}
     */
    public function reconcilePending(?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $windowStart = date('Y-m-d H:i:s', strtotime($now) - self::WINDOW_HOURS * 3600);
        $pdo = localPDO::getInstance();

        $model = new pix_charges_model();
        $inPlaceholders = implode(',', array_fill(0, count(self::ELIGIBLE_SLUGS), '?'));
        $stmt = $model->select(
            [" pc.idx AS charge_idx ", " pc.gateway_charge_id ", " pc.orders_id ", " pg.slug "],
            "WHERE pc.active = 'yes' AND pc.status = 'pendente'
                AND o.active = 'yes' AND o.status = 'aguardando_pagamento'
                AND pg.slug IN ($inPlaceholders)
                AND o.created_at >= ?
              ORDER BY pc.idx ASC
              LIMIT " . self::BATCH_SIZE,
            [...self::ELIGIBLE_SLUGS, $windowStart],
            "pc",
            "JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id JOIN orders o ON o.idx = pc.orders_id"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $summary = ['checked' => 0, 'confirmed' => 0, 'skipped' => 0, 'errored' => 0, 'alerted' => 0];

        foreach ($rows as $row) {
            $summary['checked']++;

            try {
                $status = ($this->statusResolver)($row['slug'], $row['gateway_charge_id']);

                if ($status === 'erro') {
                    // fetchStatus() ja devolve 'erro' (e ja loga warning) quando
                    // a chamada ao PSP falha (timeout, HTTP nao-2xx) — achado do
                    // adversarial review (/ship): antes isso caia no mesmo
                    // 'skipped' da corrida benigna com o webhook, escondendo uma
                    // falha sistemica do PSP atras do contador errado bem no
                    // cenario em que este job existe pra ajudar.
                    $summary['errored']++;
                    continue;
                }

                if ($status !== 'pago') {
                    // 'expirado' fica a cargo do plano 032 (job de expiracao) —
                    // misturar responsabilidades aqui aumenta o risco.
                    $summary['skipped']++;
                    continue;
                }

                $confirmed = $this->confirmOne((int)$row['orders_id'], (int)$row['charge_idx'], $now);
                if ($confirmed) {
                    $summary['confirmed']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $e) {
                // Falha isolada (ex. erro de banco na escrita de 1 cobranca) nao
                // pode derrubar o restante do lote — mesmo espirito do catch por
                // pedido em OrderExpirer::expireDueOrders(). Achado do
                // maintainability specialist (/ship): antes contava como
                // 'skipped' (mesmo contador da corrida benigna com o webhook),
                // escondendo falha real recorrente — 'errored' e distinto,
                // mesmo padrao ja usado em OrderExpirer::expireDueOrders().
                $pdo->rollback();
                $pdo->beginTransaction();
                error_log("OrderReconciler: falha ao reconciliar cobranca {$row['charge_idx']} — " . $e->getMessage());
                $summary['errored']++;
            }
        }

        $summary['alerted'] = $this->alertRecentlyExpiredPaidCharges($now);

        return $summary;
    }

    /**
     * Segunda passada (achado do red-team, /ship): cobrancas cujo pedido
     * expirou ha pouco (ultimos EXPIRED_ALERT_WINDOW_MINUTES) mas o PSP ainda
     * diz 'pago'. So ALERTA (log ERROR + marca a cobranca 'erro') — nunca
     * reverte orders.status pra 'pago' sozinho, porque isso exigiria
     * re-decrementar o estoque ja devolvido pelo OrderExpirer, fora do escopo
     * deste job. Marcar 'erro' e o mecanismo de dedup: sem isso, a mesma
     * cobranca alertaria de novo a cada tick dentro da janela.
     */
    private function alertRecentlyExpiredPaidCharges(string $now): int
    {
        $pdo = localPDO::getInstance();
        $windowStart = date('Y-m-d H:i:s', strtotime($now) - self::EXPIRED_ALERT_WINDOW_MINUTES * 60);

        $model = new pix_charges_model();
        $inPlaceholders = implode(',', array_fill(0, count(self::ELIGIBLE_SLUGS), '?'));
        $stmt = $model->select(
            [" pc.idx AS charge_idx ", " pc.gateway_charge_id ", " pc.orders_id ", " pg.slug "],
            "WHERE pc.active = 'yes' AND pc.status = 'expirado'
                AND o.active = 'yes' AND o.status = 'expirado'
                AND pg.slug IN ($inPlaceholders)
                AND o.modified_at >= ?
              ORDER BY pc.idx ASC
              LIMIT " . self::BATCH_SIZE,
            [...self::ELIGIBLE_SLUGS, $windowStart],
            "pc",
            "JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id JOIN orders o ON o.idx = pc.orders_id"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $alerted = 0;
        foreach ($rows as $row) {
            try {
                $status = ($this->statusResolver)($row['slug'], $row['gateway_charge_id']);
                if ($status !== 'pago') {
                    continue;
                }

                Logger::getInstance()->error('OrderReconciler: PSP confirmou pago para pedido ja expirado — estoque ja devolvido, requer reconciliacao manual', [
                    'orders_id'  => (int)$row['orders_id'],
                    'charge_idx' => (int)$row['charge_idx'],
                ]);

                // modified_at explicito: achado do adversarial review (/ship) --
                // o codigo raw anterior tambem carimbava este em PHP, mesmo
                // motivo do skew de fuso do update de orders em OrderExpirer.
                $alertModel = new pix_charges_model();
                $alertResult = $alertModel->update(
                    [" status = 'erro' ", " modified_at = ? "],
                    "WHERE idx = ? AND status = 'expirado'",
                    [$now, (int)$row['charge_idx']]
                );
                $pdo->commit();
                $pdo->beginTransaction();

                // Achado do adversarial review (/ship): so conta como alertada
                // se o dedup realmente gravou. O log ERROR acima ja saiu de
                // qualquer forma (redundante e inofensivo se rowCount()===0 —
                // ex. outro processo ja marcou 'erro' nesse meio-tempo); o que
                // nao pode e o resumo alegar sucesso sem o dedup ter gravado.
                if ($alertResult->rowCount() === 1) {
                    $alerted++;
                }
            } catch (\Throwable $e) {
                $pdo->rollback();
                $pdo->beginTransaction();
                error_log("OrderReconciler: falha ao alertar cobranca expirada {$row['charge_idx']} — " . $e->getMessage());
            }
        }

        return $alerted;
    }

    /**
     * Confirma 1 cobranca como paga, re-verificando sob transacao que o
     * pedido ainda esta aguardando_pagamento (o webhook pode ter chegado
     * nesse meio-tempo). Retorna true se este processo confirmou o
     * pagamento agora, false se a guarda de corrida encontrou o pedido ja
     * resolvido por outro caminho (nada e escrito nesse caso).
     *
     * ESPELHA webhook_controller.php:194-296 (fonte da verdade) — mesmas
     * colunas (status, paid_at), mesmo guard condicional de status, mesmo
     * e-mail 'order_paid' enfileirado DEPOIS do commit. NAO espelha a
     * confirmacao de valor do webhook (paidAmountCents >= total_cents,
     * webhook_controller.php:171-192): uma cobranca PIX e criada no PSP para
     * um valor FIXO — fetchStatus()=='pago' ja e a confirmacao de que aquele
     * valor especifico foi recebido (nao ha "pago a menor" num PIX de valor
     * fixo). Achado do adversarial review (/ship): comentario ajustado para
     * nao alegar paridade que nao existe neste ponto especifico.
     *
     * Publico (nao-final) para ser sobrescrito por subclasse anonima em teste,
     * forcando uma falha isolada num lote sem hooks de concorrencia reais —
     * mesmo padrao de OrderExpirer::expireOne().
     */
    public function confirmOne(int $ordersId, int $chargeIdx, string $now): bool
    {
        $pdo = localPDO::getInstance();
        $model = new orders_model();

        // modified_at explicito: achado do adversarial review (/ship) -- o
        // codigo raw anterior tambem carimbava este em PHP (mesmo motivo do
        // skew de fuso do update de orders em OrderExpirer).
        $orderResult = $model->update(
            [" status = 'pago' ", " paid_at = ? ", " modified_at = ? "],
            "WHERE idx = ? AND status = 'aguardando_pagamento'",
            [$now, $now, $ordersId]
        );
        if ($orderResult->rowCount() !== 1) {
            // Corrida com o webhook (ja confirmado por outro caminho) ou o
            // pedido saiu de aguardando_pagamento por outro motivo (ex.
            // expirado) — nao sobrescreve as cegas.
            $this->logIfAlreadyExpired($ordersId, $chargeIdx);
            return false;
        }

        $chargesModel = new pix_charges_model();
        $chargesModel->update(
            [" status = 'pago' ", " paid_at = ? ", " modified_at = ? "],
            "WHERE idx = ? AND status = 'pendente'",
            [$now, $now, $chargeIdx]
        );

        // Commit por cobranca confirmada (nao um so no fim do lote) — mesma
        // razao do dispatch_emails.php/OrderExpirer: limita o blast radius de
        // uma falha isolada a no maximo 1 cobranca.
        $pdo->commit();
        $pdo->beginTransaction();

        // Best-effort, DEPOIS do commit — uma falha aqui (render do template
        // ou o proprio enqueue) nunca pode reverter o pagamento ja
        // durabilizado, so o e-mail em si pode se perder.
        $this->enqueuePaidEmail($ordersId);

        return true;
    }

    /**
     * Achado do red-team (/ship): o webhook loga ERROR + "requer reconciliacao
     * manual" quando encontra o mesmo cenario (webhook_controller.php:231-244)
     * porque, se o pedido ja foi expirado pelo OrderExpirer (plano 032), o
     * estoque ja foi devolvido e pode ja ter sido revendido — silenciar essa
     * corrida escondia exatamente o caso que precisa de atencao operacional.
     * Corrida benigna (webhook ja confirmou 'pago' primeiro) nao loga.
     */
    private function logIfAlreadyExpired(int $ordersId, int $chargeIdx): void
    {
        $orderModel = new orders_model();
        $orderModel->set_filter([" idx = ? "], [$ordersId]);
        $orderModel->set_paginate([1]);
        $orderModel->load_data(false);
        $order = $orderModel->data[0] ?? null;

        if (($order['status'] ?? null) === 'expirado') {
            Logger::getInstance()->error('OrderReconciler: PSP confirmou pago para pedido ja expirado — estoque ja devolvido, requer reconciliacao manual', [
                'orders_id'  => $ordersId,
                'charge_idx' => $chargeIdx,
            ]);
        }
    }

    private function enqueuePaidEmail(int $ordersId): void
    {
        try {
            $orderModel = new orders_model();
            $orderModel->set_filter([" idx = ? "], [$ordersId]);
            $orderModel->set_paginate([1]);
            $orderModel->load_data(false);
            $order = $orderModel->data[0] ?? null;

            if ($order === null) {
                return;
            }

            ob_start();
            $name       = $order['customer_name'];
            $orderToken = $order['token'];
            include(constant("cRootServer") . "ui/mail/order_paid.php");
            $mailBody = ob_get_clean();
            OrderMailQueue::enqueue(
                $ordersId,
                'order_paid',
                $order['customer_mail'],
                "Pagamento confirmado — " . constant('cStoreName'),
                (string)$mailBody
            );
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            Logger::getInstance()->error('OrderReconciler: falha ao renderizar/enfileirar e-mail de pagamento confirmado', [
                'orders_id' => $ordersId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
