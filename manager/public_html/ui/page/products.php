<?php
$credential = $_SESSION[constant("cAppKey")]["credential"] ?? [];
$userName   = htmlspecialchars($credential["name"] ?? "Admin", ENT_QUOTES, 'UTF-8');
$csrfToken  = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');

$currentQ          = $currentQ ?? '';
$currentCategory   = $currentCategory ?? '';
$categories        = $categories ?? [];
$currentStock      = $currentStock ?? '';
$lowStockThreshold = (int)$lowStockThreshold;

// Opções do filtro de estoque. Chaves batem com products_controller::buildFilter
// (só estas viram condição); "crítico" = baixo OU esgotado.
$stockFilterOptions = [
    'baixo'    => 'Estoque baixo',
    'esgotado' => 'Esgotado',
    'critico'  => 'Baixo ou esgotado',
];

// Ordenacao clicavel do cabecalho. As chaves batem com products_controller::SORTABLE
// (o controller valida qual coluna vira ORDER BY); aqui so montamos os links.
$currentSort = $currentSort ?? '';
$currentDir  = (($currentDir ?? 'asc') === 'desc') ? 'desc' : 'asc';
$sortColumns = [
    'nome'      => 'Nome',
    'categoria' => 'Categoria',
    'preco'     => 'Preço unid.',
    'estoque'   => 'Estoque',
];

// Filtros propagados para paginar/ordenar (URLs limpas: só o que está setado).
$filterParams = array_filter([
    'q'         => $currentQ,
    'categoria' => $currentCategory,
    'estoque'   => $currentStock,
], static fn($v) => $v !== '');
?>

