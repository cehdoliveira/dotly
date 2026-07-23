-- Plano 016: rastreio de envio. Estado "enviado" e derivado (shipped_at IS NOT
-- NULL), nao um novo valor no enum orders.status — a maquina de status de
-- pagamento continua transicionada so pelo webhook (fechado com o dono).
--
-- Idempotencia da propria migration: cada ADD COLUMN atras de uma checagem em
-- information_schema (mesmo padrao de 015_add_customer_cpf_to_orders.sql).

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'tracking_code'
);
SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `orders` ADD COLUMN `tracking_code` VARCHAR(64) DEFAULT NULL AFTER `paid_at`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists2 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'shipped_at'
);
SET @ddl2 := IF(
    @col_exists2 = 0,
    'ALTER TABLE `orders` ADD COLUMN `shipped_at` DATETIME DEFAULT NULL AFTER `tracking_code`',
    'DO 0'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
