<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');

$statusLabels = [
    'aguardando_pagamento' => 'Aguardando pagamento',
    'pago'                 => 'Pago',
    'cancelado'             => 'Cancelado',
    'expirado'              => 'Expirado',
];
$statusBadge = [
    'aguardando_pagamento' => 'badge-inactive',
    'pago'                 => 'badge-active',
    'cancelado'             => 'badge-removed',
    'expirado'              => 'badge-removed',
];

$chargeStatusLabels = [
    'pendente' => 'Pendente',
    'pago'     => 'Pago',
    'expirado' => 'Expirado',
    'erro'     => 'Erro',
];
$chargeStatusBadge = [
    'pendente' => 'badge-inactive',
    'pago'     => 'badge-active',
    'expirado' => 'badge-removed',
    'erro'     => 'badge-removed',
];

$charge    = $order['charges_attach'][0] ?? null;
$items     = $order['items_attach'] ?? [];
$csrfToken = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');

$isShipped = !empty($order['shipped_at']);

// Formatadores de exibicao — inline, mesmo padrao do number_format ja usado na
// tela. Nunca alteram o dado armazenado (so digitos), apenas a apresentacao.
$money   = static fn($cents): string => 'R$ ' . number_format((int)$cents / 100, 2, ',', '.');
$absDate = static fn(?string $dt): ?string => $dt ? date('d/m/Y H:i', strtotime($dt)) : null;
$fmtCpf  = static function (?string $raw): string {
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    return strlen($d) === 11
        ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2)
        : ($raw !== null && $raw !== '' ? $raw : '—');
};
$fmtCep = static function (?string $raw): string {
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5, 3) : ($raw ?: '—');
};
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$createdAbs = $absDate($order['created_at'] ?? null);
?>

