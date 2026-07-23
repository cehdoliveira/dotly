<?php
// payment.php — Tela 4 "Pague com PIX" (plano 004)
// Variaveis de checkout_controller::payment(): $order, $charge
$isRedirectMode = $charge['redirect_url'] !== null;
$totalFormatted = number_format((int)$order['total_cents'] / 100, 2, ',', '.');
$expiresAtIso   = date('c', strtotime((string)$charge['expires_at']));
$isExpiredNow   = strtotime((string)$charge['expires_at']) <= time();
$statusUrl      = sprintf($GLOBALS['payment_url'], $order['token']) . '/status';
$doneUrl        = sprintf($GLOBALS['done_url'], $order['token']);

// redirect_url vem da resposta do PSP (InfinitePayGateway) — so aceita http(s)
// antes de virar href, nunca confia no esquema de uma URL vinda de terceiro.
$redirectUrl = null;
if ($isRedirectMode) {
    $scheme = parse_url((string)$charge['redirect_url'], PHP_URL_SCHEME);
    if (in_array($scheme, ['http', 'https'], true)) {
        $redirectUrl = (string)$charge['redirect_url'];
    }
}
?>
<?php
// x-data monta uma chamada JS — json_encode (nao aspas manuais) garante que o
// valor vira um literal de string JS seguro mesmo se um dia deixar de vir só
// de fontes internas (htmlspecialchars sozinho protege o atributo HTML, não
// o contexto JS que o Alpine avalia depois de decodificar as entidades).
$paymentStatusArgs = htmlspecialchars(
    json_encode($statusUrl) . ',' . json_encode($doneUrl) . ',' . json_encode($expiresAtIso),
    ENT_QUOTES,
    'UTF-8'
);
?>
<div class="container py-4" style="max-width:480px"
     x-data="paymentStatus(<?php echo $paymentStatusArgs; ?>)">

    <?php /*
     * Conteudo real no DOM (nunca dentro de <template x-if>) — se o Alpine
     * nao carregar (CDN bloqueado, JS desligado), o comprador ainda ve a tela
     * certa pro estado no momento do render. JS so faz a transicao ao vivo
     * quando o cliente cruza o expires_at sem recarregar a pagina.
     */ ?>
    <div class="payment-expired-box"<?php if (!$isExpiredNow): ?> x-show="expired" style="display:none"<?php endif; ?>>
        <i class="bi bi-hourglass-bottom" style="font-size:2.5rem;color:var(--text-muted);" aria-hidden="true"></i>
        <h1 class="mt-3" style="font-size:1.3rem;">Este pedido expirou.</h1>
        <a href="<?php echo $GLOBALS['home_url']; ?>" class="btn btn-accent mt-3">Fazer novo pedido</a>
    </div>

    <?php if (!$isExpiredNow): ?>
        <div x-show="!expired">
            <h1 class="mb-1" style="font-size:1.4rem;">Pague com PIX</h1>
            <p class="mb-4" style="color:var(--text-muted);font-size:0.88rem;">
                Seu pedido está reservado por 30 minutos.
            </p>

            <?php if (!$isRedirectMode): ?>
                <div class="text-center mb-3">
                    <div class="qr-container d-inline-block">
                        <img src="data:image/png;base64,<?php echo htmlspecialchars((string)$charge['qr_image_base64'], ENT_QUOTES, 'UTF-8'); ?>" alt="QR Code do PIX" style="max-width:220px;width:100%;">
                    </div>
                </div>
                <p class="text-center mb-4" style="font-size:0.85rem;color:var(--text-muted);">
                    Abra o app do seu banco e aponte a câmera.
                </p>

                <div class="text-center mb-3" style="font-size:0.78rem;color:var(--text-muted);">— ou —</div>

                <button type="button" class="btn btn-accent w-100 mb-2" @click="copyCode()">
                    <span x-text="copied ? '✓ Copiado!' : 'Copiar código PIX'">Copiar código PIX</span>
                </button>
                <p class="mb-3" style="font-size:0.78rem;color:var(--text-muted);">
                    Cole no seu banco, em "Pix Copia e Cola".
                </p>
                <textarea class="payment-code-box mb-4" rows="3" readonly x-ref="pixCode"><?php echo htmlspecialchars((string)$charge['qr_payload'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            <?php elseif ($redirectUrl !== null): ?>
                <a href="<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_self" class="btn btn-accent w-100 mb-3">Ir para o pagamento</a>
                <p class="mb-4" style="font-size:0.85rem;color:var(--text-muted);">
                    Você vai para o ambiente seguro de pagamento e volta pra cá no fim.
                </p>
            <?php else: ?>
                <p class="mb-4" style="color:var(--text-muted);">
                    Não conseguimos abrir seu pagamento agora. Tente de novo em instantes.
                </p>
            <?php endif; ?>

            <p class="mb-2" style="font-size:0.95rem;">Total: <strong>R$ <?php echo $totalFormatted; ?></strong></p>

            <?php if (!$isRedirectMode): ?>
                <p class="payment-countdown mb-3">
                    <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Expira em <span x-text="countdownLabel">--:--</span>
                </p>

                <p class="mb-3" style="font-size:0.88rem;color:var(--text-muted);">
                    <span class="pulsing-dot" aria-hidden="true"></span>Aguardando seu pagamento...
                </p>
            <?php endif; ?>

        </div>
    <?php endif; ?>
</div>
