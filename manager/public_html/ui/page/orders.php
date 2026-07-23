<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');

$statusLabels = [
    'aguardando_pagamento' => 'Aguardando pagamento',
    'pago'                 => 'Pago',
    'cancelado'             => 'Cancelado',
    'expirado'              => 'Expirado',
    // Pseudo-opcao do filtro: "Enviado" nao e status de pagamento, e derivado de
    // shipped_at (ver orders_controller::SHIPPED_FILTER). So aparece no dropdown.
    'enviado'              => 'Enviado',
];
$statusBadge = [
    'aguardando_pagamento' => 'badge-inactive',
    'pago'                 => 'badge-active',
    'cancelado'             => 'badge-removed',
    'expirado'              => 'badge-removed',
    'enviado'              => 'badge-shipped',
];
$currentStatuses      = $currentStatuses ?? [];
$currentCpf           = $currentCpf ?? '';
$currentPhone         = $currentPhone ?? '';
$currentDateStart     = $currentDateStart ?? '';
$currentDateEnd       = $currentDateEnd ?? '';
$currentGateway       = (int)($currentGateway ?? 0);
$gateways             = $gateways ?? [];
$phoneFilterMinDigits = $phoneFilterMinDigits ?? 4;

// Ordenacao clicavel do cabecalho. As chaves batem com orders_controller::SORTABLE
// (o controller valida qual coluna vira ORDER BY); aqui so montamos os links.
$currentSort = $currentSort ?? 'criado';
$currentDir  = (($currentDir ?? 'desc') === 'asc') ? 'asc' : 'desc';
$sortColumns = [
    'id'      => '#',
    'token'   => 'Token',
    'cliente' => 'Cliente',
    'status'  => 'Status',
    'gateway' => 'Gateway',
    'total'   => 'Total',
    'criado'  => 'Criado em',
    'pago'    => 'Pago em',
];

// Filtros propagados para exportar/paginar. Escalares via array_filter; status
// como array (set_url serializa como status[]=a&status[]=b).
$filterParams = array_filter([
    'cpf'         => $currentCpf,
    'telefone'    => $currentPhone,
    'data_inicio' => $currentDateStart,
    'data_fim'    => $currentDateEnd,
], static fn($v) => $v !== '');
if (!empty($currentStatuses)) {
    $filterParams['status'] = $currentStatuses;
}
if ($currentGateway > 0) {
    $filterParams['gateway'] = $currentGateway;
}

// Rotulo do botao do multi-select de status.
$statusCount = count($currentStatuses);
if ($statusCount === 0) {
    $statusToggleLabel = 'Todos os status';
} elseif ($statusCount === 1) {
    $statusToggleLabel = $statusLabels[$currentStatuses[0]] ?? $currentStatuses[0];
} else {
    $statusToggleLabel = $statusCount . ' status selecionados';
}
?>

