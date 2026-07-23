/**
 * Login Controller - Alpine.js
 * Gerencia validação e submit do formulário de login
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('loginController', () => ({
        // Estado do formulário
        formData: {
            login: '',
            password: '',
            remember: false,
        },

        // Controle de estado
        isSubmitting: false,
        showPassword: false,

        // Erros de validação
        errors: {},

        /**
         * Valida o formulário antes do submit
         */
        validate() {
            this.errors = {};
            let isValid = true;

            // Validar login
            if (!this.formData.login || this.formData.login.trim().length < 3) {
                this.errors.login = 'Login deve ter pelo menos 3 caracteres';
                isValid = false;
            }

            // Validar senha
            if (!this.formData.password || this.formData.password.length < 4) {
                this.errors.password = 'Senha deve ter pelo menos 4 caracteres';
                isValid = false;
            }

            return isValid;
        },

        /**
         * Processa o submit do formulário
         */
        async handleSubmit(event) {
            // Validar campos
            if (!this.validate()) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'Por favor, corrija os erros no formulário',
                    confirmButtonText: 'Ok',
                });
                return;
            }

            // Bloquear múltiplos submits
            if (this.isSubmitting) {
                return;
            }

            this.isSubmitting = true;

            // Remover mensagens de erro anteriores
            this.errors = {};

            // Submit do formulário nativo (POST para o backend PHP)
            // O formulário já tem method="POST" e action definidos
            event.target.submit();
        },

        /**
         * Limpa o formulário
         */
        resetForm() {
            this.formData = {
                login: '',
                password: '',
                remember: false,
            };
            this.errors = {};
            this.isSubmitting = false;
            this.showPassword = false;
        },

        /**
         * Inicialização
         */
        init() {
            // Focar no campo de login após carregamento
            this.$nextTick(() => {
                const loginInput = document.getElementById('login');
                if (loginInput) {
                    loginInput.focus();
                }
            });
        },
    }));
});
