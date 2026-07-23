/**
 * Checkout Controller - Alpine.js
 * Mascaras nos campos de contato (WhatsApp, CPF, CEP), normalizacao do e-mail,
 * auto-preenchimento de endereco pelo CEP e validacao inline no submit.
 *
 * A validacao roda 100% no cliente ANTES de enviar (form com novalidate): as
 * mesmas regras de checkout_controller::validateCustomer() sao checadas aqui, e
 * um erro so mostra a mensagem inline no campo — nunca recarrega a tela nem faz o
 * cliente redigitar tudo. O servidor revalida em finalize() mesmo assim (defesa
 * em profundidade — o cliente nunca e a fonte da verdade). Sem JS o formulario
 * ainda envia e o servidor valida normalmente.
 *
 * A busca de CEP passa pelo nosso proxy (/checkout/cep/{cep}) porque o CSP
 * (connect-src 'self') nao libera chamar o ViaCEP direto do browser.
 */

/**
 * Porta em JS do validate_cpf() de CommonFunctions.php (modulo 11 da
 * Receita Federal). Mantem os dois algoritmos em sincronia manual — se o
 * PHP mudar, atualize aqui tambem.
 */
function validateCpfChecksum(cpf) {
    const digits = cpf.replace(/\D/g, '');

    if (digits.length !== 11 || /^(\d)\1{10}$/.test(digits)) {
        return false;
    }

    for (let t = 9; t < 11; t++) {
        let sum = 0;
        for (let i = 0; i < t; i++) {
            sum += parseInt(digits[i], 10) * ((t + 1) - i);
        }
        const digit = ((10 * sum) % 11) % 10;
        if (parseInt(digits[t], 10) !== digit) {
            return false;
        }
    }

    return true;
}

