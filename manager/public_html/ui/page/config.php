<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$adminIdx   = (int)($credential["idx"] ?? 0);
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');

$users         = $users ?? [];
$total_users   = $total_users ?? 0;
$active_users  = $active_users ?? 0;
$enabled_users = $enabled_users ?? 0;
$removed_users = $removed_users ?? 0;

$user = $user ?? [];
$f_name  = htmlspecialchars((string)($user['name']  ?? ''), ENT_QUOTES, 'UTF-8');
$f_mail  = htmlspecialchars((string)($user['mail']  ?? ''), ENT_QUOTES, 'UTF-8');
$f_login = htmlspecialchars((string)($user['login'] ?? ''), ENT_QUOTES, 'UTF-8');
$f_phone = htmlspecialchars((string)($user['phone'] ?? ''), ENT_QUOTES, 'UTF-8');

$gateways   = $gateways ?? [];
$modeLabels = ['qr' => 'QR Code', 'redirect' => 'Redirecionamento'];

$salesSettings = $salesSettings ?? ['sales_override' => '', 'sales_window_start_at' => '', 'sales_window_end_at' => ''];
$salesStatus   = $salesStatus   ?? ['open' => true, 'reopens_at' => null, 'reason' => null];

$salesReasonLabels = [
    'override' => 'fechado manualmente',
    'window'   => 'fora da janela',
    'stock'    => 'estoque esgotado',
];
$salesReasonLabel = $salesReasonLabels[$salesStatus['reason'] ?? ''] ?? null;

$f_sales_start = htmlspecialchars(str_replace(' ', 'T', substr((string)$salesSettings['sales_window_start_at'], 0, 16)), ENT_QUOTES, 'UTF-8');
$f_sales_end   = htmlspecialchars(str_replace(' ', 'T', substr((string)$salesSettings['sales_window_end_at'], 0, 16)), ENT_QUOTES, 'UTF-8');
?>

