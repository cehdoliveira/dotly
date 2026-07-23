<?php
// track_order.php — página pública "Acompanhar meu pedido" (plano 017)
// Variáveis vindas de track_order_controller: $orders, $searched

// Estágios reais da jornada de um pedido importado, na ordem em que acontecem.
$trackSteps = ['Pedido', 'Pago', 'Enviado'];

/**
 * Traduz uma linha de pedido no estado de exibição da jornada:
 * qual estágio está ativo, tom de cor, rótulo do status e qual bloco
 * de mensagem mostrar (rastreio, aguardar 30 dias, pagamento ou encerrado).
 *
 * @param array<string,mixed> $o
 * @return array{status:string,label:string,tone:string,step:int,flow:bool,shipped:bool,tracking:string}
 */
function trk_state(array $o): array
{
    $status   = (string)($o['status'] ?? '');
    $tracking = trim((string)($o['tracking_code'] ?? ''));
    $shipped  = $tracking !== '' || !empty($o['shipped_at']);

    return match (true) {
        $status === 'cancelado' => ['status' => $status, 'label' => 'Cancelado', 'tone' => 'red',   'step' => -1, 'flow' => false, 'shipped' => false, 'tracking' => ''],
        $status === 'expirado'  => ['status' => $status, 'label' => 'Expirado',  'tone' => 'gray',  'step' => -1, 'flow' => false, 'shipped' => false, 'tracking' => ''],
        $status === 'pago' && $shipped
            => ['status' => $status, 'label' => 'Enviado',              'tone' => 'green', 'step' => 2, 'flow' => true, 'shipped' => true,  'tracking' => $tracking],
        $status === 'pago'
            => ['status' => $status, 'label' => 'Pagamento confirmado', 'tone' => 'teal',  'step' => 1, 'flow' => true, 'shipped' => false, 'tracking' => ''],
        default // aguardando_pagamento
            => ['status' => $status, 'label' => 'Aguardando pagamento', 'tone' => 'amber', 'step' => 0, 'flow' => true, 'shipped' => false, 'tracking' => ''],
    };
}

/** Escape curto para uso repetido na view. */
function trk_e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>

