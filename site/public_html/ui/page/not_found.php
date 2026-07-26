<?php
// not_found.php — pagina de 404 (plano 019). Renderizada pelo fallback do
// dispatcher em index.php DENTRO do chrome padrao do site (head+header ja
// incluidos antes, footer+foot depois) — ao contrario de sales_closed.php, que
// e standalone (so head.php, sem nav). Por isso este arquivo e um fragmento
// (sem </head>/<body>/<html> proprios), reaproveitando as mesmas classes de
// card de sales_closed.php.
$homeUrl = htmlspecialchars($GLOBALS['home_url'] ?? '/', ENT_QUOTES, 'UTF-8');
?>
<style>
    .not-found-page {
        min-height: 60vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 1rem;
    }

    .not-found-card {
        max-width: 440px;
        width: 100%;
        text-align: center;
    }

    .not-found-icon-badge {
        width: 88px;
        height: 88px;
        margin: 0 auto 1.5rem;
        background: var(--accent-dim);
        border: 1px solid var(--border-accent);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--accent);
        font-size: 2.1rem;
    }

    .not-found-title {
        font-size: 1.4rem;
        line-height: 1.3;
        margin-bottom: 0.75rem;
    }

    .not-found-subtitle {
        color: var(--text-muted);
        font-size: 0.9rem;
        line-height: 1.6;
        margin-bottom: 1.75rem;
    }

    .not-found-cta {
        display: block;
        width: 100%;
    }
</style>

<div class="not-found-page">
    <div class="not-found-card">
        <div class="not-found-icon-badge" aria-hidden="true">
            <i class="bi bi-compass"></i>
        </div>

        <h1 class="not-found-title">Página não encontrada</h1>

        <p class="not-found-subtitle">O endereço acessado não existe ou não está mais disponível.</p>

        <a href="<?php echo $homeUrl; ?>" class="btn btn-accent not-found-cta">
            Voltar para a loja
        </a>
    </div>
</div>
