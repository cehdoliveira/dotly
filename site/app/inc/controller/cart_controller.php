<?php
class cart_controller
{
    // Monta o payload JSON do carrinho a partir de um Cart::hydrate() ja feito
    // pelo chamador — nao hidrata de novo aqui, pra nao duplicar a query.
    // Inclui csrf_token: validate_csrf() consome (unset) o token da sessao a
    // cada POST valido (ver CommonFunctions.php); sem devolver um token novo
    // aqui, o segundo "Adicionar ao Pedido" via AJAX falha assim que sai da
    // janela de graca de 10s (achado no /ship — a UI ficava presa depois do
    // primeiro item). O chamador garante que $_SESSION['_csrf_token'] existe
    // antes de chamar isto.
    private function cartJsonPayload(array $lines, int $totalCents): array
    {
        // Mesmas taxas do checkout (10% + R$60 cambio + Infinity opcional), pra
        // o drawer "Seu pedido" discriminar Subtotal / Encargos / Total antes de
        // o cliente ir pro checkout. $totalCents aqui e so o subtotal das linhas;
        // OrderPricing devolve o total com encargos.
        $pricing = OrderPricing::compute($lines, $totalCents);

        return [
            'count'              => Cart::count(),
            'lines'              => $lines,
            'subtotal_cents'     => $pricing['subtotal_cents'],
            'encargos_cents'     => $pricing['total_cents'] - $pricing['subtotal_cents'],
            'total_cents'        => $pricing['total_cents'],
            'fee_percent_bps'    => $pricing['fee_percent_bps'],
            'fee_fixed_cents'    => $pricing['fee_fixed_cents'],
            'fee_infinity_cents' => $pricing['fee_infinity_cents'],
            'csrf_token'         => $_SESSION['_csrf_token'] ?? '',
        ];
    }

    public function index(array $info): void
    {
        [$lines, $totalCents] = Cart::hydrate();

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        // Leitura do carrinho pro drawer. Cart::hydrate() so le do banco —
        // nao precisa de commit (json_response nao commita, ver plano 006).
        if (($info['get']['format'] ?? '') === 'json') {
            json_response($this->cartJsonPayload($lines, $totalCents));
        }

        $alpineControllers = ['shop'];

        // Breakdown de encargos pra tela "Meu Pedido" (mesma conta do drawer/checkout).
        $pricing = OrderPricing::compute($lines, $totalCents);

        include(constant("cRootServer") . "ui/common/head.php");
        include(constant("cRootServer") . "ui/common/header.php");
        include(constant("cRootServer") . "ui/page/cart.php");
        include(constant("cRootServer") . "ui/common/footer.php");
        include(constant("cRootServer") . "ui/common/foot.php");
    }

    public function action(array $info): void
    {
        global $cart_url, $home_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';

        validate_csrf($post['_csrf_token'] ?? null, $cart_url);

        // validate_csrf() consome (unset) o token usado nesta requisicao —
        // regenera na hora pra qualquer resposta JSON abaixo (sucesso ou erro)
        // devolver um token que a proxima chamada AJAX ainda pode usar.
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        $productId = (int)($post['products_id'] ?? 0);
        $variant   = (string)($post['variant'] ?? '');
        $qty       = (int)($post['qty'] ?? 1);

        // Cart::* grava so em $_SESSION, nunca no banco — por isso o branch JSON
        // nao precisa de commit(). Ver plano 006, secao 1.1.
        $wantsJson = ($post['format'] ?? '') === 'json';

        switch ($action) {
            case 'adicionar':
                Cart::add($productId, $variant, $qty);
                break;

            case 'atualizar':
                Cart::setQty($productId, $variant, $qty);
                break;

            case 'remover':
                Cart::remove($productId, $variant);
                break;

            default:
                if ($wantsJson) {
                    json_response(['error' => 'acao invalida'], 400);
                }
                // Sem JS, cai no basic_redir($cart_url) do final do metodo
                // (acao invalida !== 'adicionar', mesmo destino).
                break;
        }

        if ($wantsJson) {
            [$lines, $totalCents] = Cart::hydrate();
            json_response($this->cartJsonPayload($lines, $totalCents));
        }

        // Sem JS: 'adicionar' volta pra home (nao teleporta o leigo pra outra
        // tela ao clicar "+ Adicionar ao Pedido" no card); o resto volta pro carrinho.
        basic_redir($action === 'adicionar' ? $home_url : $cart_url);
    }
}
