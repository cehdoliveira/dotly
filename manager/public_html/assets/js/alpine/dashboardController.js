document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardController', () => ({
        editData: { idx: 0, name: '', mail: '' },
        _modal: null,

        init() {
            this._modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        },

        openEdit(idx, name, mail) {
            this.editData = { idx: idx, name: name, mail: mail };
            this._modal.show();
        },

        // SweetAlert2 injeta html via innerHTML — nome vem do cadastro do
        // usuario (input do admin), nao e HTML confiavel. Mesmo padrao de
        // customersController.js/productsController.js.
        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        async confirmToggle(form, userName, action) {
            const isInativar = action === 'inativar';
            const safeName = this.escapeHtml(userName);
            const result = await Swal.fire({
                title: isInativar ? 'Inativar usuário?' : 'Ativar usuário?',
                html: isInativar
                    ? `O usuário <strong>${safeName}</strong> não conseguirá mais fazer login.`
                    : `O usuário <strong>${safeName}</strong> poderá fazer login novamente.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: isInativar ? 'Inativar' : 'Ativar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: isInativar ? '#f59e0b' : '#4ade80',
            });
            if (result.isConfirmed) form.submit();
        },

        async confirmRemove(form, userName) {
            const result = await Swal.fire({
                title: 'Remover usuário?',
                html: `O usuário <strong>${this.escapeHtml(userName)}</strong> será removido. Esta ação não pode ser desfeita.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remover',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
            });
            if (result.isConfirmed) form.submit();
        },

        async confirmResetPassword(idx, userName) {
            const result = await Swal.fire({
                title: 'Enviar reset de senha?',
                html: `Um link de redefinição será enviado para o e-mail de <strong>${this.escapeHtml(userName)}</strong>.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Enviar',
                cancelButtonText: 'Cancelar',
            });
            if (result.isConfirmed) {
                document.getElementById('resetPasswordIdx').value = idx;
                document.getElementById('resetPasswordForm').submit();
            }
        },
    }));
});
