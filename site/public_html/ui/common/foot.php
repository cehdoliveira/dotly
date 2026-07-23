    <!-- Bootstrap 5.3.3 JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- SweetAlert2 11.14.5 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js" integrity="sha384-YB/DdIkloKoRpclWB8bNcYXWakt57USgtQPDzvnIDHYU0lasD5eWlXVo1S4ODukY" crossorigin="anonymous"></script>

    <!-- shopController.js: header.php (incluido em toda pagina) depende de
         $store.shop pro botao "Pedido" e pro drawer — carrega sempre, nao so
         nas paginas que listam 'shop' em $alpineControllers. Sem isso, paginas
         como /pagamento/{token}, /termos ou /entrar quebram o clique no header
         (plano 006, achado no /ship: $store.shop indefinido = expressao Alpine
         lanca erro e o botao Pedido para de responder). -->
    <script src="<?php echo constant('cFrontend'); ?>assets/js/alpine/shopController.js?v=<?php echo constant('APP_VERSION'); ?>"></script>

    <!-- Alpine.js Controllers - Carregamento Dinâmico -->
    <?php
    if (isset($alpineControllers) && is_array($alpineControllers) && count($alpineControllers) > 0) {
        $cacheBust = '?v=' . constant('APP_VERSION');
        foreach ($alpineControllers as $controller) {
            if ($controller === 'shop') {
                continue; // ja carregado incondicionalmente acima
            }
            $safeController = preg_replace('/[^a-zA-Z0-9_-]/', '', $controller);
            print('<script src="' . constant('cFrontend') . 'assets/js/alpine/' . $safeController . 'Controller.js' . $cacheBust . '"></script>' . "\n    ");
        }
    }
    ?>

    <!-- Alpine.js 3.14.9 -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js" integrity="sha384-9Ax3MmS9AClxJyd5/zafcXXjxmwFhZCdsT6HJoJjarvCaAkJlk5QDzjLJm+Wdx5F" crossorigin="anonymous"></script>

    <!-- Custom JS -->
    <script src="<?php printf("%s%s", constant('cFrontend'), "assets/js/main.js"); ?>"></script>
    </body>

    </html>