<div class="manager-layout" x-data="productsController()" x-init="init()">

    <!-- Sidebar -->
    <?php include(constant("cRootServer") . "ui/common/sidebar.php"); ?>

    <!-- Conteúdo principal -->
    <main class="manager-content">

        <?php html_notification_print(); ?>

        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1><i class="bi bi-box-seam me-2" aria-hidden="true"></i>Produtos</h1>
                    <p>Olá, <?php echo $userName; ?>. Gerencie o catálogo de produtos da loja.</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" style="white-space:nowrap;" @click="openCreate()">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Novo Produto
                    </button>
                </div>
            </div>
        </div>

        <!-- Barra de filtros -->
        <form method="GET" action="<?php echo $GLOBALS['products_url']; ?>" class="orders-filters">
            <div class="orders-filters-grid">
                <div class="orders-filter products-filter-search">
                    <label class="orders-filter-label" for="products-filter-q">Nome do produto</label>
                    <input type="text" id="products-filter-q" name="q" class="form-control form-control-sm"
                        placeholder="Buscar por nome"
                        value="<?php echo htmlspecialchars($currentQ, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="orders-filter products-filter-category">
                    <label class="orders-filter-label" for="products-filter-categoria">Categoria</label>
                    <select id="products-filter-categoria" name="categoria" class="form-select form-select-sm">
                        <option value="">Todas as categorias</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentCategory === $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="orders-filter products-filter-category">
                    <label class="orders-filter-label" for="products-filter-estoque">Estoque</label>
                    <select id="products-filter-estoque" name="estoque" class="form-select form-select-sm">
                        <option value="">Todos os estoques</option>
                        <?php foreach ($stockFilterOptions as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentStock === $value ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="orders-filter orders-filter-actions">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                    <?php if ($currentQ !== '' || $currentCategory !== '' || $currentStock !== ''): ?>
                        <a href="<?php echo htmlspecialchars($GLOBALS['products_url'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-lg" aria-hidden="true"></i> Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Tabela de produtos -->
        <div class="content-panel">
            <div class="content-panel-header">
                <i class="bi bi-table" aria-hidden="true"></i> Produtos Cadastrados
            </div>
            <div class="content-panel-body p-0">
                <?php if (empty($products)): ?>
                    <div class="p-4 text-center" style="color: var(--text-muted); font-size: 0.85rem;">
                        <?php echo ($currentQ !== '' || $currentCategory !== '' || $currentStock !== '') ? 'Nenhum produto encontrado para esse filtro.' : 'Nenhum produto cadastrado.'; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Capa</th>
                                    <?php foreach ($sortColumns as $sortKey => $sortLabel):
                                        $isActive = ($currentSort === $sortKey);
                                        $nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
                                        $ariaSort = $isActive ? ($currentDir === 'asc' ? 'ascending' : 'descending') : 'none';
                                        $sortHref = set_url($GLOBALS['products_url'], $filterParams + ['sort' => $sortKey, 'dir' => $nextDir]);
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
                                <?php foreach ($products as $p):
                                    $productIdx = (int)$p['idx'];
                                    $coverPath  = $p['cover_path'] ?? null;
                                    $jsName     = htmlspecialchars(json_encode($p['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsSlug     = htmlspecialchars(json_encode($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsCategory = htmlspecialchars(json_encode($p['category'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsPriceUnit = htmlspecialchars(json_encode(number_format((int)($p['price_unit_cents'] ?? 0) / 100, 2, ',', '.')), ENT_QUOTES, 'UTF-8');
                                    $jsDosage   = htmlspecialchars(json_encode($p['dosage'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $jsStock    = (int)($p['stock'] ?? 0);

                                    // Estado de estoque: a linha inteira sinaliza urgencia (ver .product-row--*).
                                    if ($jsStock <= 0) {
                                        $rowStateClass = 'product-row--out';
                                    } elseif ($jsStock <= $lowStockThreshold) {
                                        $rowStateClass = 'product-row--low';
                                    } else {
                                        $rowStateClass = '';
                                    }
                                ?>
                                    <tr class="<?php echo $rowStateClass; ?>">
                                        <td style="font-size:0.78rem;color:var(--text-muted);"><?php echo $productIdx; ?></td>
                                        <td>
                                            <?php if ($coverPath): ?>
                                                <img src="<?php echo htmlspecialchars(constant('cAssets') . $coverPath, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:0.35rem;">
                                            <?php else: ?>
                                                <span style="font-size:0.72rem;color:var(--text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($p['name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($p['category'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-size:0.82rem;">R$ <?php echo number_format((int)($p['price_unit_cents'] ?? 0) / 100, 2, ',', '.'); ?></td>
                                        <td style="font-size:0.82rem;">
                                            <?php if ($jsStock <= 0): ?>
                                                <span class="stock-pill stock-pill--out">
                                                    <i class="bi bi-x-octagon-fill" aria-hidden="true"></i> Esgotado
                                                </span>
                                            <?php elseif ($jsStock <= $lowStockThreshold): ?>
                                                <span class="stock-pill stock-pill--low">
                                                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Baixo · <?php echo $jsStock; ?>
                                                </span>
                                            <?php else: ?>
                                                <?php echo $jsStock; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <!-- Editar -->
                                                <button type="button" class="btn btn-sm btn-action-edit"
                                                    @click="openEdit(<?php echo $productIdx; ?>, <?php echo $jsName; ?>, <?php echo $jsSlug; ?>, <?php echo $jsCategory; ?>, <?php echo $jsDosage; ?>, <?php echo $jsPriceUnit; ?>, <?php echo $jsStock; ?>)"
                                                    title="Editar produto">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                                </button>

                                                <!-- Remover -->
                                                <form method="POST" action="<?php echo $GLOBALS['products_url']; ?>"
                                                    @submit.prevent="confirmRemove($event.target, <?php echo $jsName; ?>)">
                                                    <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="idx" value="<?php echo $productIdx; ?>">
                                                    <input type="hidden" name="action" value="remover">
                                                    <button type="submit" class="btn btn-sm btn-action-remove" title="Remover produto">
                                                        <i class="bi bi-trash" aria-hidden="true"></i> Remover
                                                    </button>
                                                </form>
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
                    $pg_url    = $GLOBALS['products_url'];
                    $pg_page   = $page;
                    $pg_total  = $totalPages;
                    // Preserva busca e ordenação ao paginar; omite o sort quando é o padrão.
                    $pg_params = $filterParams;
                    if ($currentSort !== '') {
                        $pg_params += ['sort' => $currentSort, 'dir' => $currentDir];
                    }
                    $pg_label  = 'Paginação de produtos';
                    include(constant("cRootServer") . "ui/common/pagination.php");
                    ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Modais FORA de .manager-content: a animacao de entrada dela (manager-fade-in)
         cria um stacking context que prende o modal ATRAS do .modal-backdrop (o
         Bootstrap anexa o backdrop no body). Ficam dentro de .manager-layout para o
         x-data/Alpine (x-model, @submit) continuar no escopo. -->

        <!-- Modal de criação -->
        <div id="createProductModal" class="modal fade" tabindex="-1" aria-labelledby="createProductModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;">
                    <form method="POST" action="<?php echo $GLOBALS['products_url']; ?>" enctype="multipart/form-data">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="criar">

                        <div class="modal-header" style="border-color:var(--border);padding:1rem 1.25rem 0.75rem;">
                            <h5 class="modal-title" id="createProductModalLabel"
                                style="font-size:0.9rem;font-weight:700;color:var(--text);">
                                <i class="bi bi-plus-lg me-2" style="color:var(--accent)" aria-hidden="true"></i>Novo Produto
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body" style="padding:1.25rem;">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                                <input type="text" name="name" class="form-control" required autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Slug (opcional — derivado do nome se vazio)</label>
                                <input type="text" name="slug" class="form-control" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Categoria</label>
                                <input type="text" name="category" class="form-control" required autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Dosagem (mg)</label>
                                <input type="text" name="dosage" class="form-control" placeholder="ex.: 60" maxlength="40" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Preço unidade (ex.: 70,00)</label>
                                <input type="text" name="price_unit_cents" class="form-control" placeholder="R$ 0,00" required autocomplete="off" @blur="formatPrice($event.target)">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Estoque</label>
                                <input type="number" name="stock" class="form-control" value="0" min="0">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Fotos</label>
                                <input type="file" name="photos[]" class="form-control" multiple accept="image/*">
                            </div>
                        </div>

                        <div class="modal-footer" style="border-color:var(--border);padding:0.75rem 1.25rem;justify-content:end;">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-sm btn-primary">Criar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal de edição -->
        <div id="editProductModal" class="modal fade" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;">
                    <form method="POST" action="<?php echo $GLOBALS['products_url']; ?>" enctype="multipart/form-data">
                        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="editar">
                        <input type="hidden" name="idx" :value="editData.idx">

                        <div class="modal-header" style="border-color:var(--border);padding:1rem 1.25rem 0.75rem;">
                            <h5 class="modal-title" id="editProductModalLabel"
                                style="font-size:0.9rem;font-weight:700;color:var(--text);">
                                <i class="bi bi-pencil me-2" style="color:var(--accent)" aria-hidden="true"></i>Editar Produto
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body" style="padding:1.25rem;">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Nome</label>
                                <input type="text" name="name" class="form-control" x-model="editData.name" required autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Slug</label>
                                <input type="text" name="slug" class="form-control" x-model="editData.slug" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Categoria</label>
                                <input type="text" name="category" class="form-control" x-model="editData.category" required autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Dosagem (mg)</label>
                                <input type="text" name="dosage" class="form-control" x-model="editData.dosage" placeholder="ex.: 60" maxlength="40" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Preço unidade</label>
                                <input type="text" name="price_unit_cents" class="form-control" x-model="editData.priceUnit" required autocomplete="off" @blur="formatPrice($event.target)">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Estoque</label>
                                <input type="number" name="stock" class="form-control" x-model="editData.stock" min="0">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Adicionar fotos</label>
                                <input type="file" name="photos[]" class="form-control" multiple accept="image/*">
                            </div>
                        </div>

                        <div class="modal-footer" style="border-color:var(--border);padding:0.75rem 1.25rem;justify-content:end;">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
</div>
