/**
 * Home Controller - Alpine.js
 * Card de produto da vitrine (Tela 1): o campo de quantidade conta sempre em
 * UNIDADES (edicao livre). "Unidade" e "Caixa x10" sao um seletor de passo
 * (default Unidade): selecionar um deles soma o proprio passo ao campo uma vez
 * (+1 ou +boxQty) e passa a valer para o "-"/"+" (de 1 em 1 ou de boxQty em
 * boxQty). O preço é sempre preço unitário x quantidade. Nao decide nada do
 * servidor — o form continua funcionando sem JS (submit nativo com os defaults).
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('productCard', (unitPriceCents, boxQty) => ({
        variant: 'unit',
        qty: 1,
        unitPriceCents: unitPriceCents,
        boxQty: boxQty,
        // Feedback local do botao apos adicionar (vira "Adicionado" por ~1,6s).
        // No mobile e a unica confirmacao visivel, ja que o drawer nao abre mais
        // a cada item — a contagem vai pra sacola flutuante.
        added: false,
        _addedTimer: null,

        // Encaminha o item pro pedido e, se o servidor confirmar, mostra
        // "Adicionado" no proprio botao. addToCart devolve false em erro
        // (sessao expirada etc.) — nesse caso nao mentimos dizendo que entrou.
        async add(payload) {
            const ok = await this.$store.shop.addToCart(payload);
            if (!ok) {
                return;
            }
            this.added = true;
            clearTimeout(this._addedTimer);
            this._addedTimer = setTimeout(() => { this.added = false; }, 1600);
        },

        step() {
            return this.variant === 'box' ? this.boxQty : 1;
        },

        selectVariant(v) {
            this.variant = v;
            this.qty = Math.min(99, this.qty + (v === 'box' ? this.boxQty : 1));
        },

        increment() {
            this.qty = Math.min(99, this.qty + this.step());
        },

        decrement() {
            this.qty = Math.max(1, this.qty - this.step());
        },

        formattedPrice() {
            const cents = this.unitPriceCents * this.qty;
            const value = (cents / 100).toFixed(2).replace('.', ',');
            const parts = value.split(',');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return 'R$ ' + parts.join(',');
        },
    }));

    // Busca (?q=) e categoria (?cat=) da home, filtradas client-side sobre os
    // cards ja renderizados — sem request novo (ver plano 006, Passo 10).
    Alpine.data('productFilter', (hasFullCatalog) => ({
        // Estado inicial vem do server (?q= e ?cat=), pra que o deep link
        // e o filtro client-side comecem concordando.
        query: new URLSearchParams(location.search).get('q') || '',
        category: new URLSearchParams(location.search).get('cat') || '',
        // false quando a home carregou com ?q=/?cat= (deep link) — o DOM so
        // tem o subconjunto que o servidor filtrou, entao trocar de filtro
        // aqui teria que navegar de verdade, nao reinterpretar um catalogo
        // parcial como se fosse completo (achado na revisao adversarial do
        // /ship: clicar "Todos" vindo de um link de categoria mostrava so a
        // categoria que ja tinha carregado, nao o catalogo inteiro).
        hasFullCatalog: hasFullCatalog,

        matches(el) {
            const name = el.dataset.name || '';
            const cat = el.dataset.category || '';
            const q = this.query.trim().toLowerCase();
            // Os dois filtros COMPOEM (bug conhecido: na UI antiga um anulava o outro).
            return (q === '' || name.includes(q)) && (this.category === '' || cat === this.category);
        },

        onCategoryClick(event, cat) {
            if (!this.hasFullCatalog) {
                return; // deixa o <a href> navegar normal — so o servidor tem os dados certos
            }
            event.preventDefault();
            this.category = cat;
        },

        onSearchSubmit(event) {
            if (this.hasFullCatalog) {
                // x-model ja filtra ao vivo a cada tecla — Enter nao precisa recarregar.
                event.preventDefault();
            }
            // senao, deixa o form submeter normal (GET ?q=... — servidor refiltra certo)
        },

        get visibleCount() {
            if (!this.$refs.grid) {
                return 0;
            }
            const cards = this.$refs.grid.querySelectorAll('[data-name]');
            let count = 0;
            cards.forEach((el) => {
                if (this.matches(el)) {
                    count++;
                }
            });
            return count;
        },
    }));
});
