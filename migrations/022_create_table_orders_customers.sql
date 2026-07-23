-- Plano 009: junção orders <-> customers (owner=orders, pois o save_attach no
-- checkout e chamado a partir do orders_model). Modelada em
-- 004_create_table_users_profiles.sql. Relacionamento SEMPRE via tabela de
-- junção — sem coluna orders.customers_id inline.
CREATE TABLE IF NOT EXISTS `orders_customers` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `orders_id` INT NOT NULL,
    `customers_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_orders_id` (`orders_id`),
    KEY `idx_customers_id` (`customers_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_orders_customers` (`orders_id`, `customers_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Relacao pedido <-> cliente';

-- Backfill dos links a partir do CPF (best-effort).
INSERT IGNORE INTO `orders_customers` (`created_at`, `created_by`, `active`, `orders_id`, `customers_id`)
SELECT NOW(), 0, 'yes', o.`idx`, c.`idx`
FROM `orders` o
JOIN `customers` c ON c.`cpf` = o.`customer_cpf` AND c.`active` = 'yes'
WHERE o.`customer_cpf` <> '';
