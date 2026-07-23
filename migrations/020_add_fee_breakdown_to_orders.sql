-- Plano 008: total_cents deixa de ser so o subtotal das linhas e passa a
-- incluir 8% + R$60 fixo + taxa Infinity (parametrizavel). Persistimos o
-- breakdown para auditoria/exibicao (checkout.php e done.php).
--
-- Idempotencia da propria migration: cada ADD COLUMN atras de uma checagem em
-- information_schema (mesmo padrao de 015_add_customer_cpf_to_orders.sql).

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'subtotal_cents'
);
SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `orders` ADD COLUMN `subtotal_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ship_uf`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists2 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'fee_percent_cents'
);
SET @ddl2 := IF(
    @col_exists2 = 0,
    'ALTER TABLE `orders` ADD COLUMN `fee_percent_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `subtotal_cents`',
    'DO 0'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SET @col_exists3 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'fee_fixed_cents'
);
SET @ddl3 := IF(
    @col_exists3 = 0,
    'ALTER TABLE `orders` ADD COLUMN `fee_fixed_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `fee_percent_cents`',
    'DO 0'
);
PREPARE stmt3 FROM @ddl3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

SET @col_exists4 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'fee_infinity_cents'
);
SET @ddl4 := IF(
    @col_exists4 = 0,
    'ALTER TABLE `orders` ADD COLUMN `fee_infinity_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `fee_fixed_cents`',
    'DO 0'
);
PREPARE stmt4 FROM @ddl4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;
