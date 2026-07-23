CREATE TABLE IF NOT EXISTS `categories` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `name` VARCHAR(60) NOT NULL,
    `slug` VARCHAR(80) NOT NULL UNIQUE,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`),
    KEY `idx_categories_active_sort` (`active`, `sort_order`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Backfill (best-effort; DEV pode dropar a base): 1 categoria por string distinta.
-- Slug precisa bater com valid_slug() do app (^[a-z0-9]+(?:[-_][a-z0-9]+)*$), entao
-- o acento tem que ser removido igual ao remove_accents()/generate_slug() do PHP
-- (app/inc/lib/CommonFunctions.php + app/inc/lists.php) — nao so trocar espaco/barra.
INSERT IGNORE INTO `categories` (`created_at`, `created_by`, `active`, `name`, `slug`, `sort_order`)
SELECT NOW(), 0, 'yes', p.category,
       TRIM(BOTH '-' FROM REGEXP_REPLACE(
           REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
               LOWER(TRIM(p.category)),
               'á', 'a'), 'à', 'a'), 'ã', 'a'), 'â', 'a'), 'ä', 'a'),
               'é', 'e'), 'è', 'e'), 'ê', 'e'), 'ë', 'e'),
               'í', 'i'), 'ì', 'i'), 'î', 'i'), 'ï', 'i'),
               'ó', 'o'), 'ò', 'o'), 'õ', 'o'), 'ô', 'o'), 'ö', 'o'),
               'ú', 'u'), 'ù', 'u'), 'û', 'u'), 'ü', 'u'),
               'ç', 'c'), 'ñ', 'n'),
           '[^a-z0-9]+', '-'
       )), 0
FROM (SELECT DISTINCT `category` FROM `products` WHERE `category` <> '') AS p;
