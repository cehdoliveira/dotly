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
    UNIQUE KEY `mail_UNIQUE` (`mail`)
);

-- INSERT IGNORE: `mail` já é UNIQUE, então uma re-execução desta migration
-- (ex.: por estado inconsistente em migrations_log) não lança erro de duplicata.
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
        'admin@dotly.com.br',
        'admin',
        '!disabled!',
        'Dotly Admin',
        'no'
    );
