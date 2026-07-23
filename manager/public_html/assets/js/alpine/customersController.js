document.addEventListener('alpine:init', () => {
    Alpine.data('customersController', () => ({
        // SweetAlert2 injeta html via innerHTML — nome vem do checkout publico
        // (input do cliente), nao e HTML confiavel. Mesmo padrao de productsController.js.
        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        async confirmBlock(form, customerName) {
            const result = await Swal.fire({
                title: 'Bloquear cliente?',
                html: `<strong>${this.escapeHtml(customerName)}</strong> não conseguirá mais concluir pedidos no checkout. O bloqueio vale para o e-mail, CPF e telefone deste cliente.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Bloquear',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
            });
            if (result.isConfirmed) form.submit();
        },

        async confirmUnblock(form, customerName) {
            const result = await Swal.fire({
                title: 'Desbloquear cliente?',
                html: `<strong>${this.escapeHtml(customerName)}</strong> voltará a concluir pedidos no checkout. O bloqueio de e-mail, CPF e telefone deste cliente será removido.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Desbloquear',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#128c7e',
            });
            if (result.isConfirmed) form.submit();
        },
    }));
});
