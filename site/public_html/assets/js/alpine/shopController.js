/**
 * Shop Controller - Alpine.js
 * Estado global da vitrine: contador do pedido no header, drawer do carrinho e
 * modal de produto. Todo preco/total vem do servidor via JSON — o cliente nunca
 * recalcula dinheiro. Sem JS, os links continuam navegando normalmente.
 */

document.addEventListener('alpine:init', () => {
    Alpine.store('shop', {
        cartCount: 0,
        cartOpen: false,
        // Mobile: a sacola flutuante contabiliza e pulsa a cada item; o drawer
        // so abre ao toca-la (ver addToCart). Desktop mantem o drawer automatico.
        isMobile: false,
        fabBump: false,
        _bumpTimer: null,
        lines: [],
        subtotalCents: 0,
        encargosCents: 0,
        totalCents: 0,
        feePercentBps: 0,
        feeFixedCents: 0,
        feeInfinityCents: 0,
        loading: false,
        _csrfToken: '',

        init() {
            // Contador inicial vem do DOM que o PHP ja pintou (ver header.php),
            // pra nao gastar um request so pra saber o que a pagina ja sabe.
            const badge = document.querySelector('.store-cart-badge');
            this.cartCount = badge ? parseInt(badge.textContent, 10) || 0 : 0;

            // validate_csrf() consome (unset) o token da sessao a cada POST —
            // guardamos o token em estado (nao relemos o DOM a cada chamada)
            // e atualizamos com o token novo que cada resposta AJAX devolve
            // (ver cartJsonPayload() no cart_controller). Sem isso, o segundo
            // "Adicionar ao Pedido" via AJAX falhava fora da janela de graca
            // de 10s do validate_csrf — achado no /ship.
            const input = document.querySelector('input[name="_csrf_token"]');
            this._csrfToken = input ? input.value : '';

            // No mobile, revelar o drawer a cada "Adicionar" obrigava o leigo a
            // fecha-lo varias vezes enquanto montava o pedido. Aqui detectamos a
            // largura pra, no addToCart, so pulsar a sacola flutuante em vez de
            // abrir o drawer — que agora abre apenas ao toque na sacola. O mesmo
            // breakpoint (767px) ja e usado no main.css pra mostrar a sacola.
            this._mq = window.matchMedia('(max-width: 767px)');
            this.isMobile = this._mq.matches;
            this._mq.addEventListener('change', (e) => { this.isMobile = e.matches; });
        },

        // Reinicia a animacao de "pulo" da sacola flutuante (mobile) a cada item
        // adicionado. prefers-reduced-motion zera a animacao no CSS.
        bumpFab() {
            this.fabBump = false;
            requestAnimationFrame(() => { this.fabBump = true; });
            clearTimeout(this._bumpTimer);
            this._bumpTimer = setTimeout(() => { this.fabBump = false; }, 450);
        },

        csrfToken() {
            return this._csrfToken;
        },

        cartUrl() {
            const link = document.querySelector('.store-cart-link');
            return link ? link.getAttribute('href') : '/carrinho';
        },

        formattedPrice(cents) {
            const value = (cents / 100).toFixed(2).replace('.', ',');
            const parts = value.split(',');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return 'R$ ' + parts.join(',');
        },

        // SweetAlert2 injeta title/html via innerHTML — nome e descricao vem
        // do banco (cadastro do produto no manager), nao sao HTML confiavel.
        // Mesmo padrao de product.php: htmlspecialchars() antes de qualquer
        // formatacao (nl2br la, <br> aqui).
        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        applyCartData(data) {
            this.cartCount = data.count;
            this.lines = data.lines;
            this.subtotalCents = data.subtotal_cents;
            this.encargosCents = data.encargos_cents;
            this.totalCents = data.total_cents;
            this.feePercentBps = data.fee_percent_bps;
            this.feeFixedCents = data.fee_fixed_cents;
            this.feeInfinityCents = data.fee_infinity_cents;
            if (data.csrf_token) {
                this._csrfToken = data.csrf_token;
            }
        },

        // Rotulo dos encargos, ex.: "(+10% + R$ 60,00)". Percentual e taxa fixa
        // vem do servidor (OrderPricing/settings) — nunca hardcode, pra o rotulo
        // nao mentir se a taxa mudar. A taxa Infinity, quando incide (> 0), entra
        // como componente extra pra soma bater com o valor exibido em Encargos.
        encargosLabel() {
            const percent = (this.feePercentBps / 100)
                .toFixed(2).replace('.', ',').replace(/,00$/, '').replace(/(,\d)0$/, '$1');
            const parts = ['+' + percent + '%', '+ ' + this.formattedPrice(this.feeFixedCents)];
            if (this.feeInfinityCents > 0) {
                parts.push('+ ' + this.formattedPrice(this.feeInfinityCents) + ' Infinity');
            }
            return '(' + parts.join(' ') + ')';
        },

        async parseJsonResponse(res) {
            const type = res.headers.get('content-type') || '';
            if (!res.ok || !type.includes('application/json')) {
                Swal.fire({
                    icon: 'error',
                    title: 'Não foi possível concluir',
                    text: 'Sua sessão pode ter expirado. Recarregue a página e tente de novo.',
                });
                return null;
            }
            return res.json();
        },

        async openCart() {
            this.loading = true;
            try {
                const res = await fetch(this.cartUrl() + '?format=json', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await this.parseJsonResponse(res);
                if (data === null) {
                    return;
                }
                this.applyCartData(data);
                this.cartOpen = true;
            } finally {
                this.loading = false;
            }
        },

        closeCart() {
            this.cartOpen = false;
        },

        async addToCart(payload) {
            this.loading = true;
            try {
                const body = new URLSearchParams({
                    action: 'adicionar',
                    products_id: payload.products_id,
                    variant: payload.variant,
                    qty: payload.qty,
                    format: 'json',
                    _csrf_token: this.csrfToken(),
                });

                const res = await fetch(this.cartUrl(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                });
                const data = await this.parseJsonResponse(res);
                if (data === null) {
                    return false;
                }
                this.applyCartData(data);

                // Desktop: revela o drawer "Seu pedido" ja com o item recem-adicionado
                // (applyCartData atualizou lines/total) — o leigo ve o pedido tomando
                // forma e o botao "Finalizar pedido" logo ali. Mobile: nao abre o
                // drawer (evita o fecha-e-abre repetido); so pulsa a sacola flutuante,
                // que passa a exibir o contador. O botao do card vira "Adicionado".
                if (this.isMobile) {
                    this.bumpFab();
                } else {
                    this.cartOpen = true;
                }
                return true;
            } finally {
                this.loading = false;
            }
        },

        // Ajusta a quantidade de uma linha do drawer por um delta (+1 / -1).
        // line.qty vem do JSON como string ("1"), entao Number() antes de somar —
        // sem isso, "1" + 1 vira "11" (concatenacao). Chegar a 0 vira remocao
        // (mesma regra do "-" da cart.php quando qty chega a 1), pra o leigo nao
        // ficar com um item de quantidade zero no pedido.
        async updateQty(line, delta) {
            const next = Number(line.qty) + delta;
            if (next < 1) {
                await this.removeLine(line);
                return;
            }
            await this._cartAction('atualizar', line.products_id, line.variant, next);
        },

        async removeLine(line) {
            await this._cartAction('remover', line.products_id, line.variant, 0);
        },

        // POST compartilhado por updateQty/removeLine: reaproveita applyCartData
        // (o servidor devolve o pedido inteiro ja recalculado + csrf_token novo,
        // igual ao addToCart). loading trava os botoes do stepper contra duplo
        // clique enquanto a requisicao esta no ar.
        async _cartAction(action, productsId, variant, qty) {
            this.loading = true;
            try {
                const body = new URLSearchParams({
                    action: action,
                    products_id: productsId,
                    variant: variant,
                    qty: qty,
                    format: 'json',
                    _csrf_token: this.csrfToken(),
                });

                const res = await fetch(this.cartUrl(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                });
                const data = await this.parseJsonResponse(res);
                if (data === null) {
                    return;
                }
                this.applyCartData(data);
            } finally {
                this.loading = false;
            }
        },

        // Frascos que uma linha representa: caixa vale box_qty frascos, unidade 1.
        // line.qty/box_qty vem do JSON como string — Number() antes de multiplicar.
        _lineUnits(line) {
            const qty = Number(line.qty);
            return line.variant === 'box' ? qty * Number(line.box_qty) : qty;
        },

        // Categoria "Diluente" agrupa a agua bacteriostatica (BAC Water). Cada
        // frasco de peptideo (nao-Diluente) precisa de 1 BAC Water pra reconstituir.
        _isDiluent(line) {
            return String(line.category || '').toLowerCase() === 'diluente';
        },

        // Frascos de peptideo no pedido — quantos BAC Water o pedido pede.
        bacNeeded() {
            return this.lines.reduce(
                (sum, line) => sum + (this._isDiluent(line) ? 0 : this._lineUnits(line)),
                0,
            );
        },

        // BAC Water ja no pedido.
        bacHave() {
            return this.lines.reduce(
                (sum, line) => sum + (this._isDiluent(line) ? this._lineUnits(line) : 0),
                0,
            );
        },

        // Quantos BAC Water faltam. > 0 dispara o lembrete no drawer (nao bloqueia
        // a compra — so avisa). 0 quando esta coberto ou o pedido nao tem peptideo.
        bacShortfall() {
            return Math.max(0, this.bacNeeded() - this.bacHave());
        },
    });
});