<div class="manager-layout">

    <!-- Sidebar -->
    <?php include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <div>
                <h1><i class="bi bi-receipt me-2" aria-hidden="true"></i>Pedidos</h1>
                <p>Olá, <?php echo $userName; ?>. Acompanhe os pedidos recebidos na loja. Somente leitura.</p>
            </div>
        </div>

        <!-- Barra de filtros -->
        <form method="GET" action="<?php echo $GLOBALS['orders_url']; ?>" class="orders-filters">
            <div class="orders-filters-grid">

                <!-- Status: multi-seleção -->
                <div class="dropdown orders-filter orders-filter-status">
                    <label class="orders-filter-label" id="orders-status-label">Status</label>
                    <button type="button" class="form-select form-select-sm orders-status-toggle<?php echo $statusCount > 0 ? ' is-active' : ''; ?>"
                            data-bs-toggle="dropdown" data-bs-auto-close="outside"
                            aria-expanded="false" aria-labelledby="orders-status-label">
                        <span><?php echo htmlspecialchars($statusToggleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($statusCount > 0): ?>
                            <span class="orders-status-count"><?php echo $statusCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu orders-status-menu">
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <label class="orders-status-option">
                                <input class="form-check-input" type="checkbox" name="status[]"
                                       value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                       <?php echo in_array($value, $currentStatuses, true) ? 'checked' : ''; ?>>
                                <span class="user-badge <?php echo $statusBadge[$value]; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- CPF: valor único -->
                <div class="orders-filter">
                    <label class="orders-filter-label" for="orders-filter-cpf">CPF</label>
                    <input type="text" id="orders-filter-cpf" name="cpf" class="form-control form-control-sm" placeholder="000.000.000-00"
                           value="<?php echo htmlspecialchars($currentCpf, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <!-- Telefone: valor único -->
                <div class="orders-filter">
                    <label class="orders-filter-label" for="orders-filter-telefone">Telefone</label>
                    <input type="text" id="orders-filter-telefone" name="telefone" class="form-control form-control-sm"
                           placeholder="mín. <?php echo (int)$phoneFilterMinDigits; ?> dígitos"
                           value="<?php echo htmlspecialchars($currentPhone, ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <!-- Gateway: valor único -->
                <div class="orders-filter orders-filter-gateway">
                    <label class="orders-filter-label" for="orders-filter-gateway">Gateway</label>
                    <select id="orders-filter-gateway" name="gateway" class="form-select form-select-sm">
                        <option value="">Todos os gateways</option>
                        <?php foreach ($gateways as $gw): ?>
                            <option value="<?php echo (int)$gw['idx']; ?>" <?php echo $currentGateway === (int)$gw['idx'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gw['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Data de criação: intervalo -->
                <div class="orders-filter orders-filter-dates">
                    <label class="orders-filter-label">Data da Compra</label>
                    <div class="orders-date-range">
                        <input type="date" name="data_inicio" class="form-control form-control-sm" aria-label="Criado a partir de"
                               value="<?php echo htmlspecialchars($currentDateStart, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="orders-date-sep" aria-hidden="true">→</span>
                        <input type="date" name="data_fim" class="form-control form-control-sm" aria-label="Criado até"
                               value="<?php echo htmlspecialchars($currentDateEnd, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <!-- Ações -->
                <div class="orders-filter orders-filter-actions">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                    <a href="<?php echo htmlspecialchars(set_url($GLOBALS['orders_export_url'], $filterParams), ENT_QUOTES, 'UTF-8'); ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-download" aria-hidden="true"></i> Exportar CSV
                    </a>
                </div>

            </div>
        </form>

        <!-- Tabela de pedidos -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Pedidos Recebidos
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($orders)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhum pedido encontrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <?php foreach ($sortColumns as $sortKey => $sortLabel):
                                        $isActive = ($currentSort === $sortKey);
                                        $nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
                                        $ariaSort = $isActive ? ($currentDir === 'asc' ? 'ascending' : 'descending') : 'none';
                                        $sortHref = set_url($GLOBALS['orders_url'], $filterParams + ['sort' => $sortKey, 'dir' => $nextDir]);
                                        if ($isActive) {
                                            $sortIcon = $currentDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill';
                                        } else {
                                            $sortIcon = 'bi-chevron-expand';
                                        }
                                    ?>
                                        <th aria-sort="<?php echo $ariaSort; ?>" class="orders-th-sortable<?php echo $isActive ? ' is-active' : ''; ?>">
                                            <a href="<?php echo htmlspecialchars($sortHref, ENT_QUOTES, 'UTF-8'); ?>"
                                               class="orders-sort-link<?php echo $isActive ? ' is-active' : ''; ?>">
                                                <span><?php echo htmlspecialchars($sortLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <i class="bi <?php echo $sortIcon; ?> orders-sort-icon" aria-hidden="true"></i>
                                            </a>
                                        </th>
                                    <?php endforeach; ?>
                                    <th><span class="visually-hidden">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $o):
                                    $orderIdx = (int)$o['idx'];
                                    $orderShowUrl = sprintf($GLOBALS['order_url'], $orderIdx);
                                ?>
                                    <tr>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $orderIdx; ?></td>
                                        <td style="font-size:0.78rem;font-family:monospace;"><?php echo htmlspecialchars(substr($o['token'] ?? '', 0, 8), ENT_QUOTES, 'UTF-8'); ?>…</td>
                                        <td><?php echo htmlspecialchars($o['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if (!empty($o['shipped_at'])): ?>
                                                <span class="user-badge badge-shipped">Enviado</span>
                                            <?php else: ?>
                                                <span class="user-badge <?php echo $statusBadge[$o['status']] ?? 'badge-inactive'; ?>">
                                                    <?php echo htmlspecialchars($statusLabels[$o['status']] ?? $o['status'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($o['gateway_name'])): ?>
                                                <span class="gateway-tag"><?php echo htmlspecialchars($o['gateway_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted);font-size:0.78rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem;">R$ <?php echo number_format((int)($o['total_cents'] ?? 0) / 100, 2, ',', '.'); ?></td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo time_ago($o['created_at'] ?? null); ?></td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $o['paid_at'] ? time_ago($o['paid_at']) : '—'; ?></td>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($orderShowUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-action-edit">
                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                                <span class="orders-action-label">Detalhes</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (($totalPages ?? 0) > 1): ?>
                <div class="content-panel-footer d-flex justify-content-center p-3">
                    <?php
                    $pg_url    = $GLOBALS['orders_url'];
                    $pg_page   = $page;
                    $pg_total  = $totalPages;
                    // Preserva a ordenacao ao paginar; omite quando e o padrao (URLs limpas).
                    $pg_params = $filterParams;
                    if ($currentSort !== 'criado' || $currentDir !== 'desc') {
                        $pg_params += ['sort' => $currentSort, 'dir' => $currentDir];
                    }
                    $pg_label  = 'Paginação de pedidos';
                    include(constant("cRootServer") . "ui/common/pagination.php");
                    ?>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>