<div class="manager-layout" x-data="dashboardController()" x-init="init()">

    <!-- Sidebar -->
    <?php include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <div>
                <h1><i class="bi bi-gear me-2" aria-hidden="true"></i>Configurações</h1>
                <p>Olá, <?php echo $userName; ?>. Gerencie os dados da sua conta e o uso dos gateways de pagamento.</p>
            </div>
        </div>

        <!-- Dados da conta + Alterar senha (mesmo card) e Janela de Vendas, lado a lado -->
        <div class="row g-3 mb-4 align-items-stretch">
            <div class="col-12 col-lg-6">
                <div class="content-panel h-100">
                    <div class="content-panel-header">
                        <i class="bi bi-person-gear" aria-hidden="true"></i> Dados da Conta
                    </div>
                    <div class="content-panel-body">
                        <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_url'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="perfil">
                            <div class="row g-3">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="cfg-name">Nome</label>
                                    <input type="text" class="form-control" id="cfg-name" name="name" value="<?php echo $f_name; ?>" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="cfg-mail">E-mail</label>
                                    <input type="email" class="form-control" id="cfg-mail" name="mail" value="<?php echo $f_mail; ?>" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="cfg-login">Login</label>
                                    <input type="text" class="form-control" id="cfg-login" name="login" value="<?php echo $f_login; ?>" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="cfg-phone">Telefone</label>
                                    <input type="text" class="form-control" id="cfg-phone" name="phone" value="<?php echo $f_phone; ?>">
                                </div>
                            </div>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Salvar dados
                                </button>
                            </div>
                        </form>

                        <hr style="border-color:var(--border);margin:1.25rem 0;">

                        <div class="d-flex align-items-center gap-2 mb-3" style="font-size:0.65rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.09em;">
                            <i class="bi bi-shield-lock" aria-hidden="true"></i> Alterar Senha
                        </div>
                        <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_url'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="senha">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="cfg-senha-atual">Senha atual</label>
                                    <input type="password" class="form-control" id="cfg-senha-atual" name="senha_atual" autocomplete="current-password" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="cfg-senha-nova">Nova senha</label>
                                    <input type="password" class="form-control" id="cfg-senha-nova" name="senha_nova" autocomplete="new-password" minlength="8" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="cfg-senha-confirma">Confirmar nova senha</label>
                                    <input type="password" class="form-control" id="cfg-senha-confirma" name="senha_confirma" autocomplete="new-password" minlength="8" required>
                                </div>
                            </div>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Alterar senha
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="content-panel h-100">
                    <div class="content-panel-header">
                        <i class="bi bi-shop" aria-hidden="true"></i> Janela de Vendas
                    </div>
                    <div class="content-panel-body">
                        <p style="font-size:0.85rem;color:var(--text-muted);">
                            Estado atual:
                            <?php if ($salesStatus['open']): ?>
                                <span class="user-badge badge-active">Vendas ABERTAS</span>
                            <?php else: ?>
                                <span class="user-badge badge-removed">Vendas FECHADAS</span>
                                <?php if ($salesReasonLabel !== null): ?>
                                    <span style="color:var(--text-muted);">— <?php echo htmlspecialchars($salesReasonLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                        <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_url'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="janela">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label d-block">Controle de vendas</label>
                                    <div class="d-flex flex-wrap gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="sales_override" id="sw-auto" value="" <?php echo $salesSettings['sales_override'] === '' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sw-auto">Automático (janela + estoque)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="sales_override" id="sw-open" value="open" <?php echo $salesSettings['sales_override'] === 'open' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sw-open">Forçar vendas abertas</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="sales_override" id="sw-closed" value="closed" <?php echo $salesSettings['sales_override'] === 'closed' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sw-closed">Forçar vendas fechadas</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="sw-start">Início da janela</label>
                                    <input type="datetime-local" class="form-control" id="sw-start" name="sales_window_start_at" value="<?php echo $f_sales_start; ?>">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="sw-end">Fim da janela</label>
                                    <input type="datetime-local" class="form-control" id="sw-end" name="sales_window_end_at" value="<?php echo $f_sales_end; ?>">
                                </div>
                                <div class="col-12">
                                    <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0;">
                                        Início futuro é a data de reabertura mostrada ao cliente. Campos vazios = sem restrição de data.
                                        Estoque zerado fecha as vendas automaticamente (a menos que "Forçar vendas abertas" esteja selecionado).
                                    </p>
                                </div>
                            </div>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Salvar janela de vendas
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gateways -->
        <div class="content-panel mb-4">
            <div class="content-panel-header">
                <i class="bi bi-credit-card" aria-hidden="true"></i> Gateways de Pagamento
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($gateways)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhum gateway cadastrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Slug</th>
                                    <th>Modo</th>
                                    <th>Faturamento do mês</th>
                                    <th>% de utilização</th>
                                    <th>Habilitado</th>
                                    <th>Limite mensal</th>
                                    <th>Teto por pedido</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gateways as $g):
                                    $gatewayIdx     = (int)$g['idx'];
                                    $usagePct       = (float)($g['usage_pct'] ?? 0);
                                    $usageBadge     = $usagePct >= 100 ? 'badge-removed' : ($usagePct >= 80 ? 'badge-inactive' : 'badge-active');
                                    $maxOrderCents  = $g['max_order_cents'] ?? null;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($g['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($g['slug'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($modeLabels[$g['mode']] ?? $g['mode'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;">R$ <?php echo number_format((int)($g['mtd_cents'] ?? 0) / 100, 2, ',', '.'); ?></td>
                                        <td>
                                            <span class="user-badge <?php echo $usageBadge; ?>"><?php echo number_format($usagePct, 1, ',', '.'); ?>%</span>
                                        </td>
                                        <td>
                                            <?php if (($g['enabled'] ?? 'no') === 'yes'): ?>
                                                <span class="user-badge badge-active">Sim</span>
                                            <?php else: ?>
                                                <span class="user-badge badge-inactive">Não</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem;">R$ <?php echo number_format((int)($g['monthly_limit_cents'] ?? 0) / 100, 2, ',', '.'); ?></td>
                                        <td style="font-size:0.82rem;">
                                            <?php echo $maxOrderCents === null ? 'Ilimitado' : 'R$ ' . number_format((int)$maxOrderCents / 100, 2, ',', '.'); ?>
                                        </td>
                                        <td>
                                            <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_url'], ENT_QUOTES, 'UTF-8'); ?>" class="d-flex align-items-center gap-1">
                                                <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="gateway">
                                                <input type="hidden" name="idx" value="<?php echo $gatewayIdx; ?>">
                                                <input type="checkbox" name="enabled" value="yes" class="form-check-input" title="Habilitado"
                                                    <?php echo ($g['enabled'] ?? 'no') === 'yes' ? 'checked' : ''; ?>>
                                                <input type="text" name="monthly_limit_cents" class="form-control form-control-sm" style="width:8rem;"
                                                    placeholder="R$ 0,00"
                                                    value="<?php echo htmlspecialchars(number_format((int)($g['monthly_limit_cents'] ?? 0) / 100, 2, ',', '.'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="text" name="max_order_cents" class="form-control form-control-sm" style="width:8rem;"
                                                    placeholder="Ilimitado"
                                                    value="<?php echo $maxOrderCents === null ? '' : htmlspecialchars(number_format((int)$maxOrderCents / 100, 2, ',', '.'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-sm btn-action-edit" title="Salvar">
                                                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3" style="font-size:0.8rem;color:var(--text-muted);">
                        Teto por pedido: vazio = sem limite por pedido. Pelo menos um gateway habilitado deve ficar sem limite.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Usuários admin -->
        <div class="content-panel">
            <div class="content-panel-header content-panel-header--action">
                <span><i class="bi bi-people" aria-hidden="true"></i> Usuários Admin</span>
                <div class="d-flex gap-2">
                    <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_users_url'], ENT_QUOTES, 'UTF-8'); ?>" style="display:inline;">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="export-csv">
                        <button type="submit" class="btn btn-outline-secondary btn-sm" style="white-space:nowrap;">
                            <i class="bi bi-download me-1" aria-hidden="true"></i> Exportar CSV
                        </button>
                    </form>
                    <button type="button" class="btn btn-sm btn-primary" style="white-space:nowrap;" data-bs-toggle="modal" data-bs-target="#createUserModal">
                        <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Novo usuário
                    </button>
                </div>
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($users)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        Nenhum usuário cadastrado.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nome</th>
                                    <th>E-mail</th>
                                    <th>Login</th>
                                    <th>Status</th>
                                    <th>Verificado</th>
                                    <th>Último login</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u):
                                    $isRemoved  = ($u['active'] ?? 'yes') === 'no';
                                    $isEnabled  = ($u['enabled'] ?? 'yes') === 'yes';
                                    $isVerified = !empty($u['email_verified_at']);
                                    $lastLogin  = time_ago($u['last_login'] ?? null);
                                    $userIdx    = (int)$u['idx'];
                                    $isSelf     = $userIdx === $adminIdx;
                                    $jsName     = htmlspecialchars(json_encode($u['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsMail     = htmlspecialchars(json_encode($u['mail'] ?? ''), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr<?php echo $isRemoved ? ' style="opacity:.4"' : ''; ?>>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $userIdx; ?></td>
                                        <td><?php echo htmlspecialchars($u['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($u['mail'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($u['login'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($isRemoved): ?>
                                                <span class="user-badge badge-removed">Removido</span>
                                            <?php elseif ($isEnabled): ?>
                                                <span class="user-badge badge-active">Ativo</span>
                                            <?php else: ?>
                                                <span class="user-badge badge-inactive">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isVerified): ?>
                                                <i class="bi bi-check-circle-fill" style="color:var(--success)" title="Verificado" aria-label="E-mail verificado"></i>
                                            <?php else: ?>
                                                <i class="bi bi-clock" style="color:var(--text-muted)" title="Pendente" aria-label="Aguardando verificação"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $lastLogin; ?></td>
                                        <td>
                                            <?php if (!$isRemoved && !$isSelf): ?>
                                                <div class="d-flex gap-1">

                                                    <!-- Editar -->
                                                    <button type="button" class="btn btn-sm btn-action-edit"
                                                        @click="openEdit(<?php echo $userIdx; ?>, <?php echo $jsName; ?>, <?php echo $jsMail; ?>)"
                                                        title="Editar usuário">
                                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                                    </button>

                                                    <!-- Ativar / Inativar -->
                                                    <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_users_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        @submit.prevent="confirmToggle($event.target, <?php echo $jsName; ?>, '<?php echo $isEnabled ? 'inativar' : 'ativar'; ?>')">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="idx" value="<?php echo $userIdx; ?>">
                                                        <input type="hidden" name="action" value="<?php echo $isEnabled ? 'inativar' : 'ativar'; ?>">
                                                        <button type="submit" class="btn btn-sm btn-action-toggle"
                                                            title="<?php echo $isEnabled ? 'Inativar usuário' : 'Ativar usuário'; ?>">
                                                            <i class="bi <?php echo $isEnabled ? 'bi-person-dash' : 'bi-person-check'; ?>" aria-hidden="true"></i>
                                                        </button>
                                                    </form>

                                                    <!-- Remover -->
                                                    <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_users_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        @submit.prevent="confirmRemove($event.target, <?php echo $jsName; ?>)">
                                                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="idx" value="<?php echo $userIdx; ?>">
                                                        <input type="hidden" name="action" value="remover">
                                                        <button type="submit" class="btn btn-sm btn-action-remove" title="Remover usuário">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </form>

                                                </div>
                                            <?php elseif ($isSelf && !$isRemoved): ?>
                                                <span style="font-size:0.72rem;color:var(--text-muted);">Você</span>
                                            <?php else: ?>
                                                <span style="font-size:0.72rem;color:var(--text-muted);">—</span>
                                            <?php endif; ?>
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
                    $pg_url    = $GLOBALS['config_url'];
                    $pg_page   = $page;
                    $pg_total  = $totalPages;
                    $pg_params = [];
                    $pg_label  = 'Paginação de usuários';
                    include(constant("cRootServer") . "ui/common/pagination.php");
                    ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Modal de edição de usuário -->
    <div id="editUserModal" class="modal fade" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;">
                <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_users_url'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="editar">
                    <input type="hidden" name="idx" :value="editData.idx">

                    <div class="modal-header" style="border-color:var(--border);padding:1rem 1.25rem 0.75rem;">
                        <h5 class="modal-title" id="editUserModalLabel"
                            style="font-size:0.9rem;font-weight:700;color:var(--text);">
                            <i class="bi bi-pencil me-2" style="color:var(--accent)" aria-hidden="true"></i>Editar Usuário
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body" style="padding:1.25rem;">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                            <input type="text" name="name" class="form-control" x-model="editData.name" required autocomplete="off">
                        </div>
                        <div class="mb-0">
                            <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">E-mail</label>
                            <input type="email" name="mail" class="form-control" x-model="editData.mail" required autocomplete="off">
                        </div>
                    </div>

                    <div class="modal-footer" style="border-color:var(--border);padding:0.75rem 1.25rem;justify-content:space-between;">
                        <button type="button" class="btn btn-sm btn-action-reset"
                            @click="confirmResetPassword(editData.idx, editData.name)">
                            <i class="bi bi-envelope-arrow-up me-1" aria-hidden="true"></i>Enviar reset de senha
                        </button>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de criação de usuário -->
    <div id="createUserModal" class="modal fade" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;">
                <form method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_users_url'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="criar">

                    <div class="modal-header" style="border-color:var(--border);padding:1rem 1.25rem 0.75rem;">
                        <h5 class="modal-title" id="createUserModalLabel"
                            style="font-size:0.9rem;font-weight:700;color:var(--text);">
                            <i class="bi bi-person-plus me-2" style="color:var(--accent)" aria-hidden="true"></i>Novo Usuário
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body" style="padding:1.25rem;">
                        <p class="small" style="color: var(--text-muted);">O novo usuário receberá um email com as instruções para definir a senha.</p>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                            <input type="text" name="name" class="form-control" autocomplete="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">E-mail</label>
                            <input type="email" name="mail" class="form-control" autocomplete="email" required>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Login <small class="fw-normal">(sem espaços)</small></label>
                            <input type="text" name="login" class="form-control" autocomplete="username" pattern="[a-zA-Z0-9._-]+" required>
                        </div>
                    </div>

                    <div class="modal-footer" style="border-color:var(--border);padding:0.75rem 1.25rem;justify-content:end;">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-sm btn-primary">Cadastrar Usuário</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Form oculto para reset de senha -->
    <form id="resetPasswordForm" method="POST" action="<?php echo htmlspecialchars($GLOBALS['config_users_url'], ENT_QUOTES, 'UTF-8'); ?>" style="display:none;">
        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="reset-senha">
        <input type="hidden" name="idx" id="resetPasswordIdx" value="">
    </form>
</div>
