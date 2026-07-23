-- Plano 008: a taxa Infinity so incide quando o carrinho tiver produto(s)
-- Infinity. O schema atual nao tem como identificar isso — adotamos a opcao
-- recomendada do plano: flag por produto. Marcacao manual pelo admin
-- (checkbox no form de produtos) fica como follow-up do Plano 007/produtos.
--
-- Idempotencia da propria migration: ADD COLUMN atras de checagem em
-- information_schema (mesmo padrao de 015_add_customer_cpf_to_orders.sql).

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'is_infinity'
);
SET @ddl := IF(
    @col_exists = 0,
    "ALTER TABLE `products` ADD COLUMN `is_infinity` ENUM('yes','no') NOT NULL DEFAULT 'no' AFTER `category`",
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
