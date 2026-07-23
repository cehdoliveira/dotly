<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');

$customers  = $customers ?? [];

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Formatadores de exibicao — inline, mesmo padrao de order_detail.php. Nunca
// alteram o dado (so digitos), apenas a apresentacao.
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
$fmtDate = static fn(?string $dt): string => $dt ? date('d/m/Y', strtotime($dt)) : '—';

// Filtros atuais (repovoam os campos e propagam em ordenacao/paginacao).
$currentName          = $currentName ?? '';
$currentEmail         = $currentEmail ?? '';
$currentPhone         = $currentPhone ?? '';
$currentDateStart     = $currentDateStart ?? '';
$currentDateEnd       = $currentDateEnd ?? '';
$phoneFilterMinDigits = $phoneFilterMinDigits ?? 4;

// Ordenacao clicavel — as chaves batem com customers_controller::SORTABLE.
$currentSort = $currentSort ?? 'ultima_compra';
$currentDir  = (($currentDir ?? 'desc') === 'asc') ? 'asc' : 'desc';
$sortColumns = [
    'nome'          => 'Nome',
    'email'         => 'E-mail',
    'telefone'      => 'Telefone',
    'cidade'        => 'Cidade / UF',
    'ultima_compra' => 'Última compra',
];

// Filtros propagados para os links de ordenacao/paginacao (omitidos quando vazios).
$filterParams = array_filter([
    'nome'        => $currentName,
    'email'       => $currentEmail,
    'telefone'    => $currentPhone,
    'data_inicio' => $currentDateStart,
    'data_fim'    => $currentDateEnd,
], static fn($v) => $v !== '');
?>