<style>
    .trk-page {
        max-width: 720px;
        margin: 0 auto;
        padding: 3.5rem 1.25rem 4.5rem;
    }

    .trk-head {
        margin-bottom: 2.25rem;
    }

    .trk-eyebrow {
        font-family: var(--font-mono);
        font-size: 0.7rem;
        font-weight: 500;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        color: var(--secondary);
        margin: 0 0 0.75rem;
    }

    .trk-title {
        font-size: clamp(1.85rem, 5vw, 2.6rem);
        font-weight: 800;
        letter-spacing: -0.03em;
        line-height: 1.05;
        color: var(--text-heading);
        margin: 0 0 0.6rem;
    }

    .trk-sub {
        font-size: 0.95rem;
        color: var(--text-muted);
        margin: 0;
        max-width: 34rem;
    }

    /* ---- Formulário de busca ---- */
    .trk-search {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 1.6rem;
        box-shadow: 0 1px 3px rgba(30, 28, 70, 0.05);
    }

    .trk-fields {
        display: grid;
        grid-template-columns: 1fr 10rem;
        gap: 0.9rem;
    }

    @media (max-width: 480px) {
        .trk-fields {
            grid-template-columns: 1fr;
        }
    }

    .trk-field label {
        display: block;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 0.35rem;
    }

    .trk-field input {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 0.7rem 0.85rem;
        font-size: 0.9rem;
        color: var(--text);
        background: var(--bg);
        transition: border-color 0.15s, box-shadow 0.15s;
    }

    .trk-field input:focus {
        outline: none;
        border-color: var(--border-accent);
        box-shadow: 0 0 0 3px var(--focus-ring);
        background: var(--surface);
    }

    .trk-field--code input {
        font-family: var(--font-mono);
        letter-spacing: 0.35em;
        text-align: center;
    }

    .trk-submit {
        margin-top: 1.15rem;
        width: 100%;
        border: none;
        border-radius: 10px;
        background: var(--accent);
        color: #fff;
        font-weight: 700;
        font-size: 0.9rem;
        letter-spacing: 0.01em;
        padding: 0.85rem;
        min-height: 48px;
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .trk-submit:hover {
        background: var(--accent-hover);
    }

    .trk-submit:focus-visible {
        outline: 2px solid var(--focus-ring);
        outline-offset: 2px;
    }

    /* ---- Resultados ---- */
    .trk-results {
        margin-top: 2rem;
        display: flex;
        flex-direction: column;
        gap: 1.15rem;
    }

    .trk-empty {
        margin-top: 2rem;
        border: 1px dashed var(--border-accent);
        border-radius: 14px;
        padding: 1.75rem;
        text-align: center;
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    .trk-empty .bi {
        font-size: 1.6rem;
        color: var(--secondary);
        display: block;
        margin-bottom: 0.5rem;
    }

    .trk-order {
        background: var(--surface);
        border: 1px solid var(--border);
        border-top: 3px solid var(--_tone, var(--accent));
        border-radius: 16px;
        padding: 1.5rem 1.5rem 1.6rem;
        box-shadow: 0 1px 3px rgba(30, 28, 70, 0.05);
    }

    .trk-order[data-tone="amber"] { --_tone: var(--warning); }
    .trk-order[data-tone="teal"]  { --_tone: var(--success); }
    .trk-order[data-tone="green"] { --_tone: var(--success); }
    .trk-order[data-tone="red"]   { --_tone: var(--error); }
    .trk-order[data-tone="gray"]  { --_tone: var(--text-muted); }

    .trk-order-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }

    .trk-id {
        font-family: var(--font-mono);
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--text-muted);
    }

    .trk-id strong {
        color: var(--text);
        font-weight: 500;
    }

    .trk-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        padding: 0.4rem 0.85rem;
        border-radius: 2rem;
        color: var(--_tone);
        background: color-mix(in srgb, var(--_tone) 12%, transparent);
        white-space: nowrap;
    }

    .trk-pill::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--_tone);
    }

    /* ---- Stepper da jornada ---- */
    .trk-track {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        margin: 0 0 1.4rem;
        padding: 0;
        list-style: none;
    }

    .trk-node {
        position: relative;
        text-align: center;
        font-size: 0.72rem;
        color: var(--text-muted);
    }

    .trk-node::before {
        content: "";
        position: absolute;
        top: 9px;
        left: -50%;
        width: 100%;
        height: 2px;
        background: var(--border);
        z-index: 0;
    }

    .trk-node:first-child::before { display: none; }

    .trk-dot {
        position: relative;
        z-index: 1;
        width: 20px;
        height: 20px;
        margin: 0 auto 0.5rem;
        border-radius: 50%;
        background: var(--surface);
        border: 2px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
        color: transparent;
    }

    .trk-node.is-done .trk-dot,
    .trk-node.is-current .trk-dot {
        border-color: var(--_tone);
    }

    .trk-node.is-done .trk-dot {
        background: var(--_tone);
        color: #fff;
    }

    .trk-node.is-done::before {
        background: var(--_tone);
    }

    .trk-node.is-current .trk-dot {
        box-shadow: 0 0 0 4px color-mix(in srgb, var(--_tone) 18%, transparent);
    }

    .trk-node.is-done .trk-label,
    .trk-node.is-current .trk-label {
        color: var(--text);
        font-weight: 600;
    }

    /* ---- Blocos de mensagem ---- */
    .trk-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem 1.75rem;
        padding-top: 1.15rem;
        border-top: 1px solid var(--border);
        font-size: 0.82rem;
        color: var(--text-muted);
    }

    .trk-meta b {
        color: var(--text);
        font-weight: 600;
    }

    .trk-meta .trk-num {
        font-family: var(--font-mono);
    }

    .trk-note {
        margin-top: 1.15rem;
        border-radius: 12px;
        padding: 1rem 1.1rem;
        font-size: 0.85rem;
        line-height: 1.5;
        display: flex;
        gap: 0.7rem;
    }

    .trk-note .bi {
        font-size: 1.1rem;
        flex-shrink: 0;
        margin-top: 0.05rem;
    }

    .trk-note--wait {
        background: var(--row-amber);
        color: #8a5200;
    }

    .trk-note--wait .bi { color: var(--warning); }

    .trk-note--sent {
        background: var(--row-green);
        color: #0d6b60;
    }

    .trk-note--sent .bi { color: var(--success); }

    .trk-note--info {
        background: var(--surface-2);
        color: var(--text);
    }

    .trk-note--info .bi { color: var(--secondary); }

    .trk-note--dead {
        background: color-mix(in srgb, var(--error) 8%, transparent);
        color: #99292a;
    }

    .trk-note--dead .bi { color: var(--error); }

    .trk-note strong { font-weight: 700; }

    /* ---- Código de rastreio (herói quando enviado) ---- */
    .trk-code {
        margin-top: 1.25rem;
        border: 1px solid var(--border-accent);
        border-radius: 14px;
        background: linear-gradient(160deg, var(--accent-dim), var(--surface));
        padding: 1.15rem 1.25rem;
    }

    .trk-code-label {
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--secondary);
        margin-bottom: 0.5rem;
    }

    .trk-code-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .trk-code-value {
        font-family: var(--font-mono);
        font-size: clamp(1.15rem, 4vw, 1.5rem);
        font-weight: 500;
        letter-spacing: 0.08em;
        color: var(--accent);
        word-break: break-all;
    }

    .trk-copy {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border: 1px solid var(--border-accent);
        background: var(--surface);
        color: var(--accent);
        font-size: 0.78rem;
        font-weight: 600;
        padding: 0.5rem 0.85rem;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color 0.15s, color 0.15s;
    }

    .trk-copy:hover {
        background: var(--accent);
        color: #fff;
    }