<div class="manager-layout">

    <!-- Sidebar -->
    <?php include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content order-detail-page">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header order-detail-header">
            <div>
                <h1>
                    <i class="bi bi-receipt me-2" aria-hidden="true"></i>Pedido #<?php echo (int)$order['idx']; ?>
                    <span class="user-badge <?php echo $statusBadge[$order['status']] ?? 'badge-inactive'; ?>">
                        <?php echo $e($statusLabels[$order['status']] ?? $order['status']); ?>
                    </span>
                </h1>
                <p class="order-meta">
                    <span class="order-meta-token" title="Token do pedido"><i class="bi bi-hash" aria-hidden="true"></i><?php echo $e($order['token'] ?? ''); ?></span>
                    <?php if ($createdAbs): ?>
                        <span class="order-meta-sep" aria-hidden="true">·</span>
                        <span title="Data da compra"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Criado em <?php echo $e($createdAbs); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo $GLOBALS['orders_url']; ?>" class="btn btn-sm btn-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Voltar
            </a>
        </div>

        <!-- Comprador + endereço lado a lado -->
        <div class="detail-columns">

            <!-- Dados do comprador -->
            <div class="content-panel">
                <div class="content-panel-header">
                    <i class="bi bi-person" aria-hidden="true"></i> Dados do Comprador
                </div>
                <div class="content-panel-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Nome</span>
                            <span class="detail-value"><?php echo $e($order['customer_name'] ?? '—'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">CPF</span>
                            <span class="detail-value"><?php echo $e($fmtCpf($order['customer_cpf'] ?? null)); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">E-mail</span>
                            <span class="detail-value">
                                <?php if (!empty($order['customer_mail'])): ?>
                                    <a href="mailto:<?php echo $e($order['customer_mail']); ?>"><?php echo $e($order['customer_mail']); ?></a>
                                    <?php else: ?>—<?php endif; ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Telefone</span>
                            <span class="detail-value"><?php echo $e($order['customer_phone'] ?? '—'); ?></span>
                        </div>
                    </div>

                    <div class="detail-subhead"><i class="bi bi-qr-code" aria-hidden="true"></i> Pagamento</div>
                    <?php if ($charge): ?>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Gateway</span>
                                <span class="detail-value">
                                    <?php if (!empty($gatewayName)): ?>
                                        <span class="gateway-tag"><?php echo $e($gatewayName); ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Status da cobrança</span>
                                <span class="detail-value">
                                    <span class="user-badge <?php echo $chargeStatusBadge[$charge['status']] ?? 'badge-inactive'; ?>">
                                        <?php echo $e($chargeStatusLabels[$charge['status']] ?? $charge['status']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Valor da cobrança</span>
                                <span class="detail-value"><?php echo $e($money($charge['amount_cents'] ?? 0)); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">ID no gateway</span>
                                <span class="detail-value detail-value--mono"><?php echo $e($charge['gateway_charge_id'] ?: '—'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Expira em</span>
                                <span class="detail-value"><?php echo $e($absDate($charge['expires_at'] ?? null) ?? '—'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Pago em</span>
                                <span class="detail-value"><?php echo $e($absDate($charge['paid_at'] ?? null) ?? '—'); ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="detail-empty">Nenhuma cobrança PIX gerada para este pedido.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Endereço de entrega -->
            <div class="content-panel">
                <div class="content-panel-header content-panel-header--action">
                    <span><i class="bi bi-geo-alt" aria-hidden="true"></i> Endereço de Entrega</span>
                    <?php if (!$isShipped): ?>
                        <a href="<?php echo $e(sprintf($GLOBALS['order_label_url'], (int)$order['idx'])); ?>"
                            class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                            <i class="bi bi-tag me-1" aria-hidden="true"></i> Gerar etiqueta de envio
                        </a>
                    <?php endif; ?>
                </div>
                <div class="content-panel-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Logradouro</span>
                            <span class="detail-value"><?php echo $e(trim(($order['ship_street'] ?? '') . ', ' . ($order['ship_number'] ?? ''), ', ')) ?: '—'; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Complemento</span>
                            <span class="detail-value"><?php echo $e($order['ship_complement'] ?: '—'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Bairro</span>
                            <span class="detail-value"><?php echo $e($order['ship_district'] ?: '—'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Cidade / UF</span>
                            <span class="detail-value"><?php echo $e(trim(($order['ship_city'] ?? '') . ' / ' . ($order['ship_uf'] ?? ''), ' /')) ?: '—'; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">CEP</span>
                            <span class="detail-value"><?php echo $e($fmtCep($order['ship_zip'] ?? null)); ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /detail-columns -->

        <!-- Itens do pedido -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-basket" aria-hidden="true"></i> Itens do Pedido
            </div>
            <div class="content-panel-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 order-items-table">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th>Variante</th>
                                <th class="text-end">Qtd</th>
                                <th class="text-end">Preço unit.</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="5" class="text-center order-items-empty">Nenhum item registrado neste pedido.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?php echo $e($item['product_name'] ?? '—'); ?></td>
                                        <td><span class="variant-tag"><?php echo $item['variant'] === 'box' ? 'Caixa' : 'Unidade'; ?></span></td>
                                        <td class="text-end"><?php echo (int)($item['qty'] ?? 0); ?></td>
                                        <td class="text-end"><?php echo $e($money($item['unit_price_cents'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo $e($money($item['line_total_cents'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <?php if (isset($order['subtotal_cents'])): ?>
                                <tr class="order-total-row order-total-row--muted">
                                    <td colspan="4" class="text-end">Subtotal dos itens</td>
                                    <td class="text-end"><?php echo $e($money($order['subtotal_cents'])); ?></td>
                                </tr>
                                <?php if ((int)($order['fee_percent_cents'] ?? 0) > 0): ?>
                                    <?php // Percentual efetivo do pedido (nao a taxa atual em settings, que pode ter mudado desde a compra).
                                    $feePercentLabel = (int)($order['subtotal_cents'] ?? 0) > 0
                                        ? rtrim(rtrim(number_format((int)$order['fee_percent_cents'] / (int)$order['subtotal_cents'] * 100, 2, ',', '.'), '0'), ',')
                                        : ''; ?>
                                    <tr class="order-total-row order-total-row--muted">
                                        <td colspan="4" class="text-end">Taxa de serviço<?php echo $feePercentLabel !== '' ? ' (' . $e($feePercentLabel) . '%)' : ''; ?></td>
                                        <td class="text-end"><?php echo $e($money($order['fee_percent_cents'])); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ((int)($order['fee_fixed_cents'] ?? 0) > 0): ?>
                                    <tr class="order-total-row order-total-row--muted">
                                        <td colspan="4" class="text-end">Taxa fixa</td>
                                        <td class="text-end"><?php echo $e($money($order['fee_fixed_cents'])); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ((int)($order['fee_infinity_cents'] ?? 0) > 0): ?>
                                    <tr class="order-total-row order-total-row--muted">
                                        <td colspan="4" class="text-end">Taxa Infinity</td>
                                        <td class="text-end"><?php echo $e($money($order['fee_infinity_cents'])); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endif; ?>
                            <tr class="order-total-row order-total-row--grand">
                                <td colspan="4" class="text-end"><strong>Total do pedido</strong></td>
                                <td class="text-end"><strong><?php echo $e($money($order['total_cents'] ?? 0)); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Envio -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-truck" aria-hidden="true"></i> Envio
            </div>
            <div class="content-panel-body">
                <?php if ($isShipped): ?>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Enviado em</span>
                            <span class="detail-value"><?php echo $e($absDate($order['shipped_at']) ?? '—'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Código de rastreio</span>
                            <span class="detail-value detail-value--mono"><?php echo $e($order['tracking_code'] ?: 'Sem código de rastreio'); ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <form method="POST" action="<?php echo sprintf($GLOBALS['order_ship_url'], (int)$order['idx']); ?>" class="row g-3" x-data="ordersController()" @submit.prevent="confirmShip($event.target)">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="col-12 col-md-8">
                            <label class="form-label detail-label" for="tracking_code">Código de rastreio (opcional)</label>
                            <input type="text" id="tracking_code" name="tracking_code" class="form-control" maxlength="<?php echo orders_controller::TRACKING_CODE_MAX_LENGTH; ?>" autocomplete="off">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-truck me-1" aria-hidden="true"></i> Envio realizado
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>
