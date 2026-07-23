-- Plano 010: ledger de movimentacoes de estoque (entrada/saida). Nao guarda
-- FK inline para produto/pedido — relacionamento e sempre via tabela de
-- juncao (regra do dono), ver 024/025_create_table_*_stock_movements.sql.

CREATE TABLE IF NOT EXISTS `stock_movements` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') DEFAULT 'yes',
    `kind` ENUM('entrada','saida') NOT NULL,
    `qty` INT NOT NULL,               -- sempre positivo; `kind` diz a direcao
    `note` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`idx`),
    KEY `idx_stock_mov_kind` (`kind`, `active`),
    CONSTRAINT `chk_stock_mov_qty_positive` CHECK (`qty` > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
