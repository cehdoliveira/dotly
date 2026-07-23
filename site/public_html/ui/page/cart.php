<?php
// cart.php — Tela 2 "Meu Pedido" (plano 004)
// Variaveis de cart_controller::index(): $lines, $totalCents
// (cada linha de $lines: products_id, variant, qty, name, unit_price_cents, line_total_cents)
$csrfToken = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<div class="container py-4" style="max-width:640px">

    <a href="<?php echo $GLOBALS['home_url']; ?>" class="d-inline-block mb-3" style="font-size:0.85rem;color:var(--text-muted);text-decoration:none;">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Continuar comprando
    </a>

    <h1 class="mb-4" style="font-size:1.5rem;">Meu Pedido</h1>

    <?php html_notification_print(); ?>

    <?php if (empty($lines)): ?>
        <div class="empty-state">
            <i class="bi bi-bag state-icon" aria-hidden="true"></i>
            <h3>Seu pedido está vazio.</h3>
            <a href="<?php echo $GLOBALS['home_url']; ?>" class="btn btn-accent mt-2">Ver produtos</a>
        </div>
    <?php else: ?>

        <?php foreach ($lines as $line):
            $productId   = (int)$line['products_id'];
            $variant     = (string)$line['variant'];
            $qty         = (int)$line['qty'];
            $variantLabel = $variant === 'box' ? 'Caixa' : 'Unidade';
        ?>
            <div class="cart-line">
                <div class="cart-line-media">
                    <i class="bi bi-capsule" aria-hidden="true"></i>
                </div>
                <div class="cart-line-body">
                    <div class="cart-line-name"><?php echo htmlspecialchars((string)$line['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="cart-line-meta"><?php echo htmlspecialchars($variantLabel, ENT_QUOTES, 'UTF-8'); ?></div>

                    <div class="cart-line-controls">
                        <div class="qty-stepper">
                            <form method="post" action="<?php echo $GLOBALS['cart_url']; ?>" style="display:inline;">
                                <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="products_id" value="<?php echo $productId; ?>">
                                <input type="hidden" name="variant" value="<?php echo htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if ($qty <= 1): ?>
                                    <input type="hidden" name="action" value="remover">
                                    <button type="submit" aria-label="Remover">&minus;</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="atualizar">
                                    <input type="hidden" name="qty" value="<?php echo $qty - 1; ?>">
                                    <button type="submit" aria-label="Diminuir quantidade">&minus;</button>
                                <?php endif; ?>
                            </form>
                            <span><?php echo $qty; ?></span>
                            <form method="post" action="<?php echo $GLOBALS['cart_url']; ?>" style="display:inline;">
                                <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="products_id" value="<?php echo $productId; ?>">
                                <input type="hidden" name="variant" value="<?php echo htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="atualizar">
                                <input type="hidden" name="qty" value="<?php echo $qty + 1; ?>">
                                <button type="submit" aria-label="Aumentar quantidade">+</button>
                            </form>
                        </div>
                        <span class="cart-line-price">R$ <?php echo number_format((int)$line['line_total_cents'] / 100, 2, ',', '.'); ?></span>
                    </div>

                    <form method="post" action="<?php echo $GLOBALS['cart_url']; ?>" class="mt-2">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="products_id" value="<?php echo $productId; ?>">
                        <input type="hidden" name="variant" value="<?php echo htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="remover">
                        <button type="submit" class="cart-line-remove" style="background:none;border:none;padding:0;">Remover</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
            // Rotulo dos encargos, ex.: "(+10% + R$ 60,00)" — percentual e taxa
            // fixa vindos de OrderPricing/settings (mesmo criterio do drawer).
            $encargosCents = $pricing['total_cents'] - $pricing['subtotal_cents'];
            $feePercentLabel = rtrim(rtrim(number_format($pricing['fee_percent_bps'] / 100, 2, ',', '.'), '0'), ',');
            $encargosParts = [
                '+' . $feePercentLabel . '%',
                '+ R$ ' . number_format($pricing['fee_fixed_cents'] / 100, 2, ',', '.'),
            ];
            if ($pricing['fee_infinity_cents'] > 0) {
                $encargosParts[] = '+ R$ ' . number_format($pricing['fee_infinity_cents'] / 100, 2, ',', '.') . ' Infinity';
            }
        ?>
        <div class="cart-summary">
            <div class="cart-summary-row">
                <span>Subtotal</span>
                <span>R$ <?php echo number_format($pricing['subtotal_cents'] / 100, 2, ',', '.'); ?></span>
            </div>
            <div class="cart-summary-row cart-summary-row--fee">
                <span>Encargos <span class="cart-summary-note">(<?php echo htmlspecialchars(implode(' ', $encargosParts), ENT_QUOTES, 'UTF-8'); ?>)</span></span>
                <span>R$ <?php echo number_format($encargosCents / 100, 2, ',', '.'); ?></span>
            </div>
        </div>

        <div class="cart-total-row">
            <span class="cart-total-label">Total</span>
            <span class="cart-total-value">R$ <?php echo number_format($pricing['total_cents'] / 100, 2, ',', '.'); ?></span>
        </div>

        <a href="<?php echo $GLOBALS['checkout_url']; ?>" class="btn btn-accent w-100 btn-lg">Finalizar pedido</a>

        <p class="text-center mt-3" style="font-size:0.8rem;color:var(--text-muted);">
            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
            Pagamento por PIX. Você recebe o código na próxima tela.
        </p>

    <?php endif; ?>
</div>
