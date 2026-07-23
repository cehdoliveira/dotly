document.addEventListener('alpine:init', () => {
    Alpine.data('productsController', () => ({
        editData: {
            idx: 0, name: '', slug: '', category: '', dosage: '',
            priceUnit: '', stock: 0,
        },
        _editModal: null,
        _createModal: null,

        init() {
            this._editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
            this._createModal = new bootstrap.Modal(document.getElementById('createProductModal'));
        },

        openCreate() {
            this._createModal.show();
        },

        openEdit(idx, name, slug, category, dosage, priceUnit, stock) {
            this.editData = {
                idx: idx, name: name, slug: slug, category: category, dosage: dosage,
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
    }));
});
