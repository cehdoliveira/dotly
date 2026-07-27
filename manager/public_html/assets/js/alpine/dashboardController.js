document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardController', () => ({
        editData: { idx: 0, name: '', mail: '' },
        _modal: null,
        cepError: '',
        cepLoading: false,

        init() {
            this._modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        },

        onlyDigits(str) {
            return String(str).replace(/\D/g, '');
        },

        // 00000-000
        maskCep(e) {
            const d = this.onlyDigits(e.target.value).slice(0, 8);
            e.target.value = d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d;
            this.cepError = '';
        },

        cepUrl() {
            return document.getElementById('cep-endpoint').value;
        },

        // Dispara no blur do CEP: busca no proxy e preenche o endereco. Fail-soft:
        // qualquer erro so mostra cepError, os campos continuam editaveis.
        async lookupCep(e) {
            const cep = this.onlyDigits(e.target.value);
            this.cepError = '';
            if (cep.length !== 8) {
                return;
            }

            this.cepLoading = true;
            try {
                const res = await fetch(this.cepUrl() + cep, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json().catch(() => null);

                if (!res.ok || data === null || data.error) {
                    this.cepError = (data && data.error) || 'Não foi possível buscar o CEP. Preencha o endereço manualmente.';
                    return;
                }

                if (data.street) this.$refs.senderStreet.value = data.street;
                if (data.district) this.$refs.senderDistrict.value = data.district;
                if (data.city) this.$refs.senderCity.value = data.city;
                if (data.uf) this.$refs.senderUf.value = data.uf;

                this.$refs.senderNumber.focus();
            } catch (err) {
                this.cepError = 'Não foi possível buscar o CEP. Preencha o endereço manualmente.';
            } finally {
                this.cepLoading = false;
            }
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

        openGatewayCreds(idx) {
            const el = document.getElementById('gatewayCredsModal' + idx);
            if (el) new bootstrap.Modal(el).show();
        },

        async confirmClearCreds(form, gatewayName) {
            const result = await Swal.fire({
                title: 'Remover credenciais?',
                html: `As chaves de <strong>${this.escapeHtml(gatewayName)}</strong> serão apagadas e o gateway será desabilitado.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remover',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
                focusCancel: true,
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
