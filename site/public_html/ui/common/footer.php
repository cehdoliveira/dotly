    </main>

    <footer class="ss-footer">
        <div class="container">
            <div class="ss-footer-inner">
                <div class="ss-footer-brand">
                    <span class="brand-logo brand-logo-sm" aria-hidden="true"><?php readfile(__DIR__ . '/../../assets/img/favicon.svg'); ?></span>
                    <span><?php echo htmlspecialchars(constant('cStoreName')); ?> &copy; <?php echo date('Y'); ?></span>
                </div>
                <div class="ss-footer-links">
                    <a href="https://wa.me/<?php echo htmlspecialchars(constant('whatsapp_number'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Atendimento: <?php echo htmlspecialchars(constant('whatsapp_display'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>
        </div>
    </footer>
