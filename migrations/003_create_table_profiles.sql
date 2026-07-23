-- Tabela de perfis de acesso — define papéis e permissões de usuários
-- Inclui perfis de sistema (admin, user) e perfis de assinatura (trial, monthly, annual, lifetime)
CREATE TABLE IF NOT EXISTS `profiles` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `name` VARCHAR(255) DEFAULT NULL,
    `editabled` ENUM('yes', 'no') DEFAULT 'yes',
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `adm` ENUM('yes', 'no') DEFAULT 'no',
    `parent` INT DEFAULT '0',
    PRIMARY KEY (`idx`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Perfis de sistema
-- INSERT IGNORE: com `slug` UNIQUE, re-execuções viram no-op em vez de
-- acumular perfis duplicados (admin/user).
-- editabled='no' para admin e user: perfis de sistema não são editáveis
-- pelo painel manager (fold da migration 008_lock_seed_profiles).
INSERT IGNORE INTO
    `profiles` (
        `created_at`,
        `created_by`,
        `active`,
        `name`,
        `editabled`,
        `slug`,
        `adm`,
        `parent`
    )
VALUES (
        NOW(),
        0,
        'yes',
        'Administrador',
        'no',
        'admin',
        'yes',
        0
    ),
    (
        NOW(),
        0,
        'yes',
        'Usuário',
        'no',
        'user',
        'no',
        0
    );
