<?php
class track_order_controller
{
    public const PHONE_SUFFIX_LEN = 4;
    private const MAX_ORDERS_RETURNED = 50;

    public function index(array $info): void
    {
        $this->renderPage([], false);
    }

    public function search(array $info): void
    {
        validate_csrf($info['post']['_csrf_token'] ?? null, $GLOBALS['track_order_url']);

        $mail   = trim($info['post']['mail'] ?? '');
        $phone4 = sanitize_string($info['post']['phone4'] ?? '', true);

        $redis   = $GLOBALS['redis'] ?? null;
        $rateKey = "track_order:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (check_and_increment_rate_limit($redis, $rateKey, 5, 300)) {
            $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde alguns minutos."];
            basic_redir($GLOBALS['track_order_url']);
        }

        if ($mail === '' || filter_var($mail, FILTER_VALIDATE_EMAIL) === false || strlen((string)$phone4) !== self::PHONE_SUFFIX_LEN) {
            // Mesma mensagem generica para qualquer combinacao invalida
            // (campo vazio, formato de e-mail invalido, tamanho errado do
            // telefone) — nao criar um oraculo distinguindo os motivos.
            $_SESSION["messages_app"]["danger"] = ["Informe e-mail e os 4 últimos dígitos do telefone."];
            basic_redir($GLOBALS['track_order_url']);
        }

        $orders = $this->findOrders($mail, (string)$phone4);

        $this->renderPage($orders, true);
    }

    private function renderPage(array $orders, bool $searched): void
    {
        // validate_csrf() consome o token no POST; mintar aqui garante que o
        // formulário re-renderizado (após uma busca) leve um token válido para
        // a próxima busca — senão a 2ª busca falha com "Requisição inválida".
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/track_order.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    /**
     * Busca pedidos cujos customer_mail/customer_phone (RIGHT 4) batam com os
     * dois campos informados. So retorna linha se OS DOIS baterem — nunca vaza
     * por 1 campo. Nunca seleciona customer_cpf nem ship_*.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findOrders(string $mail, string $phone4): array
    {
        try {
            $model = new orders_model();
            $model->set_field([" idx ", " token ", " status ", " total_cents ", " created_at ",
                " paid_at ", " tracking_code ", " shipped_at "]);
            $model->set_filter([" active = 'yes' ", " customer_mail = ? ", " RIGHT(customer_phone, 4) = ? "],
                [$mail, $phone4]);
            $model->set_order([" created_at DESC "]);
            $model->set_paginate([self::MAX_ORDERS_RETURNED]);
            $model->load_data(false);

            return $model->data;
        } catch (RuntimeException $e) {
            error_log("Erro ao buscar pedidos em acompanhar-pedido: " . $e->getMessage());
            return [];
        }
    }
}
