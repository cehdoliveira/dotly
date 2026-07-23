<?php
// sales_closed.php — pagina standalone "vendas encerradas" (plano 037).
// Incluida direto pelo gate em index.php (head.php ja foi incluido antes
// desta view) quando SalesWindow::status() reporta vendas fechadas. NAO usa
// header/footer de navegacao — sem menu, sem carrinho.
$salesStatus = $salesStatus ?? ['open' => false, 'reopens_at' => null, 'reason' => null];

$reason = $salesStatus['reason'] ?? null;

$title = $reason === 'stock'
    ? 'Estoque esgotado! As vendas deste período foram encerradas.'
    : 'As vendas estão encerradas no momento.';

$icon = $reason === 'stock' ? 'bi-box-seam' : 'bi-calendar-event';

$reopensFormatted = null;
if (!empty($salesStatus['reopens_at'])) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string) $salesStatus['reopens_at']);
    if ($dt !== false) {
        $reopensFormatted = $dt->format('d/m/Y') . ' às ' . $dt->format('H\hi');
    }
}

$storeName    = htmlspecialchars(constant('cStoreName'), ENT_QUOTES, 'UTF-8');
$whatsappUrl  = 'https://wa.me/' . htmlspecialchars(constant('whatsapp_number'), ENT_QUOTES, 'UTF-8');
$trackOrderUrl = htmlspecialchars($GLOBALS['track_order_url'] ?? '/acompanhar-pedido', ENT_QUOTES, 'UTF-8');
?>
</head>
<body>
<style>
    .sales-closed-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 1rem;
    }

    .sales-closed-card {
        max-width: 440px;
        width: 100%;
        text-align: center;
    }

    @media (prefers-reduced-motion: no-preference) {
        .sales-closed-card {
            animation: sales-closed-fade-in 0.5s ease-out both;
        }
    }

    @keyframes sales-closed-fade-in {
        from {
            opacity: 0;
            transform: translateY(8px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .sales-closed-brand {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 2.75rem;
        font-family: var(--font-mono);
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--text-muted);
    }

    .sales-closed-icon-badge {
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

    .sales-closed-title {
        font-size: 1.4rem;
        line-height: 1.3;
        margin-bottom: 0.75rem;
    }

    .sales-closed-subtitle {
        color: var(--text-muted);
        font-size: 0.9rem;
        line-height: 1.6;
        margin-bottom: 1.75rem;
    }

    .sales-closed-reopen {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-family: var(--font-mono);
        font-size: 0.8rem;
        color: var(--accent-hover);
        background: var(--accent-dim);
        border: 1px solid var(--border-accent);
        border-radius: 2rem;
        padding: 0.5rem 1.1rem;
        margin-bottom: 2rem;
    }

    .sales-closed-reopen i {
        color: var(--secondary);
    }

    .sales-closed-cta {
        display: block;
        width: 100%;
    }

    .sales-closed-track {
        display: inline-block;
        margin-top: 1.5rem;
        font-size: 0.82rem;
        color: var(--text-muted);
        text-decoration: underline;
        text-underline-offset: 2px;
    }

    .sales-closed-track:hover {
        color: var(--accent);
    }
</style>

    <main class="sales-closed-page">
        <div class="sales-closed-card">
            <div class="sales-closed-brand">
                <span class="brand-logo brand-logo-sm" aria-hidden="true"><?php readfile(__DIR__ . '/../../assets/img/favicon.svg'); ?></span>
                <span><?php echo $storeName; ?></span>
            </div>

            <div class="sales-closed-icon-badge" aria-hidden="true">
                <i class="bi <?php echo $icon; ?>"></i>
            </div>

            <h1 class="sales-closed-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>

            <?php if ($reopensFormatted !== null): ?>
                <p class="sales-closed-subtitle">O próximo período de vendas abre em:</p>
                <div class="sales-closed-reopen">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($reopensFormatted, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php else: ?>
                <p class="sales-closed-subtitle">Avisaremos quando um novo período de vendas abrir.</p>
            <?php endif; ?>

            <a href="<?php echo $whatsappUrl; ?>" target="_blank" rel="noopener" class="btn btn-accent sales-closed-cta">
                Falar com o Atendimento
            </a>

            <a href="<?php echo $trackOrderUrl; ?>" class="sales-closed-track">Já comprou? Acompanhe seu pedido</a>
        </div>
    </main>
</body>

</html>
