document.addEventListener('alpine:init', () => {
    Alpine.data('ordersController', () => ({
        // SweetAlert2 injeta html via innerHTML — codigo de rastreio vem de input
        // livre do admin; escapamos por seguranca. Mesmo padrao de customersController.js.
        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        async confirmShip(form) {
            const trackingCode = (form.elements.tracking_code?.value || '').trim();

            const result = trackingCode
                ? await Swal.fire({
                    title: 'Confirmar envio?',
                    html: `O pedido será marcado como enviado com o código de rastreio <strong>${this.escapeHtml(trackingCode)}</strong>.`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Confirmar envio',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#128c7e',
                })
                : await Swal.fire({
                    title: 'Enviar sem código de rastreio?',
                    html: 'Você não informou o código de rastreio. Deseja marcar o pedido como enviado mesmo assim?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Enviar mesmo assim',
                    cancelButtonText: 'Voltar',
                    confirmButtonColor: '#128c7e',
                });

            if (result.isConfirmed) form.submit();
        },
    }));
});
