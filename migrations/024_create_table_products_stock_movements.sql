-- Plano 010: juncao produto <-> movimento de estoque. Nomeada com o "pai"
-- primeiro (products_...) para products_model.attach(['stock_movements'])
-- funcionar sem reverse_table (mesmo padrao de users_profiles/products_categories).

CREATE TABLE IF NOT EXISTS `products_stock_movements` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') NOT NULL DEFAULT 'yes',
    `products_id` INT NOT NULL,
    `stock_movements_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_products_id` (`products_id`),
    KEY `idx_stock_movements_id` (`stock_movements_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_products_stock_movements` (`products_id`, `stock_movements_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
