</head>

<body>
    <header>
        <nav class="ss-navbar">
            <div class="container ss-navbar-inner">
                <a class="ss-brand" href="<?php echo $GLOBALS['home_url']; ?>">
                    <span class="brand-logo" aria-hidden="true"><?php readfile(__DIR__ . '/../../assets/img/logo.svg'); ?></span>
                </a>

                <div class="ss-navbar-actions">
                    <a class="ss-track-link" href="<?php echo $GLOBALS['track_order_url']; ?>">
                        <i class="bi bi-truck" aria-hidden="true"></i>
                        <span class="ss-track-link-label">Acompanhar pedido</span>
                    </a>

                    <a class="store-cart-link" href="<?php echo $GLOBALS['cart_url']; ?>"
                        @click.prevent="$store.shop.openCart()"
                        x-data>
                        <i class="bi bi-bag" aria-hidden="true"></i>
                        Pedido
                        <span class="store-cart-badge" x-show="$store.shop.cartCount > 0"
                            x-text="$store.shop.cartCount"
                            <?php echo Cart::count() > 0 ? '' : 'style="display:none"'; ?>><?php echo (int)Cart::count(); ?></span>
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <div class="cart-overlay" x-data x-show="$store.shop.cartOpen"
        @click="$store.shop.closeCart()" x-transition.opacity style="display:none"></div>

    <aside class="cart-panel" x-data x-show="$store.shop.cartOpen"
        x-transition:enter-start="cart-panel--closed" x-transition:leave-end="cart-panel--closed"
        role="dialog" aria-modal="true" aria-label="Seu pedido" style="display:none"
        @keydown.escape.window="$store.shop.closeCart()">
        <template x-if="$store.shop.cartOpen">
            <div class="cart-panel-inner">
                <div class="cart-panel-header">
                    <h2>Seu pedido<span class="cart-count-tag" x-show="$store.shop.cartCount > 0"
                        x-text="$store.shop.cartCount + (Number($store.shop.cartCount) === 1 ? ' item' : ' itens')"></span></h2>
                    <button type="button" class="cart-panel-close" @click="$store.shop.closeCart()" aria-label="Fechar">&times;</button>
                </div>
                <div class="cart-panel-body">
                    <template x-if="$store.shop.lines.length === 0">
                        <div class="empty-state">
                            <i class="bi bi-bag state-icon" aria-hidden="true"></i>
                            <h3>Seu pedido está vazio.</h3>
                        </div>
                    </template>

                    <template x-for="line in $store.shop.lines" :key="line.products_id + ':' + line.variant">
                        <div class="cart-line">
                            <div class="cart-line-body">
                                <div class="cart-line-head">
                                    <div class="cart-line-name" x-text="line.name"></div>
                                    <button type="button" class="cart-line-remove cart-line-remove--icon"
                                        @click="$store.shop.removeLine(line)" :disabled="$store.shop.loading"
                                        aria-label="Remover item">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="cart-line-meta" x-text="line.variant === 'box' ? 'Caixa' : 'Unidade'"></div>
                                <div class="cart-line-controls">
                                    <div class="qty-stepper">
                                        <button type="button" @click="$store.shop.updateQty(line, -1)"
                                            :disabled="$store.shop.loading"
                                            :aria-label="Number(line.qty) <= 1 ? 'Remover item' : 'Diminuir quantidade'">&minus;</button>
                                        <span x-text="line.qty"></span>
                                        <button type="button" @click="$store.shop.updateQty(line, 1)"
                                            :disabled="$store.shop.loading" aria-label="Aumentar quantidade">+</button>
                                    </div>
                                    <span class="cart-line-price" x-text="$store.shop.formattedPrice(line.line_total_cents)"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="cart-panel-footer" x-show="$store.shop.lines.length > 0">
                    <div class="cart-bac-reminder" x-show="$store.shop.bacShortfall() > 0" style="display:none">
                        <i class="bi bi-droplet-half" aria-hidden="true"></i>
                        <div class="cart-bac-reminder-text">
                            <strong x-text="'Faltam ' + $store.shop.bacShortfall() + ' BAC Water'"></strong>
                            <span x-text="'Cada frasco de peptídeo precisa de uma água bacteriostática para reconstituir. Você tem ' + $store.shop.bacHave() + ' de ' + $store.shop.bacNeeded() + '.'"></span>
                        </div>
                    </div>
                    <div class="cart-summary">
                        <div class="cart-summary-row">
                            <span>Subtotal</span>
                            <span x-text="$store.shop.formattedPrice($store.shop.subtotalCents)"></span>
                        </div>
                        <div class="cart-summary-row cart-summary-row--fee">
                            <span>Encargos <span class="cart-summary-note" x-text="$store.shop.encargosLabel()"></span></span>
                            <span x-text="$store.shop.formattedPrice($store.shop.encargosCents)"></span>
                        </div>
                    </div>
                    <div class="cart-total-row">
                        <span class="cart-total-label">Total</span>
                        <span class="cart-total-value" x-text="$store.shop.formattedPrice($store.shop.totalCents)"></span>
                    </div>
                    <a class="btn btn-accent w-100 btn-lg" href="<?php echo $GLOBALS['checkout_url']; ?>">Finalizar pedido</a>
                </div>
            </div>
        </template>
    </aside>

    <!-- Sacola flutuante (somente mobile, via CSS). No celular o drawer nao abre
         mais a cada "Adicionar" — a contagem sobe aqui e a sacola pulsa; tocar
         nela abre o drawer pra conferir e finalizar. Escondida quando o drawer
         ja esta aberto ou o pedido esta vazio. -->
    <button type="button" class="cart-fab" x-data
        x-show="$store.shop.cartCount > 0 && !$store.shop.cartOpen"
        x-transition
        :class="$store.shop.fabBump ? 'cart-fab--bump' : ''"
        @click="$store.shop.openCart()"
        aria-label="Ver pedido" style="display:none">
        <i class="bi bi-bag" aria-hidden="true"></i>
        <span class="cart-fab-count" x-text="$store.shop.cartCount"></span>
    </button>

    <main id="mainContent" class="flex-shrink-0">
