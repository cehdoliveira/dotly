-- Blocklist de clientes bloqueados no checkout. Populada a partir do pedido mais
-- recente do cliente no momento do bloqueio. O checkout público
-- (site/checkout_controller::validateCustomer) rejeita o pedido se QUALQUER um
-- dos três identificadores (e-mail, CPF, telefone) bater.
--
-- UNIQUE funcional uniq_blocked_customers_active_mail: só linhas ativas colidem
-- entre si pelo customer_mail — linhas soft-deletadas (active='no') viram NULL
-- (múltiplos NULL permitidos num UNIQUE), permitindo desbloquear e rebloquear.
--
-- DROP/CREATE TABLE IF NOT EXISTS já é idempotente por natureza (não precisa de
-- checagem via information_schema usada nas migrations de ALTER).

CREATE TABLE IF NOT EXISTS `blocked_customers` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `customer_mail` VARCHAR(255) NOT NULL,
    `customer_cpf` CHAR(11) NOT NULL DEFAULT '',
    `customer_phone` VARCHAR(20) NOT NULL DEFAULT '',
    `blocked_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_blocked_customers_cpf` (`customer_cpf`),
    KEY `idx_blocked_customers_phone` (`customer_phone`),
    UNIQUE KEY `uniq_blocked_customers_active_mail` ((IF(`active` = 'yes', `customer_mail`, NULL)))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
