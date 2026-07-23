    </main>

    <footer class="infinnityimportacao-footer">
        <div class="container-fluid px-3 px-md-4">
            <div class="infinnityimportacao-footer-grid">
                <div>
                    <div class="infinnityimportacao-footer-brand">
                        <span class="infinnityimportacao-brand-mark small" aria-hidden="true"><i class="bi bi-hexagon"></i></span>
                        <div>
                            <strong><?php echo htmlspecialchars(constant("cTitle")); ?></strong>
                        </div>
                    </div>
                </div>
                <div class="infinnityimportacao-footer-meta">
                    <small class="d-flex align-items-center gap-1">
                        <span class="footer-status-dot"></span>
                        Sistema operacional
                    </small>
                    <small>Mobile-first</small>
                    <small>v1.0</small>
                    <small>
                        <a href="<?php echo defined('SITE_URL') ? htmlspecialchars(constant('SITE_URL')) : '/'; ?>/termos-de-uso" target="_blank" rel="noopener">Termos de Uso</a>
                        |
                        <a href="<?php echo defined('SITE_URL') ? htmlspecialchars(constant('SITE_URL')) : '/'; ?>/politica-de-privacidade" target="_blank" rel="noopener">Política de Privacidade</a>
                    </small>
                    <small><!-- WHITELABEL: preencha responsável e contato --><?php echo htmlspecialchars(constant('cTitle')); ?> | Contato: contato@example.com</small>
                </div>
            </div>
        </div>
    </footer>