document.addEventListener('alpine:init', () => {
    Alpine.data('checkoutForm', () => ({
        cepLoading: false,
        cepError: '',
        submitting: false,
        // Inicializados como '' pra serem reativos (Alpine nao rastreia chaves
        // adicionadas depois num objeto vazio).
        errors: {
            name: '', mail: '', phone: '', cpf: '', zip: '',
            street: '', number: '', district: '', city: '', uf: '',
        },

        init() {
            // Reaplica as mascaras nos valores que o servidor devolveu via old()
            // depois de um bounce (ex.: cliente bloqueado) — senao voltariam sem
            // formatacao.
            this.maskPhone({ target: this.$refs.phone });
            this.maskCpf({ target: this.$refs.cpf });
            this.maskCep({ target: this.$refs.zip });
        },

        onlyDigits(value) {
            return String(value).replace(/\D/g, '');
        },

        clearError(field) {
            this.errors[field] = '';
        },

        // (00) 00000-0000 — aceita fixo (10) e celular (11).
        maskPhone(e) {
            const d = this.onlyDigits(e.target.value).slice(0, 11);
            let out = d;
            if (d.length > 6) {
                const tail = d.length > 10 ? d.slice(2, 7) + '-' + d.slice(7)
                                           : d.slice(2, 6) + '-' + d.slice(6);
                out = '(' + d.slice(0, 2) + ') ' + tail;
            } else if (d.length > 2) {
                out = '(' + d.slice(0, 2) + ') ' + d.slice(2);
            } else if (d.length > 0) {
                out = '(' + d;
            }
            e.target.value = out;
            this.errors.phone = '';
        },

        // 000.000.000-00
        maskCpf(e) {
            const d = this.onlyDigits(e.target.value).slice(0, 11);
            let out = d;
            if (d.length > 9) {
                out = d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6, 9) + '-' + d.slice(9);
            } else if (d.length > 6) {
                out = d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6);
            } else if (d.length > 3) {
                out = d.slice(0, 3) + '.' + d.slice(3);
            }
            e.target.value = out;
            this.errors.cpf = '';
        },

        // 00000-000
        maskCep(e) {
            const d = this.onlyDigits(e.target.value).slice(0, 8);
            e.target.value = d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d;
            this.errors.zip = '';
        },

        normalizeEmail(e) {
            e.target.value = e.target.value.trim().toLowerCase();
            this.errors.mail = '';
        },

        // Base do site (mesma origem do link da marca no header) — evita depender
        // do path atual pra montar a URL do proxy de CEP.
        baseUrl() {
            const brand = document.querySelector('.ss-brand');
            return brand ? brand.getAttribute('href') : '/';
        },

        // Dispara no blur/completar do CEP: busca no proxy e preenche o endereco.
        async lookupCep(e) {
            const cep = this.onlyDigits(e.target.value);
            this.cepError = '';
            if (cep.length !== 8) {
                return;
            }

            this.cepLoading = true;
            try {
                const res = await fetch(this.baseUrl() + 'checkout/cep/' + cep, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await res.json().catch(() => null);

                if (!res.ok || data === null || data.error) {
                    this.cepError = (data && data.error) || 'Não foi possível buscar o CEP. Preencha o endereço manualmente.';
                    return;
                }

                if (data.street) { this.$refs.street.value = data.street; this.errors.street = ''; }
                if (data.district) { this.$refs.district.value = data.district; this.errors.district = ''; }
                if (data.city) { this.$refs.city.value = data.city; this.errors.city = ''; }
                if (data.uf) { this.$refs.uf.value = data.uf; this.errors.uf = ''; }

                // Rua/bairro/cidade/UF ja vieram — leva o cliente direto pro que
                // ele ainda precisa digitar.
                this.$refs.number.focus();
            } catch (err) {
                this.cepError = 'Não foi possível buscar o CEP. Preencha o endereço manualmente.';
            } finally {
                this.cepLoading = false;
            }
        },

        // Espelha checkout_controller::validateCustomer(). Preenche this.errors e
        // devolve true se tudo passou. O regex de e-mail exige TLD (dominio.algo)
        // pra bater com o FILTER_VALIDATE_EMAIL do servidor — sem isso "a@b"
        // passava aqui e so o servidor recusava (bounce + perda dos dados).
        validate() {
            const v = (ref) => this.$refs[ref].value.trim();
            const digits = (ref) => this.onlyDigits(this.$refs[ref].value);

            this.errors.name = v('name') === '' ? 'Informe seu nome completo.' : '';
            this.errors.mail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('mail')) ? '' : 'Informe um e-mail válido.';

            const phone = digits('phone');
            this.errors.phone = (phone.length === 10 || phone.length === 11) ? '' : 'Informe o WhatsApp com DDD.';
            this.errors.cpf = validateCpfChecksum(digits('cpf')) ? '' : 'Informe um CPF válido.';
            this.errors.zip = digits('zip').length === 8 ? '' : 'Informe um CEP válido.';

            this.errors.street = v('street') === '' ? 'Informe a rua.' : '';
            this.errors.number = v('number') === '' ? 'Informe o número.' : '';
            this.errors.district = v('district') === '' ? 'Informe o bairro.' : '';
            this.errors.city = v('city') === '' ? 'Informe a cidade.' : '';
            this.errors.uf = v('uf') === '' ? 'Selecione o estado.' : '';

            return Object.values(this.errors).every((msg) => msg === '');
        },

        // Bloqueia o envio se algo estiver invalido, foca o primeiro campo com
        // erro (o browser rola ate ele) e NAO recarrega a pagina — os dados ficam.
        // So quando tudo passa deixa enviar e trava o botao contra duplo clique.
        onSubmit(e) {
            if (!this.validate()) {
                e.preventDefault();
                const first = Object.keys(this.errors).find((k) => this.errors[k]);
                if (first && this.$refs[first]) {
                    this.$refs[first].focus();
                }
                return;
            }
            this.submitting = true;
        },
    }));
});
