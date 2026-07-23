-- Plano 023: tela /clientes (compradores derivados de orders) reintroduz o
-- bloqueio de cliente. Como cliente NAO e uma entidade normalizada (as tabelas
-- customers/orders_customers foram dropadas de proposito no plano 022, migration
-- 030), o estado "bloqueado" nao tem onde morar numa linha de cliente. Esta
-- tabela e uma blocklist enxuta — nao um retorno da tabela customers: guarda so
-- os identificadores que o checkout compara (e-mail, CPF e telefone), populados
-- a partir do pedido mais recente do cliente no momento do bloqueio.
--
-- O checkout publico (site/checkout_controller::validateCustomer) rejeita o
-- pedido se QUALQUER um dos tres bater — por isso os tres tem indice proprio.
-- customer_cpf/customer_phone default '' para o match "<> '' AND = ?" nunca
-- casar linhas vazias entre si.
--
-- DROP/CREATE TABLE IF NOT EXISTS ja e idempotente por natureza (nao precisa da
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
    KEY `idx_blocked_customers_mail` (`customer_mail`),
    KEY `idx_blocked_customers_cpf` (`customer_cpf`),
    KEY `idx_blocked_customers_phone` (`customer_phone`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
