<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f7f7fb">
    <title><?php echo htmlspecialchars(constant("cTitle")); ?></title>

    <link rel="icon" type="image/svg+xml" href="<?php printf("%s%s", constant("cFrontend"), "assets/img/favicon.svg"); ?>">
    <link rel="shortcut icon" href="<?php printf("%s%s", constant("cFrontend"), "assets/img/favicon.svg"); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.min.css">

    <?php
    // Cache-bust pelo mtime do arquivo: o nginx serve .css como immutable/1y, entao
    // sem versao na URL o Safari (e afins) trava o CSS velho pra sempre. Mesmo padrao
    // do JS no foot.php — qualquer alteracao no arquivo estoura o cache sozinha, sem
    // depender de bump manual de APP_VERSION.
    $__cssBust = static function (string $rel): string {
        $fs  = __DIR__ . '/../../' . $rel;
        $ver = is_file($fs) ? (string) filemtime($fs) : (defined('APP_VERSION') ? (string) constant('APP_VERSION') : '1');
        return constant('cFrontend') . $rel . '?v=' . $ver;
    };
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($__cssBust('assets/css/main.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($__cssBust('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Apply theme before render to prevent flash -->
    <script nonce="<?php echo htmlspecialchars($GLOBALS['cspNonce'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        (function() {
            var saved = localStorage.getItem('theme');
            var theme = saved ?
                saved :
                (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
