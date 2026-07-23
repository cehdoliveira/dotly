/**
 * Payment Controller - Alpine.js
 * Tela 4 "Pague com PIX": contagem regressiva ate expires_at, polling do
 * status a cada 5s (para depois de 30 min ou quando expira/paga), e copiar
 * o codigo PIX com fallback para WebViews de banco antigas.
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('paymentStatus', (statusUrl, doneUrl, expiresAtIso) => ({
        expiresAt: new Date(expiresAtIso).getTime(),
        expired: false,
        finalCheckStarted: false,
        copied: false,
        countdownLabel: '--:--',
        pollTimer: null,
        countdownTimer: null,
        pollStartedAt: Date.now(),
        maxPollMs: 30 * 60 * 1000,

        init() {
            this.updateCountdown();
            this.countdownTimer = setInterval(() => this.updateCountdown(), 1000);
            this.poll();
            this.pollTimer = setInterval(() => this.poll(), 5000);
        },

        updateCountdown() {
            const remainingMs = this.expiresAt - Date.now();

            if (remainingMs <= 0) {
                this.countdownLabel = '00:00';
                if (!this.finalCheckStarted) {
                    this.finalCheckStarted = true;
                    this.finalCheck();
                }
                return;
            }

            const totalSeconds = Math.floor(remainingMs / 1000);
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;
            this.countdownLabel = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        },

        /**
         * Ultima checagem de status antes de declarar expirado — cobre o caso
         * do webhook confirmar o pagamento nos segundos finais, quando o
         * relogio do cliente (skew) ou a rede ja cruzou expires_at mas o
         * servidor ainda nao.
         */
        async finalCheck() {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 4000);
                const response = await fetch(statusUrl, { signal: controller.signal });
                clearTimeout(timeoutId);

                if (response.ok) {
                    const data = await response.json();
                    if (data.status === 'pago') {
                        this.stopTimers();
                        window.location.href = doneUrl;
                        return;
                    }
                }
            } catch (e) {
                // Sem resposta: segue pro estado expirado mesmo assim.
            }

            this.expired = true;
            this.stopTimers();
        },

        async poll() {
            if (this.expired) {
                this.stopTimers();
                return;
            }

            if (Date.now() - this.pollStartedAt > this.maxPollMs) {
                this.stopTimers();
                return;
            }

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 4000);

            try {
                const response = await fetch(statusUrl, { signal: controller.signal });
                clearTimeout(timeoutId);

                if (!response.ok) {
                    return;
                }

                const data = await response.json();
                if (data.status === 'pago') {
                    this.stopTimers();
                    window.location.href = doneUrl;
                }
            } catch (e) {
                // Falha de rede/timeout: tenta de novo no proximo ciclo de polling.
            }
        },

        stopTimers() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        },

        copyCode() {
            const field = this.$refs.pixCode;
            const text = field.value;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => this.flashCopied());
                return;
            }

            // Fallback para WebViews de banco sem Clipboard API.
            field.removeAttribute('readonly');
            field.select();
            try {
                document.execCommand('copy');
                this.flashCopied();
            } catch (e) {
                // Silencioso: o texto continua selecionado no textarea para copia manual.
            }
            field.setAttribute('readonly', 'readonly');
        },

        flashCopied() {
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        },
    }));
});
