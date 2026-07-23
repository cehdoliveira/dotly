<?php

/**
 * Sidebar de navegação do manager — fonte única do menu.
 * Incluída por todas as páginas internas dentro de <div class="manager-layout">.
 * O item ativo é derivado do primeiro segmento da URL atual (sem hardcode por página).
 */

// Segmento atual relativo à base do frontend: '' | 'clientes' | 'produtos' | 'pedidos' | 'config'
$__base = rtrim((string) (parse_url((string) constant('cFrontend'), PHP_URL_PATH) ?? '/'), '/');
$__path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$__rel  = trim(substr($__path, strlen($__base)), '/');
$__seg  = explode('/', $__rel)[0] ?? '';

$__navItems = [
    ['url' => $GLOBALS['home_url'],     'seg' => '',         'icon' => 'bi-graph-up',    'label' => 'Início'],
    ['url' => $GLOBALS['orders_url'],   'seg' => 'pedidos',  'icon' => 'bi-receipt',     'label' => 'Pedidos'],
    ['url' => $GLOBALS['products_url'], 'seg' => 'produtos', 'icon' => 'bi-box-seam',    'label' => 'Produtos'],
    ['url' => $GLOBALS['customers_url'], 'seg' => 'clientes', 'icon' => 'bi-people',      'label' => 'Clientes'],
];
?>
<nav class="manager-sidebar" aria-label="Navegação principal">
    <div class="manager-sidebar-inner">
        <div class="nav-section-label">Menu</div>
        <ul class="nav flex-column gap-1">
            <?php foreach ($__navItems as $__item): ?>
                <?php $__active = ($__item['seg'] === $__seg); ?>
                <li class="nav-item">
                    <a href="<?php echo htmlspecialchars($__item['url'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="nav-link<?php echo $__active ? ' active' : ''; ?>"
                        <?php echo $__active ? 'aria-current="page"' : ''; ?>>
                        <i class="bi <?php echo $__item['icon']; ?>" aria-hidden="true"></i> <?php echo $__item['label']; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>