<div class="manager-layout" x-data="customersController()">

    <!-- Sidebar -->
    <?php include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <div>
                <h1><i class="bi bi-people me-2" aria-hidden="true"></i>Clientes</h1>
                <p>Olá, <?php echo $userName; ?>. Compradores que já fizeram ao menos um pedido na loja.</p>
            </div>
        </div>

        <!-- Barra de filtros -->
        <form method="GET" action="<?php echo $e($GLOBALS['customers_url']); ?>" class="orders-filters">
            <div class="orders-filters-grid">
                <div class="orders-filter">
                    <label class="orders-filter-label" for="customers-filter-nome">Nome</label>
                    <input type="text" id="customers-filter-nome" name="nome" class="form-control form-control-sm" placeholder="Nome do cliente"
                           value="<?php echo $e($currentName); ?>">
                </div>
                <div class="orders-filter">
                    <label class="orders-filter-label" for="customers-filter-email">E-mail</label>
                    <input type="text" id="customers-filter-email" name="email" class="form-control form-control-sm" placeholder="email@exemplo.com"
                           value="<?php echo $e($currentEmail); ?>">
                </div>
                <div class="orders-filter">
                    <label class="orders-filter-label" for="customers-filter-telefone">Telefone</label>
                    <input type="text" id="customers-filter-telefone" name="telefone" class="form-control form-control-sm"
                           placeholder="mín. <?php echo (int)$phoneFilterMinDigits; ?> dígitos"
                           value="<?php echo $e($currentPhone); ?>">
                </div>
                <div class="orders-filter orders-filter-dates">
                    <label class="orders-filter-label">Última compra</label>
                    <div class="orders-date-range">
                        <input type="date" name="data_inicio" class="form-control form-control-sm" aria-label="Última compra a partir de"
                               value="<?php echo $e($currentDateStart); ?>">
                        <span class="orders-date-sep" aria-hidden="true">→</span>
                        <input type="date" name="data_fim" class="form-control form-control-sm" aria-label="Última compra até"
                               value="<?php echo $e($currentDateEnd); ?>">
                    </div>
                </div>
                <div class="orders-filter orders-filter-actions">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                    <?php if (!empty($filterParams)): ?>
                        <a href="<?php echo $e($GLOBALS['customers_url']); ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-lg" aria-hidden="true"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Tabela de clientes -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Clientes com Pedidos
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($customers)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhum cliente ainda. Assim que um pedido for feito, o comprador aparece aqui.
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
                                        $sortHref = set_url($GLOBALS['customers_url'], $filterParams + ['sort' => $sortKey, 'dir' => $nextDir]);
                                        if ($isActive) {
                                            $sortIcon = $currentDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill';
                                        } else {
                                            $sortIcon = 'bi-chevron-expand';
                                        }
                                    ?>
                                        <th aria-sort="<?php echo $ariaSort; ?>" class="orders-th-sortable<?php echo $isActive ? ' is-active' : ''; ?>">
                                            <a href="<?php echo $e($sortHref); ?>"
                                               class="orders-sort-link<?php echo $isActive ? ' is-active' : ''; ?>">
                                                <span><?php echo $e($sortLabel); ?></span>
                                                <i class="bi <?php echo $sortIcon; ?> orders-sort-icon" aria-hidden="true"></i>
                                            </a>
                                        </th>
                                    <?php endforeach; ?>
                                    <th><span class="visually-hidden">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $c):
                                    $lastOrderIdx = (int)$c['last_order_idx'];
                                    $isBlocked    = (bool)($c['is_blocked'] ?? false);
                                    $blockedIdx   = (int)($c['blocked_idx'] ?? 0);
                                    $name         = (string)($c['customer_name'] ?? '');
                                    $detailUrl    = sprintf($GLOBALS['customer_url'], $lastOrderIdx);
                                    $lastOrderUrl = sprintf($GLOBALS['order_url'], $lastOrderIdx);
                                    $cityUf       = trim(($c['ship_city'] ?? '') . ' / ' . ($c['ship_uf'] ?? ''), ' /');
                                ?>
                                    <tr>
                                        <td>
                                            <?php echo $e($name ?: '—'); ?>
                                            <?php if ($isBlocked): ?>
                                                <span class="user-badge badge-removed ms-1">Bloqueado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem;">
                                            <?php if (!empty($c['customer_mail'])): ?>
                                                <a href="mailto:<?php echo $e($c['customer_mail']); ?>"><?php echo $e($c['customer_mail']); ?></a>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem;"><?php echo $e($fmtPhone($c['customer_phone'] ?? null)); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo $e($cityUf ?: '—'); ?></td>
                                        <td style="font-size:0.82rem;" title="<?php echo $e(time_ago($c['last_purchase'] ?? null)); ?>">
                                            <?php echo $e($fmtDate($c['last_purchase'] ?? null)); ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-end customers-actions">
                                                <a href="<?php echo $e($detailUrl); ?>" class="btn btn-sm btn-action-edit" title="Ver histórico de compras">
                                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                                    <span class="customers-action-label">Detalhes</span>
                                                </a>
                                                <a href="<?php echo $e($lastOrderUrl); ?>" class="btn btn-sm btn-action-toggle" title="Abrir o último pedido">
                                                    <i class="bi bi-receipt" aria-hidden="true"></i>
                                                    <span class="customers-action-label">Último pedido</span>
                                                </a>
                                                <?php if (!$isBlocked): ?>
                                                    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>"
                                                          @submit.prevent="confirmBlock($event.target, <?php echo $e(json_encode($name)); ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="action" value="bloquear">
                                                        <input type="hidden" name="idx" value="<?php echo $lastOrderIdx; ?>">
                                                        <button type="submit" class="btn btn-sm btn-action-remove" title="Bloquear cliente">
                                                            <i class="bi bi-slash-circle" aria-hidden="true"></i>
                                                            <span class="customers-action-label">Bloquear</span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>"
                                                          @submit.prevent="confirmUnblock($event.target, <?php echo $e(json_encode($name)); ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="action" value="desbloquear">
                                                        <input type="hidden" name="idx" value="<?php echo $lastOrderIdx; ?>">
                                                        <input type="hidden" name="blocked_idx" value="<?php echo $blockedIdx; ?>">
                                                        <button type="submit" class="btn btn-sm btn-action-restore" title="Desbloquear cliente">
                                                            <i class="bi bi-check-circle" aria-hidden="true"></i>
                                                            <span class="customers-action-label">Desbloquear</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
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
                    $pg_url    = $GLOBALS['customers_url'];
                    $pg_page   = $page;
                    $pg_total  = $totalPages;
                    // Preserva filtros ao paginar; anexa ordenacao so quando nao e o padrao.
                    $pg_params = $filterParams;
                    if ($currentSort !== 'ultima_compra' || $currentDir !== 'desc') {
                        $pg_params += ['sort' => $currentSort, 'dir' => $currentDir];
                    }
                    $pg_label  = 'Paginação de clientes';
                    include(constant("cRootServer") . "ui/common/pagination.php");
                    ?>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>
