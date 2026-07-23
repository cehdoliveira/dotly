<?php

/**
 * Checkout transacional. `finalize()` e a UNICA rota que grava o pedido — toda
 * ela roda dentro da transacao global aberta por localPDO e e commitada pelo
 * basic_redir() final. Ver plano 002 Passo 9 para a justificativa de manter a
 * chamada HTTP ao PSP dentro da transacao (tradeoff consciente).
 */
class checkout_controller
{
    public function index(array $info): void
    {
        global $cart_url;

        [$lines, $totalCents] = Cart::hydrate();
        $wantsJson = ($info['get']['format'] ?? '') === 'json';

        if (empty($lines)) {
            if ($wantsJson) {
                json_response(['error' => 'carrinho vazio'], 400);
            }
            $_SESSION["messages_app"]["danger"] = ["Seu carrinho está vazio."];
            basic_redir($cart_url);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        // Preview do breakdown de taxas antes do pedido existir (nao ha ainda
        // o que ler do banco). O calculo em si vive em OrderPricing — a view
        // so exibe o resultado, nao recalcula nada.
        $pricing = OrderPricing::compute($lines, $totalCents);

        // Payload do painel de checkout. Só leitura — quem grava é finalize(),
        // que continua sendo POST nativo com redirect pro pagamento (plano 006, 1.2).
        // total_cents aqui ja e o fee-inclusive (bate com o que finalize() vai gravar).
        if ($wantsJson) {
            json_response([
                'lines'       => $lines,
                'pricing'     => $pricing,
                'total_cents' => $pricing['total_cents'],
                'csrf_token'  => $_SESSION['_csrf_token'],
            ]);
        }

        // 'shop' ja carrega incondicionalmente (foot.php) pro header/drawer;
        // 'checkout' traz mascaras + auto-CEP desta tela.
        $alpineControllers = ['checkout'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/checkout.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function finalize(array $info): never
    {
        global $checkout_url, $cart_url, $payment_url;

        $post = $info["post"] ?? [];
        $submittedToken = $post['_csrf_token'] ?? null;

        validate_csrf($submittedToken, $checkout_url);

        // Rate limit por IP: finalize() decrementa estoque e cria cobranca real no PSP —
        // sem throttle, um flood esvazia o estoque e polui o gateway. Mesmo mecanismo de
        // checkout_controller::cep() e track_order_controller::search(). Limite conservador
        // pra nao atrapalhar retry legitimo apos erro de PIX.
        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "checkout_finalize:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 8, 60)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas de finalizar o pedido. Aguarde um instante e tente de novo."];
            basic_redir($checkout_url);
        }

        // Guarda contra duplo-submit dentro da janela de graca do CSRF (10s,
        // pensada pra sobreviver a F5-apos-submit em QUALQUER rota POST — ver
        // validate_csrf()). finalize() e a UNICA rota onde reenviar o MESMO
        // token dentro da janela e perigoso: ela nao e idempotente, e gera
        // pedido + cobranca PIX real a cada execucao bem-sucedida. Duplo clique,
        // Enter repetido ou "reenviar dados do formulario" do navegador podem
        // chegar aqui com o token ainda valido (grace period) depois que a 1a
        // requisicao ja concluiu — sem esta guarda, isso cria DOIS pedidos e
        // DUAS cobrancas reais (os gateways nao dedupam entre si: cada pedido
        // ganha um token proprio, novo). PHP serializa requisicoes concorrentes
        // da MESMA sessao (flock do session handler padrao), entao a 2a
        // requisicao so chega aqui depois que a 1a ja terminou e gravou o
        // token em _finalized_tokens — nao depende de Redis nem de nenhuma
        // infra externa.
        if (!empty($submittedToken) && isset($_SESSION['_finalized_tokens'][$submittedToken])) {
            basic_redir(sprintf($payment_url, $_SESSION['_finalized_tokens'][$submittedToken]));
        }

        [$lines, ] = Cart::hydrate();

        if (empty($lines)) {
            $_SESSION["messages_app"]["danger"] = ["Seu carrinho está vazio."];
            basic_redir($cart_url);
        }

        $customer = $this->validateCustomer($post);
        if ($customer === null) {
            basic_redir($checkout_url);
        }

        $result = $this->lockAndValidateCart($lines);

        if (!$result['ok']) {
            $_SESSION["messages_app"]["danger"] = [$result['message']];
            basic_redir($cart_url);
        }

        $finalLines = $result['lines'];
        $subtotalCents = $result['total_cents'];

        // Taxas obrigatorias: 10% + R$60 fixo + taxa Infinity (parametrizavel).
        // Ver OrderPricing — unico ponto que calcula taxa sobre o subtotal
        // reconferido; nunca embutir taxa em lockAndValidateCart().
        $pricing = OrderPricing::compute($finalLines, $subtotalCents);
        $totalCents = $pricing['total_cents'];

        // Baixa o estoque por linha.
        $productsModel = new products_model();
        foreach ($finalLines as $line) {
            $productsModel->update(
                [" stock = stock - ? "],
                "WHERE idx = ?",
                [$line['units_needed'], $line['products_id']]
            );
        }

        $token = random_token(16);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

        $order = new orders_model();
        $order->populate([
            'token'           => $token,
            'status'          => 'aguardando_pagamento',
            'customer_name'   => $customer['name'],
            'customer_mail'   => $customer['mail'],
            'customer_phone'  => $customer['phone'],
            'customer_cpf'    => $customer['cpf'],
            'ship_zip'        => $customer['zip'],
            'ship_street'     => $customer['street'],
            'ship_number'     => $customer['number'],
            'ship_complement' => $customer['complement'],
            'ship_district'   => $customer['district'],
            'ship_city'       => $customer['city'],
            'ship_uf'         => $customer['uf'],
            'subtotal_cents'  => $pricing['subtotal_cents'],
            'fee_percent_cents' => $pricing['fee_percent_cents'],
            'fee_fixed_cents' => $pricing['fee_fixed_cents'],
            'fee_infinity_cents' => $pricing['fee_infinity_cents'],
            'total_cents'     => $pricing['total_cents'],
            'expires_at'      => $expiresAt,
        ]);
        $orderId = $order->save();

        foreach ($finalLines as $line) {
            $item = new order_items_model();
            $item->populate([
                'orders_id'        => $orderId,
                'products_id'      => $line['products_id'],
                'product_name'     => $line['name'],
                'variant'          => $line['variant'],
                'qty'              => $line['qty'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_total_cents' => $line['line_total_cents'],
            ]);
            $item->save();
        }

        // Nao expomos a lista de produtos ao PSP: mandamos um unico item generico
        // e neutro ("{loja} - Pedido #{idx}") com o valor total ja com taxas — nome
        // de produto real no payload arrisca bloqueio de compliance no gateway.
        // Como o total vira o proprio valor do item, a soma dos itens continua
        // batendo com orders.total_cents para gateways que cobram pela soma
        // (ex.: InfinitePay), sem precisar de uma linha separada de taxas.
        // MercadoPago/PagBank cobram por total_cents e usam o item apenas como
        // descricao. O detalhamento real fica em order_items (acima).
        $gatewayItems = [[
            'product_name'     => constant("cStoreName") . ' - Pedido #' . $orderId,
            'variant'          => null,
            'qty'              => 1,
            'unit_price_cents' => $totalCents,
        ]];

        $orderRow = [
            'idx'            => $orderId,
            'token'          => $token,
            'customer_name'  => $customer['name'],
            'customer_mail'  => $customer['mail'],
            'customer_phone' => $customer['phone'],
            'customer_cpf'   => $customer['cpf'],
            'total_cents'    => $totalCents,
            'expires_at'     => $expiresAt,
        ];

        try {
            $picked = GatewayRouter::pick($totalCents);
            $gatewayClass = match ($picked['slug']) {
                'mercadopago' => MercadoPagoGateway::class,
                'pagbank'     => PagBankGateway::class,
                'infinitepay' => InfinitePayGateway::class,
                default       => throw new RuntimeException("Gateway desconhecido: {$picked['slug']}"),
            };
            $gateway = new $gatewayClass();
            $charge = $gateway->createCharge($orderRow, $gatewayItems);

            $pixCharge = new pix_charges_model();
            $pixCharge->populate([
                'orders_id'           => $orderId,
                'payment_gateways_id' => $picked['idx'],
                'gateway_charge_id'   => $charge['gateway_charge_id'],
                'status'              => 'pendente',
                'qr_payload'          => $charge['qr_payload'],
                'qr_image_base64'     => $charge['qr_image_base64'],
                'redirect_url'        => $charge['redirect_url'],
                'amount_cents'        => $totalCents,
                'expires_at'          => $charge['expires_at'],
            ]);
            $pixCharge->save();
        } catch (\Throwable $e) {
            Logger::getInstance()->error("checkout_controller::finalize createCharge falhou", [
                "error"     => $e->getMessage(),
                "orders_id" => $orderId,
            ]);
            $_SESSION["messages_app"]["danger"] = ["Não conseguimos gerar seu PIX agora. Tente de novo em instantes."];
            basic_redir($checkout_url, rollback: true);
        }

        Cart::clear();

        // Marca este token como ja finalizado, associado ao pedido criado —
        // fecha a guarda de duplo-submit aberta no topo do metodo. So gravado
        // apos sucesso completo (gateway ja respondeu); falhas anteriores
        // (carrinho vazio, estoque, gateway indisponivel + rollback) nunca
        // chegam aqui, entao o cliente ainda pode tentar de novo com o mesmo
        // token normalmente nesses casos.
        if (!empty($submittedToken)) {
            $_SESSION['_finalized_tokens'][$submittedToken] = $token;
        }

        basic_redir(sprintf($payment_url, $token));
    }

    public function payment(array $info): void
    {
        global $home_url, $done_url;

        $order = $this->loadOrderByToken($info[1] ?? null, $home_url);

        // Pedido ja resolvido (pago/expirado/cancelado) — nao mostra a tela de
        // pagamento de novo pra quem voltou por link salvo/favorito; a tela de
        // confirmacao ja cobre os 4 status.
        if ($order['status'] !== 'aguardando_pagamento') {
            basic_redir(sprintf($done_url, $order['token']));
        }

        $charge = $this->findLatestActiveCharge((int)$order['idx']);

        if ($charge === null) {
            $_SESSION["messages_app"]["danger"] = ["Pedido não encontrado."];
            basic_redir($home_url);
        }

        $noindex = true;
        $alpineControllers = ['payment'];

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/payment.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function done(array $info): void
    {
        global $home_url;

        $order = $this->loadOrderByToken($info[1] ?? null, $home_url);

        $itemsModel = new order_items_model();
        $itemsModel->set_filter([" active = 'yes' ", " orders_id = ? "], [$order['idx']]);
        $itemsModel->set_order([" idx asc "]);
        $itemsModel->load_data(false);
        $orderItems = $itemsModel->data;

        $noindex = true;

        // Cliente que volta do gateway antes do webhook confirmar cai aqui com
        // status 'aguardando_pagamento': carrega o polling que recarrega a tela
        // sozinho quando o pagamento confirmar (doneController.js). Nos demais
        // status a pagina ja e final, nao precisa pollar.
        if ($order['status'] === 'aguardando_pagamento') {
            $alpineControllers = ['done'];
        }

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/done.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    /**
     * Somente leitura do nosso banco — nunca chama o PSP aqui. Quem fala com o
     * PSP em tempo real e o webhook; o fallback e o job de reconciliacao.
     */
    public function status(array $info): never
    {
        $token = $info[1] ?? null;

        $model = new orders_model();
        $model->set_field([" status "]);
        $model->set_filter([" active = 'yes' ", " token = ? "], [$token]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $order = $model->data[0] ?? null;

        if (!$order) {
            json_response(['error' => 'not found'], 404);
        }

        json_response(['status' => $order['status']]);
    }

    /**
     * Proxy de consulta de CEP (ViaCEP). Feito no servidor de proposito: o CSP
     * (connect-src 'self') nao libera o browser a chamar viacep.com.br direto, e
     * assim o IP do cliente nao vaza pro terceiro. So leitura, sem tocar o banco;
     * o front usa a resposta pra preencher rua/bairro/cidade/UF e deixar o cliente
     * digitar so numero e complemento. Fail-soft: qualquer erro devolve JSON de
     * erro e o front cai pro preenchimento manual (os campos continuam editaveis).
     * Rate-limit por IP (mesmo padrao de track_order_controller::search()): sem
     * isso, o endpoint e um proxy publico e sem custo pro cliente pra martelar o
     * ViaCEP atraves do nosso servidor.
     */
    public function cep(array $info): never
    {
        $redis = $GLOBALS['redis'] ?? null;
        $rateKey = "checkout_cep:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 30, 60)) {
            json_response(['error' => 'Muitas consultas de CEP. Aguarde um instante.'], 429);
        }

        $cep = preg_replace('/\D/', '', (string)($info[1] ?? ''));
        if (strlen($cep) !== 8) {
            json_response(['error' => 'CEP inválido.'], 400);
        }

        $ch = curl_init("https://viacep.com.br/ws/{$cep}/json/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $httpCode !== 200) {
            json_response(['error' => 'Consulta de CEP indisponível.'], 502);
        }

        $data = json_decode($raw, true);
        // ViaCEP devolve {"erro": true} pra CEP inexistente (com HTTP 200).
        if (!is_array($data) || !empty($data['erro'])) {
            json_response(['error' => 'CEP não encontrado.'], 404);
        }

        json_response([
            'street'   => (string)($data['logradouro'] ?? ''),
            'district' => (string)($data['bairro'] ?? ''),
            'city'     => (string)($data['localidade'] ?? ''),
            'uf'       => strtoupper((string)($data['uf'] ?? '')),
        ]);
    }

    /**
     * Trava as linhas do carrinho (SELECT ... FOR UPDATE), confere estoque e
     * reconfere preco contra o banco — nunca contra o que veio na sessao/POST.
     * Nao redireciona nem toca $_SESSION: devolve um resultado tipado para que
     * finalize() decida a UX, e para que este metodo seja testavel sem passar
     * pelo exit() de basic_redir().
     *
     * @param array<int, array{products_id:int, variant:string, qty:int, name:string}> $lines
     * @return array{ok: true, lines: array<int, array{products_id:int, variant:string,
     *   qty:int, units_needed:int, name:string, unit_price_cents:int,
     *   line_total_cents:int}>, total_cents: int}|array{ok: false, message: string}
     */
    public function lockAndValidateCart(array $lines): array
    {
        // Sem FOR UPDATE dois compradores simultaneos vendem o mesmo ultimo frasco.
        $productIds = array_values(array_unique(array_map(
            static fn(array $line) => (int)$line['products_id'],
            $lines
        )));
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $productsModel = new products_model();
        $stmt = $productsModel->select(
            [" idx ", " stock ", " price_unit_cents ", " box_qty "],
            "WHERE active = 'yes' AND idx IN ($placeholders) FOR UPDATE",
            $productIds
        );

        $lockedProducts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $lockedProducts[(int)$row['idx']] = $row;
        }

        $finalLines = [];
        $totalCents = 0;

        foreach ($lines as $line) {
            $productId = (int)$line['products_id'];
            $variant   = (string)$line['variant'];
            $qty       = (int)$line['qty'];

            $product = $lockedProducts[$productId] ?? null;
            if ($product === null) {
                return ['ok' => false, 'message' => 'Um dos produtos do seu carrinho não está mais disponível.'];
            }

            $boxQty = (int)$product['box_qty'];
            $unitsNeeded = $variant === 'box' ? $qty * $boxQty : $qty;

            if ((int)$product['stock'] < $unitsNeeded) {
                return [
                    'ok'      => false,
                    'message' => sprintf('%s: só restam %d unidades.', $line['name'], (int)$product['stock']),
                ];
            }

            // Reconfere o preco contra o banco — nunca confia em unit_price_cents
            // vindo do carrinho/sessao (defesa contra adulteracao de preco).
            // Caixa sempre vale box_qty unidades ao preco unitario.
            $unitPriceCents = $variant === 'box'
                ? (int)$product['price_unit_cents'] * $boxQty
                : (int)$product['price_unit_cents'];
            $lineTotalCents = $unitPriceCents * $qty;
            $totalCents += $lineTotalCents;

            $finalLines[] = [
                'products_id'      => $productId,
                'variant'          => $variant,
                'qty'              => $qty,
                'units_needed'     => $unitsNeeded,
                'name'             => $line['name'],
                'unit_price_cents' => $unitPriceCents,
                'line_total_cents' => $lineTotalCents,
            ];
        }

        return ['ok' => true, 'lines' => $finalLines, 'total_cents' => $totalCents];
    }

    /**
     * Cobranca PIX mais recente do pedido (a mais recente por ser a ativa —
     * um pedido so ganha uma segunda cobranca se a rota de retry recriar uma).
     * Extraido de payment() para ser testavel sem passar pelos includes de
     * view (mesmo padrao de lockAndValidateCart()).
     */
    public function findLatestActiveCharge(int $ordersId): ?array
    {
        $chargeModel = new pix_charges_model();
        $chargeModel->set_filter([" active = 'yes' ", " orders_id = ? "], [$ordersId]);
        $chargeModel->set_order([" idx desc "]);
        $chargeModel->set_paginate([1]);
        $chargeModel->load_data(false);

        return $chargeModel->data[0] ?? null;
    }

    /**
     * @return array{name:string, mail:string, phone:string, cpf:string, zip:string,
     *   street:string, number:string, complement:string, district:string, city:string,
     *   uf:string}|null
     */
    private function validateCustomer(array $post): ?array
    {
        $name   = trim((string)($post['name'] ?? ''));
        $mail   = trim((string)($post['mail'] ?? ''));
        $phone  = preg_replace('/\D/', '', (string)($post['phone'] ?? ''));
        $cpf    = preg_replace('/\D/', '', (string)($post['cpf'] ?? ''));
        $zip    = preg_replace('/\D/', '', (string)($post['zip'] ?? ''));
        $street = trim((string)($post['street'] ?? ''));
        $number = trim((string)($post['number'] ?? ''));
        $complement = trim((string)($post['complement'] ?? ''));
        $district   = trim((string)($post['district'] ?? ''));
        $city   = trim((string)($post['city'] ?? ''));
        $uf     = strtoupper(trim((string)($post['uf'] ?? '')));

        if ($name === '' || $street === '' || $number === '' || $district === '' || $city === '') {
            $_SESSION["messages_app"]["danger"] = ["Preencha todos os campos obrigatórios."];
            return null;
        }

        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION["messages_app"]["danger"] = ["Informe um e-mail válido."];
            return null;
        }

        if (strlen($phone) < 10 || strlen($phone) > 11) {
            $_SESSION["messages_app"]["danger"] = ["Informe um telefone válido com DDD."];
            return null;
        }

        if (!validate_cpf($cpf)) {
            $_SESSION["messages_app"]["danger"] = ["Informe um CPF válido."];
            return null;
        }

        if (strlen($zip) !== 8) {
            $_SESSION["messages_app"]["danger"] = ["Informe um CEP válido."];
            return null;
        }

        if (!array_key_exists($uf, $GLOBALS['ufbr_lists'])) {
            $_SESSION["messages_app"]["danger"] = ["Informe um estado (UF) válido."];
            return null;
        }

        // Plano 023: cliente bloqueado no manager (/clientes) nao pode fechar pedido.
        // O bloqueio casa por e-mail, CPF OU telefone — qualquer um basta.
        if ($this->isBlocked($mail, $cpf, $phone)) {
            $_SESSION["messages_app"]["danger"] = ["Não foi possível concluir o pedido com estes dados. Entre em contato com o nosso suporte."];
            return null;
        }

        return [
            'name'       => $name,
            'mail'       => $mail,
            'phone'      => $phone,
            'cpf'        => $cpf,
            'zip'        => $zip,
            'street'     => $street,
            'number'     => $number,
            'complement' => $complement,
            'district'   => $district,
            'city'       => $city,
            'uf'         => $uf,
        ];
    }

    /**
     * Cliente esta bloqueado se e-mail, CPF ou telefone bater na blocklist
     * (blocked_customers, gravada pelo manager em /clientes). CPF/telefone vazios
     * ('') nunca casam entre si. Fail-open, de proposito: um erro de banco aqui
     * derruba a transacao da requisicao inteira (checkout ja quebrado) — mesma
     * postura fail-open de Redis/Kafka no projeto. O bloqueio nao e uma barreira
     * de seguranca, e uma politica comercial; sob falha de banco, deixa passar em
     * vez de travar todo o checkout.
     */
    private function isBlocked(string $mail, string $cpf, string $phone): bool
    {
        try {
            $model = new blocked_customers_model();
            $stmt  = $model->select(
                [" 1 "],
                "WHERE active = 'yes'
                    AND ( customer_mail = ?
                          OR ( customer_cpf <> '' AND customer_cpf = ? )
                          OR ( customer_phone <> '' AND customer_phone = ? ) )
                  LIMIT 1",
                [$mail, $cpf, $phone]
            );

            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            Logger::getInstance()->error("checkout isBlocked failed", ["error" => $e->getMessage()]);
            return false;
        }
    }

    private function loadOrderByToken(?string $token, string $fallbackUrl): array
    {
        if (empty($token)) {
            $_SESSION["messages_app"]["danger"] = ["Pedido não encontrado."];
            basic_redir($fallbackUrl);
        }

        $model = new orders_model();
        $model->set_filter([" active = 'yes' ", " token = ? "], [$token]);
        $model->set_paginate([1]);
        $model->load_data(false);

        $order = $model->data[0] ?? null;

        if (!$order) {
            $_SESSION["messages_app"]["danger"] = ["Pedido não encontrado."];
            basic_redir($fallbackUrl);
        }

        return $order;
    }

}
