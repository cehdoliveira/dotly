-- Plano 010: juncao pedido <-> movimento de estoque (so usada em movimentos
-- de saida por venda). Mesmo padrao de products_stock_movements.

CREATE TABLE IF NOT EXISTS `orders_stock_movements` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') NOT NULL DEFAULT 'yes',
    `orders_id` INT NOT NULL,
    `stock_movements_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_orders_id` (`orders_id`),
    KEY `idx_stock_movements_id` (`stock_movements_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_orders_stock_movements` (`orders_id`, `stock_movements_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
