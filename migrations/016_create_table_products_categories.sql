-- Relacao produto <-> categoria. Convencao do projeto: relacao entre duas
-- tabelas mora numa TERCEIRA tabela de attach, sem FOREIGN KEY — mesma forma de
-- `users_profiles` (migration 004).
--
-- Os nomes NAO sao livres: DOLModel::save_attach()/attach() derivam a tabela de
-- juncao de "<tabela>_<classe>" e as colunas de "<tabela>_id"/"<classe>_id".
-- Para products + categories isso e exatamente `products_categories`,
-- `products_id`, `categories_id`. Renomear qualquer um quebra o ORM em silencio.
--
-- UNIQUE uq_products_categories e requisito duro: save_attach() usa
-- "INSERT ... ON DUPLICATE KEY UPDATE active='yes'" para reativar uma ligacao
-- que ja existiu. Sem o UNIQUE, cada edicao de produto acumularia linha nova.
--
-- Hoje um produto tem no maximo UMA categoria (a UI so oferece um select). A
-- tabela de attach suporta N sem mudanca de schema se isso mudar.

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
    KEY `idx_products_categories_products_id` (`products_id`),
    KEY `idx_products_categories_categories_id` (`categories_id`),
    KEY `idx_products_categories_active` (`active`),
    UNIQUE KEY `uq_products_categories` (`products_id`, `categories_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Relação produto <-> categoria';

-- Backfill: liga cada produto ativo a categoria semeada na migration 015,
-- casando pelo nome (a colacao ci faz o match funcionar mesmo onde a caixa
-- divergia).
INSERT IGNORE INTO `products_categories` (`created_at`, `created_by`, `active`, `products_id`, `categories_id`)
SELECT NOW(), 0, 'yes', p.`idx`, c.`idx`
FROM `products` p
INNER JOIN `categories` c ON c.`name` = TRIM(p.`category`) AND c.`active` = 'yes'
WHERE p.`active` = 'yes' AND TRIM(p.`category`) <> '';
