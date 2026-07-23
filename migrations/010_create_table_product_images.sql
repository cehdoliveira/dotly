CREATE TABLE IF NOT EXISTS `product_images` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `products_id` INT NOT NULL,
    `path` VARCHAR(255) NOT NULL,
    `is_cover` ENUM('yes', 'no') NOT NULL DEFAULT 'no',
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`),
    KEY `idx_product_images_product` (`products_id`, `active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
