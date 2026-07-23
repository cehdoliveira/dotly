<?php
/**
 * Paginação com janela — fonte única para todas as listas do manager.
 * Mostra primeira, última e as páginas vizinhas da atual, com reticências nos vãos,
 * evitando a régua gigante de páginas quando o total é alto.
 *
 * Variáveis esperadas (definidas pela view antes do include):
 *   $pg_url    string  URL base da listagem (ex.: $GLOBALS['users_url'])
 *   $pg_page   int     Página atual
 *   $pg_total  int     Total de páginas
 *   $pg_params array   Parâmetros extras a preservar no link (filtros). Default [].
 *   $pg_label  string  Rótulo aria da navegação. Default 'Paginação'.
 */
$pg_page   = max(1, (int) ($pg_page ?? 1));
$pg_total  = max(1, (int) ($pg_total ?? 1));
$pg_params = $pg_params ?? [];
$pg_url    = $pg_url ?? '';
$pg_label  = $pg_label ?? 'Paginação';

$__win  = 2; // páginas vizinhas de cada lado da atual
$__link = static function (int $p) use ($pg_url, $pg_params): string {
    return htmlspecialchars(set_url($pg_url, ['page' => $p] + $pg_params), ENT_QUOTES, 'UTF-8');
};

// Monta a sequência visível; 0 marca reticências.
$__pages = [];
for ($p = 1; $p <= $pg_total; $p++) {
    if ($p === 1 || $p === $pg_total || ($p >= $pg_page - $__win && $p <= $pg_page + $__win)) {
        $__pages[] = $p;
    } elseif (end($__pages) !== 0) {
        $__pages[] = 0;
    }
}
?>
<nav aria-label="<?php echo htmlspecialchars($pg_label, ENT_QUOTES, 'UTF-8'); ?>">
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item<?php echo $pg_page <= 1 ? ' disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $__link(max(1, $pg_page - 1)); ?>" aria-label="Página anterior">Anterior</a>
        </li>
        <?php foreach ($__pages as $__p): ?>
            <?php if ($__p === 0): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php else: ?>
                <li class="page-item<?php echo $__p === $pg_page ? ' active' : ''; ?>">
                    <a class="page-link" href="<?php echo $__link($__p); ?>"<?php echo $__p === $pg_page ? ' aria-current="page"' : ''; ?>><?php echo $__p; ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item<?php echo $pg_page >= $pg_total ? ' disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $__link(min($pg_total, $pg_page + 1)); ?>" aria-label="Próxima página">Próximo</a>
        </li>
    </ul>
</nav>
