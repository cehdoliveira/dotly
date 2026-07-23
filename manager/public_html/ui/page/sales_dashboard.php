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

$maxStatusCount = max(1, ...array_values($byStatus));

$gateways        = $gateways ?? [];
$gatewaysTotal   = array_sum(array_column($gateways, 'mtd_cents'));
?>

<div class="manager-layout">

    <!-- Sidebar -->
    <?php include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <h1><i class="bi bi-graph-up me-2" aria-hidden="true"></i>Dashboard de Vendas</h1>
            <p>Olá, <?php echo $userName; ?>. Visão geral das vendas da loja.</p>
        </div>

        <!-- KPIs -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-cash-stack" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-label">Faturamento (mês)</div>
                            <div class="stat-value">R$ <?php echo number_format($kpis['revenue_cents'] / 100, 2, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-receipt" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-label">Pedidos pagos (mês)</div>
                            <div class="stat-value"><?php echo (int)$kpis['paid_orders']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-label">Ticket médio</div>
                            <div class="stat-value">R$ <?php echo number_format($kpis['avg_ticket_cents'] / 100, 2, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="stat-label">Aguardando pagamento</div>
                            <div class="stat-value"><?php echo (int)$kpis['awaiting']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <!-- Pedidos por status (30d) -->
            <div class="col-12 col-lg-6">
                <div class="content-panel h-100">
                    <div class="content-panel-header">
                        <i class="bi bi-bar-chart" aria-hidden="true"></i> Pedidos por status (30 dias)
                    </div>
                    <div class="content-panel-body">
                        <?php foreach ($byStatus as $statusKey => $count): ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between" style="font-size:0.8rem;">
                                    <span><?php echo htmlspecialchars($statusLabels[$statusKey] ?? $statusKey, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span style="color:var(--text-muted);"><?php echo (int)$count; ?></span>
                                </div>
                                <div style="background:var(--border); border-radius:4px; height:6px; overflow:hidden;">
                                    <div style="background:var(--accent); height:100%; width:<?php echo (int)round(((int)$count / $maxStatusCount) * 100); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Top produtos -->
            <div class="col-12 col-lg-6">
                <div class="content-panel h-100">
                    <div class="content-panel-header">
                        <i class="bi bi-trophy" aria-hidden="true"></i> Top 5 produtos (30 dias)
                    </div>
                    <div class="content-panel-body p-0">
                        <?php if (empty($topProd)): ?>
                            <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                                Nenhuma venda no período.
                            </div>
                        <?php else: ?>
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Produto</th>
                                        <th>Qtd. vendida</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topProd as $tp): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($tp['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo (int)$tp['total_qty']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <!-- Últimos pedidos -->
            <div class="col-12 col-lg-8">
                <div class="content-panel h-100 d-flex flex-column">
                    <div class="content-panel-header">
                        <i class="bi bi-clock-history" aria-hidden="true"></i> Últimos pedidos
                    </div>
                    <div class="content-panel-body p-0 flex-grow-1">
                        <?php if (empty($recent)): ?>
                            <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                                Nenhum pedido registrado.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Cliente</th>
                                            <th>Status</th>
                                            <th>Total</th>
                                            <th>Data</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent as $o):
                                            $orderIdx = (int)$o['idx'];
                                            $orderShowUrl = sprintf($GLOBALS['order_url'], $orderIdx);
                                        ?>
                                            <tr>
                                                <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $orderIdx; ?></td>
                                                <td><?php echo htmlspecialchars($o['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <span class="user-badge <?php echo $statusBadge[$o['status']] ?? 'badge-inactive'; ?>">
                                                        <?php echo htmlspecialchars($statusLabels[$o['status']] ?? $o['status'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                                <td style="font-size:0.82rem;">R$ <?php echo number_format((int)($o['total_cents'] ?? 0) / 100, 2, ',', '.'); ?></td>
                                                <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo time_ago($o['created_at'] ?? null); ?></td>
                                                <td>
                                                    <a href="<?php echo htmlspecialchars($orderShowUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-action-edit" title="Ver detalhes">
                                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($recent)): ?>
                        <div class="content-panel-footer d-flex justify-content-end p-3">
                            <a href="<?php echo htmlspecialchars($GLOBALS['orders_url'], ENT_QUOTES, 'UTF-8'); ?>" style="font-size:0.8rem;color:var(--accent);text-decoration:none;font-weight:600;">
                                Ver todos os pedidos <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Faturamento por gateway (mês) -->
            <div class="col-12 col-lg-4">
                <div class="content-panel h-100 d-flex flex-column">
                    <div class="content-panel-header">
                        <i class="bi bi-credit-card-2-back" aria-hidden="true"></i> Gateways de pagamento
                    </div>
                    <div class="content-panel-body flex-grow-1">
                        <?php if (empty($gateways)): ?>
                            <div class="p-2 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                                Nenhum gateway cadastrado.
                            </div>
                        <?php else: ?>
                            <?php foreach ($gateways as $g):
                                $gwEnabled = ($g['enabled'] ?? 'no') === 'yes';
                                $gwMtd     = (int)($g['mtd_cents'] ?? 0);
                                $gwShare   = $gatewaysTotal > 0 ? (int)round($gwMtd / $gatewaysTotal * 100) : 0;
                            ?>
                                <div class="mb-3"<?php echo $gwEnabled ? '' : ' style="opacity:.55;"'; ?>>
                                    <div class="d-flex align-items-center justify-content-between" style="gap:0.5rem;">
                                        <div class="d-flex align-items-center text-truncate" style="gap:0.4rem;min-width:0;">
                                            <span class="text-truncate" style="font-size:0.85rem;font-weight:600;"><?php echo htmlspecialchars($g['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="user-badge <?php echo $gwEnabled ? 'badge-active' : 'badge-inactive'; ?>"><?php echo $gwEnabled ? 'Ativo' : 'Inativo'; ?></span>
                                        </div>
                                        <span style="font-size:0.82rem;font-weight:600;white-space:nowrap;">R$ <?php echo number_format($gwMtd / 100, 2, ',', '.'); ?></span>
                                    </div>
                                    <div style="background:var(--border); border-radius:4px; height:6px; overflow:hidden; margin-top:0.4rem;">
                                        <div style="background:var(--accent); height:100%; width:<?php echo $gwShare; ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($gateways)): ?>
                        <div class="content-panel-footer d-flex align-items-center justify-content-between p-3">
                            <span style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Total pago no mês</span>
                            <span style="font-size:0.9rem;font-weight:700;color:var(--accent);">R$ <?php echo number_format($gatewaysTotal / 100, 2, ',', '.'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </main>
</div>
