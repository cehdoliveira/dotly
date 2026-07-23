<?php
// product.php — Tela 1b "Produto" (plano 004, opcional)
// Variaveis de shop_controller::product(): $product
$images = $product['images_attach'] ?? [];
$cover  = null;
foreach ($images as $image) {
    if (($image['is_cover'] ?? 'no') === 'yes') {
        $cover = $image;
        break;
    }
}
$cover = $cover ?? ($images[0] ?? null);

$unitPriceCents = (int)$product['price_unit_cents'];
$boxQty         = (int)$product['box_qty'];
$stock          = (int)$product['stock'];
$inStock        = $stock > 0;
?>
<div class="container py-4" style="max-width:900px">
    <a href="<?php echo $GLOBALS['home_url']; ?>" class="d-inline-block mb-3" style="font-size:0.85rem;color:var(--text-muted);text-decoration:none;">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Voltar aos produtos
    </a>

    <?php html_notification_print(); ?>

    <div class="row g-4">
        <div class="col-md-5">
            <div class="product-card-media" style="aspect-ratio:1/1;">
                <?php if ($cover && !empty($cover['path'])): ?>
                    <img src="<?php echo htmlspecialchars(constant('cAssets') . $cover['path'], ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?php echo htmlspecialchars((string)$product['name'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php else: ?>
                    <i class="bi bi-capsule" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-7">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <h1 style="font-size:1.4rem;"><?php echo htmlspecialchars((string)$product['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <?php if (!empty($product['purity_label'])): ?>
                    <span class="badge-ticker"><?php echo htmlspecialchars((string)$product['purity_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($product['category'])): ?>
                <div class="product-card-meta"><?php echo htmlspecialchars((string)$product['category'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php
                // Dosagem: valor numerico (ex.: "60") ganha sufixo "mg"; texto livre
                // (ex.: "5mg/ml") e exibido como esta. Mesmo criterio da home.php.
                $dosageRaw = trim((string)($product['dosage'] ?? ''));
                $dosageDisplay = $dosageRaw === ''
                    ? ''
                    : (preg_match('/^\d+([.,]\d+)?$/', $dosageRaw) ? $dosageRaw . 'mg' : $dosageRaw);
            ?>
            <?php if ($dosageDisplay !== ''): ?>
                <div class="product-card-dosage mb-2"><?php echo htmlspecialchars($dosageDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (!empty($product['description'])): ?>
                <p style="color:var(--text);font-size:0.9rem;"><?php echo nl2br(htmlspecialchars((string)$product['description'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>

            <?php if ($inStock): ?>
                <div class="product-card" x-data="productCard(<?php echo $unitPriceCents; ?>, <?php echo $boxQty; ?>)">
                    <form method="post" action="<?php echo $GLOBALS['cart_url']; ?>">
                        <input type="hidden" name="action" value="adicionar">
                        <input type="hidden" name="products_id" value="<?php echo (int)$product['idx']; ?>">
                        <input type="hidden" name="variant" value="unit">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="product-card-type-label">Tipo</div>
                        <div class="variant-pills">
                            <button type="button" class="variant-pill" :class="variant === 'unit' ? 'active' : ''" @click="selectVariant('unit')">Unidade</button>
                            <button type="button" class="variant-pill" :class="variant === 'box' ? 'active' : ''" @click="selectVariant('box')">Caixa &times;<?php echo $boxQty; ?></button>
                        </div>

                        <div class="product-card-price-row">
                            <div class="qty-stepper">
                                <button type="button" @click="decrement()" aria-label="Diminuir quantidade">&minus;</button>
                                <input type="number" name="qty" min="1" max="99" x-model.number="qty">
                                <button type="button" @click="increment()" aria-label="Aumentar quantidade">+</button>
                            </div>
                            <span class="product-card-price" x-text="formattedPrice()">R$ <?php echo number_format($unitPriceCents / 100, 2, ',', '.'); ?></span>
                        </div>

                        <button type="submit" class="btn btn-accent w-100">+ Adicionar ao Pedido</button>
                    </form>
                </div>
            <?php else: ?>
                <button type="button" class="btn btn-ghost w-100" disabled>ESGOTADO</button>
            <?php endif; ?>
        </div>
    </div>
</div>
