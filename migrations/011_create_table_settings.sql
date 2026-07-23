CREATE TABLE IF NOT EXISTS `settings` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') DEFAULT 'yes',
    `skey` VARCHAR(60) NOT NULL UNIQUE,
    `svalue` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`idx`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`created_at`, `created_by`, `active`, `skey`, `svalue`) VALUES
    (NOW(), 0, 'yes', 'fee_percent_bps',              '1000'),    -- 10% em basis points (fold de 040)
    (NOW(), 0, 'yes', 'fee_fixed_cents',              '6000'),    -- R$ 60,00
    (NOW(), 0, 'yes', 'fee_infinity_bps',             '0'),       -- taxa Infinity — PARAMETRIZAVEL, ajustar no manager/DB
    (NOW(), 0, 'yes', 'sales_override',               ''),        -- fold de 044: override de venda
    (NOW(), 0, 'yes', 'sales_window_start_at',        ''),        -- fold de 044: início da janela de vendas
    (NOW(), 0, 'yes', 'sales_window_end_at',          ''),        -- fold de 044: fim da janela de vendas
    (NOW(), 0, 'yes', 'velocity_paid_orders_per_hour','0');       -- fold de 046: 0 = detecção desligada (default seguro)
