-- Taxonomia de categorias de produto. Ate aqui a categoria era o VARCHAR livre
-- `products.category`, digitado a cada cadastro: a mesma categoria coexistia
-- como "Peptideos", "peptideos" e "Peptídeos ", o filtro da listagem (igualdade
-- exata) nao casava entre elas, e nao havia como criar uma categoria antes de
-- existir produto nela.
--
-- UNIQUE funcional uniq_categories_active_name: so linhas ATIVAS colidem entre
-- si pelo nome — uma linha soft-deletada (active='no') vira NULL e varios NULL
-- convivem num UNIQUE, entao da pra remover e recriar a mesma categoria depois.
-- Mesmo padrao de `blocked_customers` (migration 013).
--
-- A colacao utf8mb4_unicode_ci e case/accent-insensitive: "Peptideos" e
-- "peptideos" sao a MESMA chave. Isso e desejado — o backfill abaixo dedupe as
-- variantes de caixa que existem hoje, mantendo a primeira encontrada.

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
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uniq_categories_active_name` ((IF(`active` = 'yes', `name`, NULL)))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Backfill: semeia a taxonomia com as categorias que ja existem em produtos
-- ativos. Sem isto, a migration 016 nao teria o que ligar e todo produto ficaria
-- sem categoria depois do DROP COLUMN da 017.
INSERT IGNORE INTO `categories` (`created_at`, `created_by`, `active`, `name`)
SELECT NOW(), 0, 'yes', TRIM(p.`category`)
FROM `products` p
WHERE p.`active` = 'yes' AND TRIM(p.`category`) <> ''
GROUP BY TRIM(p.`category`);
