document.addEventListener('alpine:init', () => {
    // initialCategories vem do PHP no x-data (ui/page/products.php): a mesma
    // lista que renderiza os <select> de categoria dos modais de produto. Toda
    // mutacao no modal de categorias substitui este array pela lista que o
    // endpoint devolve, entao os selects e a listagem do pop-up nunca divergem.
    //
    // defaultCategoryId vem do mesmo x-data (products_controller::index() ->
    // loadCategoryLists() -> resolveDefaultCategoryId(), pos-023b/023c): o idx
    // da categoria "Geral", pra pre-selecionar o <select> do modal de criar
    // produto. Guardado a parte (nao so lido uma vez em createCategoryId) para
    // openCreate() conseguir resetar a selecao se o usuario abrir o modal,
    // trocar a categoria, cancelar, e abrir de novo.
    Alpine.data('productsController', (initialCategories = [], defaultCategoryId = 0) => ({
        editData: {
            idx: 0, name: '', slug: '', categoriesId: 0, dosage: '',
            priceUnit: '', stock: 0,
        },
        categories: initialCategories,
        defaultCategoryId: defaultCategoryId,
        createCategoryId: defaultCategoryId,
        newCategoryName: '',
        editingCategoryId: 0,
        editingCategoryName: '',
        categoriesError: '',
        categoriesLoading: false,
        _editModal: null,
        _createModal: null,
        _categoriesModal: null,

        init() {
            this._editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
            this._createModal = new bootstrap.Modal(document.getElementById('createProductModal'));
            this._categoriesModal = new bootstrap.Modal(document.getElementById('manageCategoriesModal'));
        },

        // openCreate ganha uma linha (reconciliado): reseta createCategoryId pro
        // default a cada abertura, senao uma selecao trocada e cancelada fica
        // "grudada" na proxima vez que o modal abrir.
        openCreate() {
            this.createCategoryId = this.defaultCategoryId;
            this._createModal.show();
        },

        openEdit(idx, name, slug, categoriesId, dosage, priceUnit, stock) {
            this.editData = {
                idx: idx, name: name, slug: slug, categoriesId: categoriesId, dosage: dosage,
                priceUnit: priceUnit, stock: stock,
            };
            this._editModal.show();
        },

        // Mascara do preco (blur): interpreta o valor como REAIS e formata com 2
        // casas em pt-BR (ex.: "70" -> "70,00", "70,5" -> "70,50"). Mesmo criterio
        // do parse no products_controller. dispatchEvent sincroniza o x-model do
        // form de editar; no form de criar (sem x-model) so ajusta o value.
        formatPrice(el) {
            const clean = String(el.value).replace(/[^\d,.]/g, '');
            if (clean === '') { return; }
            const reais = parseFloat(clean.replace(/\./g, '').replace(',', '.'));
            if (isNaN(reais)) { return; }
            el.value = reais.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            el.dispatchEvent(new Event('input'));
        },

        // SweetAlert2 injeta html via innerHTML — nome vem do cadastro do
        // produto (input do usuario), nao e HTML confiavel. Mesmo padrao de
        // site/shopController.js.
        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        async confirmRemove(form, productName) {
            const result = await Swal.fire({
                title: 'Remover produto?',
                html: `O produto <strong>${this.escapeHtml(productName)}</strong> será removido. Esta ação não pode ser desfeita.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remover',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
            });
            if (result.isConfirmed) form.submit();
        },

        categoriesUrl() {
            return document.getElementById('categories-endpoint').value;
        },

        csrfToken() {
            return document.getElementById('categories-csrf').value;
        },

        openCategories() {
            this.categoriesError = '';
            this.cancelEditCategory();
            this._categoriesModal.show();
        },

        // Toda mutacao passa por aqui. O endpoint responde SEMPRE com a lista
        // inteira ({categories, csrf_token, error}) — o cliente nunca remenda o
        // array local, so troca pelo que o servidor devolveu. Isso e o que faz a
        // categoria recem-criada aparecer na hora tanto no pop-up quanto nos
        // <select> dos modais de produto.
        async postCategories(payload) {
            this.categoriesLoading = true;
            this.categoriesError = '';
            try {
                const body = new URLSearchParams({
                    ...payload,
                    _csrf_token: this.csrfToken(),
                });

                const res = await fetch(this.categoriesUrl(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                });

                const type = res.headers.get('content-type') || '';
                // validate_csrf() falhando responde 302 HTML, nao JSON — mesmo
                // tratamento do shopController.js do site.
                if (!type.includes('application/json')) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Não foi possível concluir',
                        text: 'Sua sessão pode ter expirado. Recarregue a página e tente de novo.',
                    });
                    return false;
                }

                const data = await res.json();

                if (Array.isArray(data.categories)) {
                    this.categories = data.categories;
                }
                // O token da sessao e consumido a cada POST valido: sem trocar o
                // do formulario, a SEGUNDA acao do modal falharia.
                if (data.csrf_token) {
                    document.getElementById('categories-csrf').value = data.csrf_token;
                }
                if (data.error) {
                    this.categoriesError = data.error;
                    return false;
                }
                return true;
            } finally {
                this.categoriesLoading = false;
            }
        },

        async addCategory() {
            const name = this.newCategoryName.trim();
            if (name === '') {
                this.categoriesError = 'Informe o nome da categoria.';
                return;
            }
            const ok = await this.postCategories({ action: 'criar', name: name });
            if (ok) this.newCategoryName = '';
        },

        startEditCategory(cat) {
            this.categoriesError = '';
            this.editingCategoryId = cat.idx;
            this.editingCategoryName = cat.name;
        },

        cancelEditCategory() {
            this.editingCategoryId = 0;
            this.editingCategoryName = '';
        },

        async saveCategory() {
            const name = this.editingCategoryName.trim();
            if (name === '') {
                this.categoriesError = 'Informe o nome da categoria.';
                return;
            }
            const ok = await this.postCategories({
                action: 'editar',
                idx: this.editingCategoryId,
                name: name,
            });
            if (ok) this.cancelEditCategory();
        },

        // Swal injeta html via innerHTML — o nome vem do cadastro do usuario,
        // nao e HTML confiavel. Mesmo padrao de confirmRemove().
        async confirmRemoveCategory(cat) {
            const result = await Swal.fire({
                title: 'Remover categoria?',
                html: `A categoria <strong>${this.escapeHtml(cat.name)}</strong> será removida.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remover',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
                focusCancel: true,
            });
            if (!result.isConfirmed) return;

            await this.postCategories({ action: 'remover', idx: cat.idx });
        },
    }));
});
