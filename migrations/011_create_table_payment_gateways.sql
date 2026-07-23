CREATE TABLE IF NOT EXISTS `payment_gateways` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `name` VARCHAR(40) NOT NULL,
    `slug` VARCHAR(40) NOT NULL UNIQUE,
    `mode` ENUM('qr', 'redirect') NOT NULL DEFAULT 'qr',
    `enabled` ENUM('yes', 'no') NOT NULL DEFAULT 'no',
    `monthly_limit_cents` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT IGNORE INTO `payment_gateways`
    (`created_at`, `created_by`, `active`, `name`, `slug`, `mode`, `enabled`, `monthly_limit_cents`)
VALUES
    (NOW(), 0, 'yes', 'Mercado Pago', 'mercadopago', 'qr',       'no', 0),
    (NOW(), 0, 'yes', 'PagBank',      'pagbank',     'qr',       'no', 0),
    (NOW(), 0, 'yes', 'InfinitePay',  'infinitepay', 'redirect', 'no', 0);
