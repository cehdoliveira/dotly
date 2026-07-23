CREATE TABLE IF NOT EXISTS `order_items` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `orders_id` INT NOT NULL,
    `products_id` INT NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `variant` ENUM('unit', 'box') NOT NULL DEFAULT 'unit',
    `qty` INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_price_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `line_total_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`),
    KEY `idx_order_items_order` (`orders_id`, `active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
