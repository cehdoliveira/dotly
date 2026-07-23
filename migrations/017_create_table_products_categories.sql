CREATE TABLE IF NOT EXISTS `products_categories` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `products_id` INT NOT NULL,
    `categories_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_products_id` (`products_id`),
    KEY `idx_categories_id` (`categories_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_products_categories` (`products_id`, `categories_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Relacao produto <-> categoria';

-- Backfill dos links a partir do rotulo atual (best-effort).
INSERT IGNORE INTO `products_categories` (`created_at`, `created_by`, `active`, `products_id`, `categories_id`)
SELECT NOW(), 0, 'yes', p.`idx`, c.`idx`
FROM `products` p
JOIN `categories` c ON c.`name` = p.`category` AND c.`active` = 'yes'
WHERE p.`category` <> '';
