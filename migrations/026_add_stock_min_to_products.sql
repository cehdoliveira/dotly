-- Plano 010: limiar de "estoque baixo" parametrizavel por produto.
-- stock_min=0 = sem alerta; stock<=stock_min (e stock_min>0) = "acabando".
--
-- Idempotencia da propria migration: ADD COLUMN atras de checagem em
-- information_schema (mesmo padrao de 015_add_customer_cpf_to_orders.sql).

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'stock_min'
);
SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `stock_min` INT NOT NULL DEFAULT 0 AFTER `stock`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
