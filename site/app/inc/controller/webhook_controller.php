<?php

/**
 * Recebe notificacoes de pagamento PIX dos 3 gateways. Sem sessao de comprador
 * e sem CSRF — o PSP nao tem como mandar token de sessao. A autenticidade vem
 * da assinatura verificada por cada adapter (verifyWebhook()).
 */
class webhook_controller
{
    /**
     * Processa o evento de webhook sem chamar json_response()/exit() — permite
     * testar o fluxo completo (idempotencia, assinatura, checagem de valor) via
     * PHPUnit. receive() (abaixo) e uma casca fina em cima deste metodo.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{code: int, body: array}
     */
    public function processEvent(string $slug, string $rawBody, array $headers, array $query = []): array
    {
        $gatewayClass = match ($slug) {
            'mercadopago' => MercadoPagoGateway::class,
            'pagbank'     => PagBankGateway::class,
            'infinitepay' => InfinitePayGateway::class,
            default       => null,
        };

        if ($gatewayClass === null) {
            return ['code' => 404, 'body' => ['error' => 'unknown gateway']];
        }

        $gateway = new $gatewayClass();

        if (!$gateway->verifyWebhook($rawBody, $headers, $query)) {
            return ['code' => 401, 'body' => ['error' => 'invalid signature']];
        }

        try {
            $chargeId = $gateway->extractChargeId($rawBody, $query);

            if ($chargeId === null) {
                // Nao conseguimos identificar a cobranca — pode ser um evento que
                // nao nos interessa (ex.: outro tipo de notificacao do mesmo PSP).
                return ['code' => 200, 'body' => ['ignored' => true]];
            }

            $gatewaysModel = new payment_gateways_model();
            $gatewaysModel->set_filter([" active = 'yes' ", " slug = ? "], [$slug]);
            $gatewaysModel->set_paginate([1]);
            $gatewaysModel->load_data(false);
            $gatewayRow = $gatewaysModel->data[0] ?? null;

            if (!$gatewayRow) {
                return ['code' => 200, 'body' => ['ignored' => true]];
            }

            $chargeModel = new pix_charges_model();
            $chargeModel->set_filter(
                [" active = 'yes' ", " payment_gateways_id = ? ", " gateway_charge_id = ? "],
                [(int)$gatewayRow['idx'], $chargeId]
            );
            $chargeModel->set_paginate([1]);
            $chargeModel->load_data(false);
            $charge = $chargeModel->data[0] ?? null;

            // Nao achou: 404 faria o PSP entrar em retry infinito por um evento
            // que nunca sera nosso — respondemos 200 mesmo assim.
            if (!$charge) {
                return ['code' => 200, 'body' => ['ignored' => true]];
            }

            // Idempotencia: reentrega e normal, nao e erro.
            if ($charge['status'] === 'pago') {
                return ['code' => 200, 'body' => ['ok' => true]];
            }

            // Nunca confia so no corpo do webhook — confirma no PSP quando o
            // gateway suporta. InfinitePay nao tem fetchStatus() por charge id
            // (fetchStatus() sempre devolve 'pendente' por design), mas TEM
            // reconfirmacao equivalente via confirmPayment()/payment_check logo
            // abaixo — ver plano 031.
            if ($slug !== 'infinitepay') {
                $pspStatus = $gateway->fetchStatus($chargeId);
                if ($pspStatus !== 'pago') {
                    return ['code' => 200, 'body' => ['ok' => true]];
                }
            }

            // InfinitePay nao tem fetchStatus por charge id, mas TEM POST /payment_check
            // (order_nsu + transaction_nsu + slug do corpo). Reconfirma aqui: o
            // transaction_nsu e um UUID gerado pelo PSP que so existe apos pagamento real,
            // entao um comprador forjando o webhook do proprio pedido nao passa. Ver plano 031.
            $infinitepayConfirmedAmountCents = null;
            $infinitepayTransactionNsu = null;
            if ($slug === 'infinitepay') {
                if (!$gateway instanceof InfinitePayGateway) {
                    // Nunca deve acontecer (slug 'infinitepay' -> InfinitePayGateway acima).
                    return ['code' => 500, 'body' => ['error' => 'internal error']];
                }

                // verifyWebhook() do InfinitePay sempre retorna true (sem assinatura),
                // entao diferente de MP/PagBank este ramo nao tem nenhum portao antes
                // de gastar uma chamada de rede de ate 10s (confirmPayment() abaixo).
                // Sem isto, o dono de um pedido pendente poderia martelar o proprio
                // token e segurar workers do PHP-FPM ate esgotar o pool. Chaveado pelo
                // token do pedido (chargeId), nao por IP — REMOTE_ADDR ainda nao e
                // confiavel neste ambiente (ver plano 031). Achado do /ship (red team).
                // gateway_charge_id usa collation utf8mb4_unicode_ci (case-insensitive)
                // — sem normalizar aqui, reenviar o mesmo order_nsu com letras hex em
                // caixa alta bateria na MESMA cobranca no banco mas ganharia um balde
                // de rate limit novo a cada variacao, driblando o limite. Achado da
                // revisao adversarial do /ship.
                $rateKey = "webhook_infinitepay:" . strtolower(trim($chargeId));
                if (check_and_increment_rate_limit($GLOBALS['redis'] ?? null, $rateKey, 10, 60)) {
                    Logger::getInstance()->warning('Webhook InfinitePay: rate limit excedido', [
                        'gateway_charge_id' => $chargeId,
                    ]);
                    return ['code' => 429, 'body' => ['error' => 'muitas tentativas']];
                }

                $confirmation = $gateway->confirmPayment($rawBody);
                if (!$confirmation['paid']) {
                    // retriable=true: nao foi possivel nem perguntar pro PSP (rede,
                    // HTTP, config) — pode ter sido um pagamento real. Responde
                    // nao-2xx para a InfinitePay reentregar o webhook depois, em vez
                    // de aceitar com 200 e perder o pagamento pra sempre (sem endpoint
                    // de reconciliacao para InfinitePay). Achado da revisao
                    // adversarial do /ship (red team + Codex, plano 031).
                    if ($confirmation['retriable']) {
                        return ['code' => 502, 'body' => ['error' => 'reconfirmacao indisponivel, tente novamente']];
                    }
                    return ['code' => 200, 'body' => ['ok' => true]];
                }
                $infinitepayConfirmedAmountCents = $confirmation['paid_amount_cents'];
                $infinitepayTransactionNsu = $confirmation['transaction_nsu'];

                // transaction_nsu e um UUID gerado pela InfinitePay so apos pagamento
                // real — mas nada impede o MESMO transaction_nsu de ser reenviado num
                // webhook forjado para confirmar um pedido DIFERENTE. Verifica aqui
                // (defesa rapida) e a UNIQUE key da migration 042 fecha a corrida de
                // verdade (TOCTOU entre esta checagem e o save() abaixo).
                if ($infinitepayTransactionNsu !== null) {
                    $existingByTransaction = new pix_charges_model();
                    $existingByTransaction->set_filter(
                        [" active = 'yes' ", " transaction_nsu = ? ", " idx != ? "],
                        [$infinitepayTransactionNsu, (int)$charge['idx']]
                    );
                    $existingByTransaction->set_paginate([1]);
                    $existingByTransaction->load_data(false);
                    if (($existingByTransaction->data[0] ?? null) !== null) {
                        Logger::getInstance()->warning('Webhook InfinitePay: transaction_nsu ja usado por outra cobranca (possivel replay)', [
                            'orders_id'       => $charge['orders_id'],
                            'charge_idx'      => $charge['idx'],
                            'transaction_nsu' => $infinitepayTransactionNsu,
                        ]);
                        return ['code' => 200, 'body' => ['ok' => true]];
                    }
                }
            }

            $orderModel = new orders_model();
            $orderModel->set_filter([" active = 'yes' ", " idx = ? "], [(int)$charge['orders_id']]);
            $orderModel->set_paginate([1]);
            $orderModel->load_data(false);
            $order = $orderModel->data[0] ?? null;

            if (!$order) {
                return ['code' => 200, 'body' => ['ignored' => true]];
            }

            // Confere o valor: valor pago >= orders.total_cents. Quando o gateway
            // nao expoe o valor pago no corpo do webhook (Mercado Pago), usamos o
            // valor que nos mesmos registramos ao criar a cobranca — o
            // fetchStatus() acima ja reconfirmou aquela cobranca especifica no
            // PSP, e uma cobranca PIX de valor fixo nao e paga a menor sem falhar.
            // Para InfinitePay usamos o valor autoritativo do payment_check (acima), nunca o
            // paid_amount do corpo do webhook (controlado por quem posta). Para MP/PagBank
            // $infinitepayConfirmedAmountCents e null, entao o comportamento nao muda.
            $paidAmountCents = $infinitepayConfirmedAmountCents ?? $gateway->extractPaidAmountCents($rawBody);
            if ($paidAmountCents === null) {
                $paidAmountCents = (int)$charge['amount_cents'];
            }

            if ($paidAmountCents < (int)$order['total_cents']) {
                Logger::getInstance()->warning('Webhook PIX: valor pago menor que o total do pedido', [
                    'orders_id'         => $order['idx'],
                    'gateway_charge_id' => $chargeId,
                    'paid_amount_cents' => $paidAmountCents,
                    'total_cents'       => $order['total_cents'],
                ]);
                return ['code' => 200, 'body' => ['ok' => true]];
            }

            $paidAt = date('Y-m-d H:i:s');

            $chargeUpdateData = [
                'status'  => 'pago',
                'paid_at' => $paidAt,
            ];
            // Grava o transaction_nsu reconfirmado (InfinitePay). A UNIQUE key da
            // migration 042 e a garantia real contra replay — se outro processo
            // ganhou a corrida entre a checagem acima e este save(), o INSERT/UPDATE
            // falha aqui com erro de constraint (RuntimeException, capturado pelo
            // catch de processEvent(), nada e commitado).
            if ($infinitepayTransactionNsu !== null) {
                $chargeUpdateData['transaction_nsu'] = $infinitepayTransactionNsu;
            }

            // PagBank: charges[0].id (CHAR_...) do webhook ja verificado por assinatura
            // + fetchStatus — metadado de reconciliacao/chargeback (o gateway_charge_id
            // do PagBank e o id do QR, QRCO_..., que nao e o que o PSP cita em disputa).
            // MP/InfinitePay retornam null aqui (ver PixGateway::extractTransactionNsu).
            // So grava se a cobranca ainda nao tem NSU — nunca sobrescreve.
            if ($infinitepayTransactionNsu === null && empty($charge['transaction_nsu'])) {
                $webhookNsu = $gateway->extractTransactionNsu($rawBody);
                if ($webhookNsu !== null) {
                    $chargeUpdateData['transaction_nsu'] = $webhookNsu;
                }
            }

            // Guarda de corrida com o job de expiracao (plano 032): so grava
            // 'pago' se o pedido/cobranca AINDA nao tiver sido expirado. Sem
            // isto, um webhook atrasado (retry do PSP apos o pedido ja ter
            // expirado e o estoque ja ter sido devolvido) reviveria o pedido
            // como 'pago' sem re-reservar o estoque — overselling. O UPDATE
            // condicional (WHERE ... status <> 'expirado') e o mesmo padrao
            // atomico que o OrderExpirer usa na direcao oposta.
            $chargeUpdate = new pix_charges_model();
            $chargeUpdate->set_filter(["idx = ?", "status <> 'expirado'"], [(int)$charge['idx']]);
            $chargeUpdate->populate($chargeUpdateData);
            $chargeResult = $chargeUpdate->save();

            $orderUpdate = new orders_model();
            $orderUpdate->set_filter(["idx = ?", "status <> 'expirado'"], [(int)$order['idx']]);
            $orderUpdate->populate([
                'status'  => 'pago',
                'paid_at' => $paidAt,
            ]);
            $orderResult = $orderUpdate->save();

            $chargeGuardOk = $chargeResult instanceof \PDOStatement && $chargeResult->rowCount() === 1;
            $orderGuardOk  = $orderResult instanceof \PDOStatement && $orderResult->rowCount() === 1;
            if (!$chargeGuardOk || !$orderGuardOk) {
                // Pedido/cobranca ja foi expirado (OrderExpirer) antes deste
                // webhook confirmar o pagamento — o estoque ja foi devolvido
                // e pode ja ter sido vendido para outro comprador. Nao
                // sobrescreve para 'pago' as cegas; sem commit, o destrutor
                // do localPDO reverte (nada foi alterado mesmo). Responde 200
                // (idempotente pro PSP) e loga para reconciliacao manual.
                Logger::getInstance()->error('Webhook PIX: pagamento confirmado para pedido ja expirado — estoque ja devolvido, requer reconciliacao manual', [
                    'orders_id'         => $order['idx'],
                    'gateway_charge_id' => $chargeId,
                    'paid_amount_cents' => $paidAmountCents,
                ]);
                return ['code' => 200, 'body' => ['ok' => true]];
            }

            // COMMIT EXPLICITO — ANTES do enfileiramento do e-mail, de proposito
            // (achado da revisao adversarial, plano 016). localPDO e um singleton
            // por processo: TODO model (inclusive email_queue_model, chamado logo
            // abaixo) compartilha a MESMA transacao. Se o commit ficasse depois
            // do bloco de e-mail e o INSERT em email_queue lancasse um erro real
            // (deadlock, timeout de lock), executePrepared() reverteria a
            // transacao INTEIRA — inclusive os 2 saves() de pagamento acima —
            // antes do catch fail-open engolir o erro; o webhook responderia 200
            // pro gateway com o pagamento silenciosamente revertido, e o
            // guard de idempotencia (`:73`) impediria qualquer nova tentativa
            // futura de persistir o mesmo pagamento. Commitando aqui, um erro no
            // e-mail (best-effort, nunca deve bloquear pagamento) so pode custar
            // o proprio e-mail — nunca a confirmacao ja durabilizada.
            //
            // A excecao ao "controllers nao commitam na mao": localPDO abre a
            // transacao no inicio do request e o __destruct() faz rollback se
            // ninguem commitou. Rotas normais commitam via basic_redir(); um
            // webhook responde JSON e sai por exit(), entao sem este commit a
            // confirmacao do pagamento seria silenciosamente descartada. Unica
            // rota do site autorizada a commitar na mao.
            $orderUpdate->commit();

            // Enfileira o e-mail de pagamento confirmado. Best-effort: roda DEPOIS
            // do commit acima, entao uma falha aqui (render do template ou o
            // proprio enqueue, que ja e fail-open por si so) nunca pode reverter
            // o pagamento ja durabilizado — so o e-mail em si pode se perder,
            // risco aceito na revisao adversarial.
            try {
                ob_start();
                $name       = $order['customer_name'];
                $orderToken = $order['token'];
                include(constant("cRootServer") . "ui/mail/order_paid.php");
                $mailBody = ob_get_clean();
                OrderMailQueue::enqueue(
                    (int)$order['idx'],
                    'order_paid',
                    $order['customer_mail'],
                    "Pagamento confirmado — " . constant('cStoreName'),
                    (string)$mailBody
                );
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                Logger::getInstance()->error('webhook_controller: falha ao renderizar/enfileirar e-mail de pagamento confirmado', [
                    'orders_id' => $order['idx'],
                    'error'     => $e->getMessage(),
                ]);
            }

            return ['code' => 200, 'body' => ['ok' => true]];
        } catch (\Throwable $e) {
            // Sem commit — o destrutor de localPDO reverte. O PSP reentrega, o
            // que e o comportamento certo aqui.
            Logger::getInstance()->error('webhook_controller::receive falhou', [
                'gateway' => $slug,
                'error'   => $e->getMessage(),
            ]);
            return ['code' => 500, 'body' => ['error' => 'internal error']];
        }
    }

    public function receive(array $info): never
    {
        $slug = $info[1] ?? '';

        // Obrigatorio: $_POST fica vazio com Content-Type: application/json, e o
        // PagBank exige o body cru byte-a-byte para o hash da assinatura.
        $rawBody = (string)file_get_contents('php://input');
        $headers = getallheaders();

        $result = $this->processEvent($slug, $rawBody, $headers, $_GET);

        json_response($result['body'], $result['code']);
    }
}
