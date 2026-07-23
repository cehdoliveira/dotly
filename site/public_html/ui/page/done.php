<?php
// done.php — Tela 5 "Acompanhar meu pedido" (plano 004)
// Variaveis de checkout_controller::done(): $order, $orderItems
$status         = (string)$order['status'];
$totalFormatted = number_format((int)$order['total_cents'] / 100, 2, ',', '.');
$subtotalFormatted    = number_format((int)$order['subtotal_cents'] / 100, 2, ',', '.');
$feePercentFormatted  = number_format((int)$order['fee_percent_cents'] / 100, 2, ',', '.');
// Percentual efetivo do pedido (nao a taxa atual em settings, que pode ter mudado desde a compra).
$feePercentLabel = (int)$order['subtotal_cents'] > 0
    ? rtrim(rtrim(number_format((int)$order['fee_percent_cents'] / (int)$order['subtotal_cents'] * 100, 2, ',', '.'), '0'), ',')
    : '0';
$feeFixedFormatted    = number_format((int)$order['fee_fixed_cents'] / 100, 2, ',', '.');
$feeInfinityFormatted = number_format((int)$order['fee_infinity_cents'] / 100, 2, ',', '.');
// Encargos = tudo que nao e produto (taxa% + cambio + infinity), somado numa
// linha so na tela de confirmacao — o detalhamento fica no checkout/pagamento.
$encargosFormatted    = number_format(((int)$order['total_cents'] - (int)$order['subtotal_cents']) / 100, 2, ',', '.');
$statusUrl      = sprintf($GLOBALS['payment_url'], $order['token']) . '/status';
$trackOrderUrl  = $GLOBALS['track_order_url'];
$whatsappUrl    = 'https://wa.me/' . constant('whatsapp_number');

// Endereco em bloco (etiqueta de entrega) em vez de linha unica corrida.
$cepDigits    = preg_replace('/\D/', '', (string)$order['ship_zip']) ?? '';
$cepFormatted = strlen($cepDigits) === 8
    ? substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5)
    : (string)$order['ship_zip'];
$streetLine = $order['ship_street'] . ', ' . $order['ship_number']
    . (!empty($order['ship_complement']) ? ' · ' . $order['ship_complement'] : '');
$cityLine   = $order['ship_district'] . ' · ' . $order['ship_city'] . '/' . $order['ship_uf'];

// So a tela "aguardando" precisa pollar; json_encode garante literal JS seguro.
$statusArg = htmlspecialchars(json_encode($statusUrl), ENT_QUOTES, 'UTF-8');
?>
<div class="container py-4" style="max-width:560px"<?php if ($status === 'aguardando_pagamento'): ?> x-data="orderStatus(<?php echo $statusArg; ?>)"<?php endif; ?>>

    <?php if ($status === 'pago'): ?>
        <i class="bi bi-check-circle-fill done-icon done-icon--success" aria-hidden="true"></i>
        <h1 class="done-title">Pagamento confirmado!</h1>
        <p class="text-center mb-4" style="color:var(--text-muted);">
            Seu pedido será separado, e você será notificado no seu e-mail
            <span style="color:var(--text);font-weight:600;"><?php echo htmlspecialchars((string)$order['customer_mail'], ENT_QUOTES, 'UTF-8'); ?></span>
            quando ele for enviado.
        </p>
    <?php elseif ($status === 'aguardando_pagamento'): ?>
        <i class="bi bi-hourglass-split done-icon done-icon--pending" aria-hidden="true"></i>
        <h1 class="done-title">Estamos aguardando seu PIX</h1>
    <?php elseif ($status === 'expirado'): ?>
        <i class="bi bi-x-circle done-icon done-icon--muted" aria-hidden="true"></i>
        <h1 class="done-title">O prazo deste pedido acabou</h1>
    <?php elseif ($status === 'cancelado'): ?>
        <i class="bi bi-slash-circle done-icon done-icon--muted" aria-hidden="true"></i>
        <h1 class="done-title">Pedido cancelado</h1>
    <?php endif; ?>

    <div class="my-4">
        <div class="checkout-section-label">Seu pedido</div>
        <?php foreach ($orderItems as $item):
            $variantLabel = $item['variant'] === 'box' ? 'Caixa' : 'Unidade';
        ?>
            <div class="done-summary-row">
                <span>
                    <?php echo htmlspecialchars((string)$item['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                    · <?php echo $variantLabel; ?>
                    · <?php echo (int)$item['qty']; ?>
                </span>
                <span>R$ <?php echo number_format((int)$item['line_total_cents'] / 100, 2, ',', '.'); ?></span>
            </div>
        <?php endforeach; ?>
        <?php /* Uma vez pago, o cliente so quer o total pago — o detalhamento de
                 taxas so importa antes de pagar (aguardando/expirado/cancelado). */ ?>
        <?php if ($status !== 'pago'): ?>
            <div class="done-summary-row">
                <span>Subtotal</span>
                <span>R$ <?php echo $subtotalFormatted; ?></span>
            </div>
            <div class="done-summary-row">
                <span>Taxa <?php echo $feePercentLabel; ?>%</span>
                <span>R$ <?php echo $feePercentFormatted; ?></span>
            </div>
            <div class="done-summary-row">
                <span>Câmbio</span>
                <span>R$ <?php echo $feeFixedFormatted; ?></span>
            </div>
            <?php if ((int)$order['fee_infinity_cents'] > 0): ?>
                <div class="done-summary-row">
                    <span>Taxa Infinity</span>
                    <span>R$ <?php echo $feeInfinityFormatted; ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($status === 'pago'): ?>
            <div class="done-summary-row done-summary-row--muted">
                <span>Encargos</span>
                <span>R$ <?php echo $encargosFormatted; ?></span>
            </div>
        <?php endif; ?>
        <div class="done-summary-row">
            <span><?php echo $status === 'pago' ? 'Total pago' : 'Total'; ?></span>
            <span>R$ <?php echo $totalFormatted; ?></span>
        </div>
    </div>

    <?php if (in_array($status, ['pago', 'aguardando_pagamento'], true)): ?>
        <div class="checkout-section-label">Entrega</div>
        <div class="done-address">
            <div class="done-address__name"><?php echo htmlspecialchars((string)$order['customer_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div><?php echo htmlspecialchars($streetLine, ENT_QUOTES, 'UTF-8'); ?></div>
            <div><?php echo htmlspecialchars($cityLine, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="done-address__cep">CEP <?php echo htmlspecialchars($cepFormatted, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    <?php endif; ?>

    <?php if (in_array($status, ['pago', 'aguardando_pagamento'], true)): ?>
        <p class="done-track-hint">
            Para acompanhar seu pedido, acesse o link abaixo e insira seu e-mail e os 4 últimos dígitos do seu WhatsApp.
        </p>
    <?php endif; ?>

    <?php if ($status === 'pago'): ?>
        <a href="<?php echo htmlspecialchars($trackOrderUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-accent w-100 mb-2">Acompanhar pedido</a>
        <a href="<?php echo htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-ghost w-100" target="_blank" rel="noopener">Falar no WhatsApp</a>
    <?php elseif ($status === 'aguardando_pagamento'): ?>
        <a href="<?php echo htmlspecialchars(sprintf($GLOBALS['payment_url'], $order['token']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-accent w-100 mb-2">Ver o código PIX</a>
        <a href="<?php echo htmlspecialchars($trackOrderUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-ghost w-100">Acompanhar pedido</a>
    <?php else: ?>
        <a href="<?php echo htmlspecialchars($GLOBALS['home_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-accent w-100">Fazer novo pedido</a>
    <?php endif; ?>
</div>
