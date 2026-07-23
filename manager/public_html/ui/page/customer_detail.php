<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');

$customer  = $customer ?? [];
$orders    = $orders ?? [];
$summary   = $summary ?? ['orders_count' => 0, 'paid_cents' => 0, 'first_purchase' => null, 'last_purchase' => null];
$isBlocked  = $isBlocked ?? false;
$blockedIdx = (int)($blockedIdx ?? 0);

$statusLabels = [
    'aguardando_pagamento' => 'Aguardando pagamento',
    'pago'                 => 'Pago',
    'cancelado'            => 'Cancelado',
    'expirado'             => 'Expirado',
];
$statusBadge = [
    'aguardando_pagamento' => 'badge-inactive',
    'pago'                 => 'badge-active',
    'cancelado'            => 'badge-removed',
    'expirado'             => 'badge-removed',
];

$e       = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money   = static fn($cents): string => 'R$ ' . number_format((int)$cents / 100, 2, ',', '.');
$absDate = static fn(?string $dt): string => $dt ? date('d/m/Y H:i', strtotime($dt)) : '—';
$fmtCpf  = static function (?string $raw): string {
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    return strlen($d) === 11
        ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2)
        : ($raw !== null && $raw !== '' ? $raw : '—');
};
$fmtPhone = static function (?string $raw): string {
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    if (strlen($d) === 11) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7, 4);
    }
    if (strlen($d) === 10) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6, 4);
    }
    return $raw !== null && $raw !== '' ? $raw : '—';
};

$name     = (string)($customer['customer_name'] ?? 'Cliente');
$lastIdx  = (int)($orders[0]['idx'] ?? 0);
$cityUf   = trim(($customer['ship_city'] ?? '') . ' / ' . ($customer['ship_uf'] ?? ''), ' /');
?>

<div class="manager-layout" x-data="customersController()">

    <!-- Sidebar -->
    <?php include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header d-flex justify-content-between align-items-start">
            <div>
                <h1>
                    <i class="bi bi-person-lines-fill me-2" aria-hidden="true"></i><?php echo $e($name); ?>
                    <?php if ($isBlocked): ?>
                        <span class="user-badge badge-removed">Bloqueado</span>
                    <?php endif; ?>
                </h1>
                <p>Histórico de compras do cliente.</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (!$isBlocked && $lastIdx > 0): ?>
                    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>"
                        @submit.prevent="confirmBlock($event.target, <?php echo $e(json_encode($name)); ?>)">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="bloquear">
                        <input type="hidden" name="idx" value="<?php echo $lastIdx; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Bloquear cliente">
                            <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Bloquear
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($isBlocked && $blockedIdx > 0): ?>
                    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="desbloquear">
                        <input type="hidden" name="idx" value="<?php echo $lastIdx; ?>">
                        <input type="hidden" name="blocked_idx" value="<?php echo $blockedIdx; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Desbloquear cliente">
                            <i class="bi bi-check-circle me-1" aria-hidden="true"></i> Desbloquear
                        </button>
                    </form>
                <?php endif; ?>
                <a href="<?php echo $e($GLOBALS['customers_url']); ?>" class="btn btn-sm btn-secondary">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Voltar
                </a>
            </div>
        </div>

        <!-- Resumo do cliente -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-person" aria-hidden="true"></i> Dados do Cliente
            </div>
            <div class="content-panel-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">E-mail</span>
                        <span class="detail-value">
                            <?php if (!empty($customer['customer_mail'])): ?>
                                <a href="mailto:<?php echo $e($customer['customer_mail']); ?>"><?php echo $e($customer['customer_mail']); ?></a>
                                <?php else: ?>—<?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Telefone</span>
                        <span class="detail-value"><?php echo $e($fmtPhone($customer['customer_phone'] ?? null)); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">CPF</span>
                        <span class="detail-value"><?php echo $e($fmtCpf($customer['customer_cpf'] ?? null)); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Cidade / UF</span>
                        <span class="detail-value"><?php echo $e($cityUf ?: '—'); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Pedidos</span>
                        <span class="detail-value"><?php echo (int)($summary['orders_count'] ?? 0); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Total pago</span>
                        <span class="detail-value"><?php echo $e($money($summary['paid_cents'] ?? 0)); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Cliente desde</span>
                        <span class="detail-value"><?php echo $e($absDate($summary['first_purchase'] ?? null)); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Última compra</span>
                        <span class="detail-value"><?php echo $e($absDate($summary['last_purchase'] ?? null)); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Histórico de compras — linha do tempo -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Histórico de Compras
            </div>
            <div class="content-panel-body">
                <?php if (empty($orders)): ?>
                    <p class="detail-empty">Nenhum pedido registrado para este cliente.</p>
                <?php else: ?>
                    <ol class="customer-timeline">
                        <?php foreach ($orders as $o):
                            $orderIdx  = (int)$o['idx'];
                            $isShipped = !empty($o['shipped_at']);
                            $badge     = $isShipped ? 'badge-shipped' : ($statusBadge[$o['status']] ?? 'badge-inactive');
                            $label     = $isShipped ? 'Enviado' : ($statusLabels[$o['status']] ?? $o['status']);
                            $orderUrl  = sprintf($GLOBALS['order_url'], $orderIdx);
                        ?>
                            <li class="customer-timeline-item">
                                <span class="customer-timeline-dot" aria-hidden="true"></span>
                                <div class="customer-timeline-body">
                                    <div class="customer-timeline-head">
                                        <a href="<?php echo $e($orderUrl); ?>" class="customer-timeline-order">Pedido #<?php echo $orderIdx; ?></a>
                                        <span class="user-badge <?php echo $badge; ?>"><?php echo $e($label); ?></span>
                                    </div>
                                    <div class="customer-timeline-meta">
                                        <span><i class="bi bi-calendar3 me-1" aria-hidden="true"></i><?php echo $e($absDate($o['created_at'] ?? null)); ?></span>
                                        <span class="customer-timeline-sep" aria-hidden="true">·</span>
                                        <span class="customer-timeline-total"><?php echo $e($money($o['total_cents'] ?? 0)); ?></span>
                                        <?php if (!empty($o['paid_at'])): ?>
                                            <span class="customer-timeline-sep" aria-hidden="true">·</span>
                                            <span>Pago em <?php echo $e($absDate($o['paid_at'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="<?php echo $e($orderUrl); ?>" class="btn btn-sm btn-action-edit customer-timeline-cta" title="Abrir pedido">
                                    <i class="bi bi-eye" aria-hidden="true"></i> Detalhes
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>
