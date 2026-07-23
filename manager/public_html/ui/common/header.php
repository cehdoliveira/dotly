</head>

<body>
    <header>
        <nav class="navbar infinnityimportacao-navbar">
            <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
                <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo $GLOBALS['home_url']; ?>">
                    <span class="brand-logo" aria-hidden="true"><?php readfile(__DIR__ . '/../../assets/img/logo.svg'); ?></span>
                    <span class="brand-name"><?php echo htmlspecialchars(constant('cTitle')); ?></span>
                </a>
                <?php if (auth_controller::check_login()) { ?>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="theme-toggle-btn" data-theme-toggle="true"
                            title="Alternar tema" aria-label="Ativar tema claro">
                            <i class="bi bi-sun"></i><span class="d-none d-md-inline ms-1">Claro</span>
                        </button>
                        <a href="<?php echo htmlspecialchars($GLOBALS['config_url'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-sm btn-outline-secondary"
                            style="font-size:0.8rem; padding:0.3rem 0.75rem;"
                            title="Configurações">
                            <i class="bi bi-gear me-1"></i><span class="d-none d-md-inline">Configurações</span>
                        </a>
                        <form method="POST" action="<?php echo $GLOBALS['logout_url']; ?>" style="display:inline;">
                            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                style="font-size:0.8rem; padding:0.3rem 0.75rem; border:none; background:none; cursor:pointer;">
                                <i class="bi bi-box-arrow-right me-1"></i>Sair
                            </button>
                        </form>
                    </div>
                <?php } else { ?>
                    <a class="btn btn-sm btn-outline-secondary"
                        href="<?php echo $GLOBALS['login_url']; ?>"
                        style="font-size:0.8rem; color:var(--text-muted); border-color:var(--border);">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Entrar
                    </a>
                <?php } ?>
            </div>
        </nav>
    </header>

    <main id="mainContent" class="flex-shrink-0">
