    </main>

    <footer class="ss-footer">
        <div class="container-fluid px-3 px-md-4">
            <div class="ss-footer-grid">
                <div>
                    <div class="ss-footer-brand">
                        <span class="ss-brand-mark small" aria-hidden="true"><i class="bi bi-hexagon"></i></span>
                        <div>
                            <strong><?php echo htmlspecialchars(constant("cTitle")); ?></strong>
                        </div>
                    </div>
                </div>
                <div class="ss-footer-meta">
                    <small class="d-flex align-items-center gap-1">
                        <span class="footer-status-dot"></span>
                        Sistema operacional
                    </small>
                    <small>Mobile-first</small>
                    <small>v1.0</small>
                    <?php
                    // SITE_CANONICAL_URL e a URL publica do site (nao do painel).
                    // Termos/Privacidade sao rotas do site — sem a URL configurada,
                    // esconde os links em vez de emitir href quebrado (o fallback '/'
                    // anterior gerava '//termos-de-uso', que o navegador le como o
                    // host 'termos-de-uso').
                    $_site_base = defined('SITE_CANONICAL_URL') ? rtrim((string) constant('SITE_CANONICAL_URL'), '/') : '';
                    if ($_site_base !== ''):
                    ?>
                        <small>
                            <a href="<?php echo htmlspecialchars($_site_base); ?>/termos-de-uso" target="_blank" rel="noopener">Termos de Uso</a>
                            |
                            <a href="<?php echo htmlspecialchars($_site_base); ?>/politica-de-privacidade" target="_blank" rel="noopener">Política de Privacidade</a>
                        </small>
                    <?php endif; ?>
                    <small><!-- WHITELABEL: preencha responsável e contato --><?php echo htmlspecialchars(constant('cTitle')); ?> | Contato: contato@example.com</small>
                </div>
            </div>
        </div>
    </footer>
