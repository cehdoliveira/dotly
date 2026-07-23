<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f7f7fb">
    <?php if (!empty($noindex)): ?>
        <meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <title><?php echo htmlspecialchars(constant("cTitle")); ?></title>

    <link rel="icon" type="image/svg+xml" href="<?php printf("%s%s", constant("cFrontend"), "assets/img/favicon.svg"); ?>">
    <link rel="shortcut icon" href="<?php printf("%s%s", constant("cFrontend"), "assets/img/favicon.svg"); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.min.css">

    <link rel="stylesheet" href="<?php printf("%sassets/css/main.css?v=%s", constant("cFrontend"), constant("APP_VERSION")); ?>">
