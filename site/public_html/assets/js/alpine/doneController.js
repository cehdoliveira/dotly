/**
 * Done Controller - Alpine.js
 * Tela 5 "Acompanhar meu pedido": quando o cliente volta do gateway (InfinitePay)
 * antes do webhook confirmar, a pagina renderiza "aguardando". Este polling
 * consulta o /status a cada 5s e recarrega a pagina quando o pedido sai de
 * 'aguardando_pagamento' — deixando o PHP re-renderizar o estado certo (pago/
 * expirado/cancelado) sem o cliente ter que atualizar na mao. Para depois de
 * 30 min para nao pollar pra sempre em abas esquecidas abertas.
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('orderStatus', (statusUrl) => ({
        pollTimer: null,
        pollStartedAt: Date.now(),
        maxPollMs: 30 * 60 * 1000,

        init() {
            this.poll();
            this.pollTimer = setInterval(() => this.poll(), 5000);
        },

        async poll() {
            if (Date.now() - this.pollStartedAt > this.maxPollMs) {
                this.stop();
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
                // Qualquer status resolvido (pago/expirado/cancelado) muda a tela:
                // recarrega para o PHP renderizar o bloco correto.
                if (data.status && data.status !== 'aguardando_pagamento') {
                    this.stop();
                    window.location.reload();
                }
            } catch (e) {
                // Falha de rede/timeout: tenta de novo no proximo ciclo.
            }
        },

        stop() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },
    }));
});
