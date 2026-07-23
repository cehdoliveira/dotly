-- Decisao do dono (2026-07-15): CPF passa a ser campo obrigatorio do checkout,
-- padrao para todo comprador (nao so quem cai no PagBank). So digitos, sem
-- mascara — a view formata na exibicao, igual total_cents.
--
-- Idempotencia da propria migration: guardamos o ADD COLUMN atras de uma
-- checagem em information_schema, montando o ALTER via SQL dinamico apenas
-- quando a coluna ainda nao existe (mesmo padrao de 006_add_unique_constraints.sql).

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'customer_cpf'
);
SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `orders` ADD COLUMN `customer_cpf` CHAR(11) NOT NULL AFTER `customer_phone`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