</style>

<div class="trk-page">

    <?php html_notification_print(); ?>

    <header class="trk-head">
        <p class="trk-eyebrow">Importação · Rastreamento</p>
        <h1 class="trk-title">Onde está o meu pedido?</h1>
        <p class="trk-sub">
            Informe o e-mail e os 4 últimos dígitos do telefone usados na compra
            para ver o andamento de cada pedido.
        </p>
    </header>

    <form class="trk-search" method="POST" action="<?php echo $GLOBALS['track_order_url']; ?>">
        <input type="hidden" name="_csrf_token" value="<?php echo trk_e($_SESSION['_csrf_token'] ?? ''); ?>">

        <div class="trk-fields">
            <div class="trk-field">
                <label for="mail">E-mail da compra</label>
                <input type="email" id="mail" name="mail" autocomplete="email"
                       placeholder="voce@exemplo.com" required>
            </div>
            <div class="trk-field trk-field--code">
                <label for="phone4">4 últimos dígitos</label>
                <input type="text" id="phone4" name="phone4" inputmode="numeric"
                       maxlength="<?php echo track_order_controller::PHONE_SUFFIX_LEN; ?>"
                       placeholder="0000" pattern="\d*" required>
            </div>
        </div>

        <button type="submit" class="trk-submit">Buscar pedidos</button>
    </form>

    <?php if ($searched && empty($orders)): ?>
        <div class="trk-empty">
            <i class="bi bi-search" aria-hidden="true"></i>
            Nenhum pedido encontrado com esses dados.
            Confira o e-mail e os dígitos do telefone usados na compra.
        </div>
    <?php endif; ?>

    <?php if ($searched && !empty($orders)): ?>
        <div class="trk-results">
            <?php foreach ($orders as $o):
                $st         = trk_state($o);
                $tokenShort = strtoupper(substr((string)($o['token'] ?? ''), 0, 8));
                $total      = number_format((int)($o['total_cents'] ?? 0) / 100, 2, ',', '.');
                $createdAt  = !empty($o['created_at']) ? date('d/m/Y', strtotime((string)$o['created_at'])) : '—';

                // Previsão de entrega: até 30 dias após a confirmação do pagamento
                // (ou, na falta dela, após a criação do pedido).
                $baseDate   = $o['paid_at'] ?? $o['created_at'] ?? null;
                $deliverBy  = $baseDate ? date('d/m/Y', strtotime((string)$baseDate . ' +30 days')) : null;
            ?>
                <article class="trk-order" data-tone="<?php echo trk_e($st['tone']); ?>">
                    <div class="trk-order-top">
                        <span class="trk-id">Pedido <strong>#<?php echo trk_e($tokenShort); ?></strong></span>
                        <span class="trk-pill"><?php echo trk_e($st['label']); ?></span>
                    </div>

                    <?php if ($st['flow']): ?>
                        <ol class="trk-track">
                            <?php
                            $lastIdx = array_key_last($trackSteps);
                            foreach ($trackSteps as $i => $stepName):
                                // O último estágio alcançado (Enviado) é estado final: marca
                                // como concluído (check verde), não "atual", pois não há passo seguinte.
                                $done = $i < $st['step'] || ($i === $st['step'] && $i === $lastIdx);
                                $cls  = $done ? 'is-done' : ($i === $st['step'] ? 'is-current' : '');
                            ?>
                                <li class="trk-node <?php echo $cls; ?>">
                                    <span class="trk-dot" aria-hidden="true"><?php echo $done ? '✓' : ''; ?></span>
                                    <span class="trk-label"><?php echo trk_e($stepName); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>

                    <?php if ($st['shipped'] && $st['tracking'] !== ''): ?>
                        <div class="trk-code" x-data="{
                                copied: false,
                                done() { this.copied = true; setTimeout(() => this.copied = false, 1500) },
                                copy(text) {
                                    if (navigator.clipboard && window.isSecureContext) {
                                        navigator.clipboard.writeText(text).then(() => this.done()).catch(() => this.legacy(text));
                                    } else {
                                        this.legacy(text);
                                    }
                                },
                                legacy(text) {
                                    const ta = document.createElement('textarea');
                                    ta.value = text;
                                    ta.style.position = 'fixed';
                                    ta.style.opacity = '0';
                                    document.body.appendChild(ta);
                                    ta.focus();
                                    ta.select();
                                    try { document.execCommand('copy'); this.done(); } catch (e) {}
                                    document.body.removeChild(ta);
                                }
                            }">
                            <div class="trk-code-label">Código de rastreio</div>
                            <div class="trk-code-row">
                                <span class="trk-code-value"><?php echo trk_e($st['tracking']); ?></span>
                                <button type="button" class="trk-copy"
                                        @click="copy('<?php echo trk_e($st['tracking']); ?>')">
                                    <i class="bi" :class="copied ? 'bi-check-lg' : 'bi-clipboard'" aria-hidden="true"></i>
                                    <span x-text="copied ? 'Copiado!' : 'Copiar'">Copiar</span>
                                </button>
                            </div>
                        </div>
                    <?php elseif ($st['shipped']): ?>
                        <div class="trk-note trk-note--sent">
                            <i class="bi bi-truck" aria-hidden="true"></i>
                            <span>
                                <strong>Pedido enviado.</strong>
                                Este envio não possui código de rastreio. Por se tratar de
                                importação, aguarde a entrega em até 30 dias.
                            </span>
                        </div>
                    <?php elseif ($st['status'] === 'pago'): ?>
                        <div class="trk-note trk-note--wait">
                            <i class="bi bi-clock-history" aria-hidden="true"></i>
                            <span>
                                <strong>Pedido em preparação para envio.</strong>
                                O código de rastreio aparece aqui assim que despacharmos.
                                Por se tratar de importação, aguarde até 30 dias para receber
                                <?php if ($deliverBy): ?>
                                    — previsão de entrega até <b><?php echo trk_e($deliverBy); ?></b>.
                                <?php else: ?>
                                    seu pedido.
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php elseif ($st['status'] === 'aguardando_pagamento'): ?>
                        <div class="trk-note trk-note--info">
                            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                            <span>
                                <strong>Aguardando confirmação do pagamento.</strong>
                                Assim que ele for confirmado, iniciamos a preparação do envio.
                            </span>
                        </div>
                    <?php elseif ($st['status'] === 'cancelado'): ?>
                        <div class="trk-note trk-note--dead">
                            <i class="bi bi-x-circle" aria-hidden="true"></i>
                            <span><strong>Pedido cancelado.</strong> Se você não reconhece este cancelamento, entre em contato.</span>
                        </div>
                    <?php elseif ($st['status'] === 'expirado'): ?>
                        <div class="trk-note trk-note--dead">
                            <i class="bi bi-slash-circle" aria-hidden="true"></i>
                            <span><strong>Prazo de pagamento expirado.</strong> Faça um novo pedido para continuar.</span>
                        </div>
                    <?php endif; ?>

                    <div class="trk-meta">
                        <span>Data: <b><?php echo trk_e($createdAt); ?></b></span>
                        <span>Total: <b class="trk-num">R$ <?php echo trk_e($total); ?></b></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
