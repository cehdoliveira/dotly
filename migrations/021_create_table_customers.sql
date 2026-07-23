-- Plano 009: site (frontend publico) nao tem login usuario+senha para quem
-- compra — precisamos reconhecer clientes recorrentes sem cadastro. Chave
-- natural = CPF normalizado (ja obrigatorio e normalizado no checkout);
-- nome nao serve de chave (homonimos). Telefone fica so indexado (mais
-- volatil que CPF).
CREATE TABLE IF NOT EXISTS `customers` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `cpf` CHAR(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `mail` VARCHAR(255) NOT NULL DEFAULT '',
    `phone` VARCHAR(20) NOT NULL DEFAULT '',
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uq_customers_cpf` (`cpf`),
    KEY `idx_customers_phone` (`phone`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Backfill (best-effort): 1 cliente por CPF distinto, dados do pedido mais recente.
INSERT IGNORE INTO `customers` (`created_at`, `created_by`, `active`, `cpf`, `name`, `mail`, `phone`)
SELECT NOW(), 0, 'yes', o.`customer_cpf`, o.`customer_name`, o.`customer_mail`, o.`customer_phone`
FROM `orders` o
JOIN (SELECT `customer_cpf`, MAX(`idx`) AS max_idx FROM `orders`
      WHERE `customer_cpf` <> '' GROUP BY `customer_cpf`) latest
  ON latest.max_idx = o.`idx`;
