<?php
// home.php — landing pública (vitrine)
?>

    <!-- ===================== VITRINE (Tela 1, plano 004) ===================== -->

    <!-- HERO -->
    <section class="hero-section animate-fadein animate-pending" id="top">
        <div class="container hero-content" style="max-width:1140px">
            <div class="hero-text">
                <div class="hero-indicators mb-3">
                    <div class="hero-indicator">
                        <i class="bi bi-whatsapp" aria-hidden="true"></i>
                        <span>Atendimento exclusivo via WhatsApp</span>
                    </div>
                </div>
                <h1 class="hero-heading">
                    Peptídeos<br><em>premium<br>para você</em>
                </h1>
                <p class="hero-subtitle">
                    Escolha os produtos, a quantidade e finalize seu pedido pagando com PIX.
                </p>
                <div class="hero-indicators">
                    <div class="hero-indicator">
                        <i class="bi bi-patch-check" aria-hidden="true"></i>
                        <span>99% Purity</span>
                    </div>
                    <div class="hero-indicator">
                        <i class="bi bi-grid" aria-hidden="true"></i>
                        <span>46+ Peptídeos</span>
                    </div>
                    <div class="hero-indicator">
                        <i class="bi bi-truck" aria-hidden="true"></i>
                        <span>Entrega Rápida</span>
                    </div>
                </div>
            </div>
            <div class="hero-visual" aria-hidden="true">
                <?php readfile(__DIR__ . '/../../assets/img/favicon.svg'); ?>
            </div>
        </div>
    </section>

    <!-- BUSCA + FILTROS + GRADE DE PRODUTOS -->
    <section class="py-5">
        <div class="container" style="max-width:1140px"
            x-data="productFilter(<?php echo ($q === '' && $cat === '') ? 'true' : 'false'; ?>)">

            <?php html_notification_print(); ?>

            <!--
                hasFullCatalog: o filtro client-side so pode compor com seguranca quando o DOM
                ja tem TODOS os produtos (visita a "/" sem q=/cat=). Um deep link filtrado
                (?cat=X, indexado pelo Google ou salvo nos favoritos — ver plano 006, Passo 10)
                so renderiza os cards daquela categoria; se o JS interceptasse o clique em
                "Todos" ou noutra categoria ali, mostraria um catalogo incompleto ou vazio em
                vez de navegar pro servidor buscar os dados certos. Achado na revisao adversarial
                do /ship. Ver onCategoryClick()/onSearchSubmit() em homeController.js.
            -->
            <form method="get" action="<?php echo $GLOBALS['home_url']; ?>" class="store-search"
                @submit="onSearchSubmit($event)">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" placeholder="Buscar peptídeo..."
                    x-model.debounce.250ms="query"
                    value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            </form>

            <div class="category-chips">
                <a href="<?php echo $GLOBALS['home_url']; ?>"
                    class="category-chip"
                    :class="category === '' ? 'active' : ''"
                    @click="onCategoryClick($event, '')">Todos</a>
                <?php foreach ($categories as $categoryName): ?>
                    <?php
                    // json_encode garante literal JS seguro; htmlspecialchars por cima protege
                    // o atributo HTML que o envolve — o mesmo padrao de payment.php:20-28.
                    $categoryNameJs    = htmlspecialchars(json_encode($categoryName), ENT_QUOTES, 'UTF-8');
                    ?>
                    <a href="<?php echo $GLOBALS['home_url'] . '?cat=' . urlencode($categoryName); ?>"
                        class="category-chip"
                        :class="category === <?php echo $categoryNameJs; ?> ? 'active' : ''"
                        @click="onCategoryClick($event, <?php echo $categoryNameJs; ?>)">
                        <?php echo htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($products)): ?>
                <div class="empty-state">
                    <i class="bi bi-search state-icon" aria-hidden="true"></i>
                    <h3>Nenhum peptídeo encontrado</h3>
                    <p class="mb-0">Tente buscar por outro nome ou veja <a href="<?php echo $GLOBALS['home_url']; ?>">todos os produtos</a>.</p>
                </div>
            <?php else: ?>
                <div class="row g-3" x-ref="grid">
                    <?php foreach ($products as $product):
                        $productId        = (int)$product['idx'];
                        $productName      = (string)$product['name'];
                        $productCategory  = (string)($product['category'] ?? '');
                        $productDosage    = $product['dosage'] ?? null;
                        // Dosagem: valor numerico (ex.: "60") ganha sufixo "mg"; texto
                        // livre (ex.: "5mg/ml") e exibido como esta.
                        $dosageRaw            = trim((string)$productDosage);
                        $productDosageDisplay = $dosageRaw === ''
                            ? ''
                            : (preg_match('/^\d+([.,]\d+)?$/', $dosageRaw) ? $dosageRaw . 'mg' : $dosageRaw);
                        $purityLabel      = $product['purity_label'] ?? null;
                        $unitPriceCents   = (int)$product['price_unit_cents'];
                        $boxQty           = (int)$product['box_qty'];
                        $stock            = (int)$product['stock'];
                        $coverImage       = $product['cover_image'] ?? null;
                        $inStock          = $stock > 0;
                        // json_encode garante literal JS seguro; htmlspecialchars por cima protege
                        // o atributo HTML que o envolve — o mesmo padrao de payment.php:20-28.
                        $productNameJs    = htmlspecialchars(json_encode($productName), ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="col-12 col-sm-6 col-lg-3"
                            data-name="<?php echo htmlspecialchars(mb_strtolower($productName), ENT_QUOTES, 'UTF-8'); ?>"
                            data-category="<?php echo htmlspecialchars($productCategory, ENT_QUOTES, 'UTF-8'); ?>"
                            x-show="matches($el)">
                            <div class="product-card<?php echo $inStock ? '' : ' product-card--unavailable'; ?>"
                                <?php if ($inStock): ?>x-data="productCard(<?php echo $unitPriceCents; ?>, <?php echo $boxQty; ?>)" <?php endif; ?>>

                                <div class="product-card-media">
                                    <?php if ($coverImage && !empty($coverImage['path'])): ?>
                                        <img src="<?php echo htmlspecialchars(constant('cAssets') . $coverImage['path'], ENT_QUOTES, 'UTF-8'); ?>"
                                            alt="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>"
                                            loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <i class="bi bi-capsule" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </div>

                                <div class="product-card-top">
                                    <span class="product-card-name"><?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if (!empty($purityLabel)): ?>
                                        <span class="badge-ticker"><?php echo htmlspecialchars((string)$purityLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($productCategory !== ''): ?>
                                    <div class="product-card-meta"><?php echo htmlspecialchars($productCategory, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($productDosageDisplay !== ''): ?>
                                    <div class="product-card-dosage"><?php echo htmlspecialchars($productDosageDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>

                                <?php if ($inStock): ?>
                                    <form method="post" action="<?php echo $GLOBALS['cart_url']; ?>"
                                        @submit.prevent="add({ products_id: <?php echo $productId; ?>, variant: 'unit', qty: qty, name: <?php echo $productNameJs; ?> })">
                                        <input type="hidden" name="action" value="adicionar">
                                        <input type="hidden" name="products_id" value="<?php echo $productId; ?>">
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

                                        <div class="product-card-footer">
<?php // Rotulo por x-text (nao spans com x-show): no iOS Safari um <button> com
      // filhos que alternam display renderiza o chrome nativo (branco) e some o
      // texto branco. Texto direto mantem o btn-accent pintando o fundo. ?>
                                            <button type="submit" class="btn btn-accent w-100"
                                                :class="added ? 'is-added' : ''"
                                                :disabled="$store.shop.loading || added"
                                                x-text="added ? '✓ Adicionado' : '+ Adicionar ao Pedido'">+ Adicionar ao Pedido</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="product-card-footer">
                                        <button type="button" class="btn btn-ghost w-100" disabled>ESGOTADO</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="empty-state" x-show="visibleCount === 0" style="display:none">
                    <i class="bi bi-search state-icon" aria-hidden="true"></i>
                    <h3>Nenhum peptídeo encontrado</h3>
                    <p class="mb-0">Tente buscar por outro nome ou veja <a href="<?php echo $GLOBALS['home_url']; ?>">todos os produtos</a>.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
