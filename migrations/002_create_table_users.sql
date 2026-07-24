-- Tabela para registrar os usuários do sistema
CREATE TABLE IF NOT EXISTS `users` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `mail` VARCHAR(255) NOT NULL DEFAULT '-',
    `login` VARCHAR(255) DEFAULT NULL,
    `password` VARCHAR(255) DEFAULT NULL,
    `name` VARCHAR(255) DEFAULT NULL,
    `last_login` DATETIME DEFAULT NULL,
    `phone` VARCHAR(255) DEFAULT NULL,
    `genre` ENUM('wait', 'male', 'female') NOT NULL DEFAULT 'wait',
    `enabled` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `email_token` VARCHAR(64) NULL DEFAULT NULL,
    `email_verified_at` DATETIME NULL DEFAULT NULL,
    `email_token_expires_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`idx`),
    UNIQUE KEY `mail_UNIQUE` (`mail`),
    UNIQUE KEY `login_UNIQUE` (`login`)
);

-- INSERT IGNORE: `mail` já é UNIQUE, então uma re-execução desta migration
-- (ex.: por estado inconsistente em migrations_log) não lança erro de duplicata.
-- Seed brand-neutral: nome 'Admin' e email 'admin@example.com' (placeholders
-- substituíveis pelo init-whitelabel.sh).
-- Senha bootstrap: admin123 (hash bcrypt abaixo). DEVE ser trocada no primeiro
-- login via Configurações > Alterar Senha — ver auth_controller::set_password()
-- (manager/app/inc/controller/auth_controller.php) e config_controller (linha 245).
-- ATTENTION: este hash é público no repo. Cada marca instanciada via
-- init-whitelabel.sh nasce com admin123 por conveniência de dev; o operador
-- é responsável por trocar a senha antes de qualquer deploy público.
INSERT IGNORE INTO
    `users` (
        `created_at`,
        `created_by`,
        `active`,
        `mail`,
        `login`,
        `password`,
        `name`,
        `enabled`
    )
VALUES (
        NOW(),
        '0',
        'yes',
        'admin@example.com',
        'admin',
        '$2y$12$T/wUIJS2YH8kjPvL3rKLZu3d35hyKViTczmLaPMlbtQIx0omZKQP.',
        'Admin',
        'yes'
    );
