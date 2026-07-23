-- Plano 024: remove o modulo /estoque e o ledger stock_movements. O checkout
-- ja garante nao vender sem estoque (SELECT ... FOR UPDATE + UPDATE
-- products.stock em site/checkout_controller.php) e o CRUD de produtos
-- permite editar o estoque direto — o ledger de auditoria e o alerta de
-- estoque baixo (stock_min) eram redundantes com esse fluxo.
--
-- DROP TABLE IF EXISTS ja e idempotente por natureza (mesmo padrao de
-- 030_drop_customers_tables.sql). Juncoes primeiro, tabela principal depois.

DROP TABLE IF EXISTS products_stock_movements;
DROP TABLE IF EXISTS orders_stock_movements;
DROP TABLE IF EXISTS stock_movements;

-- DROP COLUMN atras de checagem em information_schema (mesmo padrao de
-- 026_add_stock_min_to_products.sql, condicao invertida).
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'stock_min'
);
SET @ddl := IF(
    @col_exists > 0,
    'ALTER TABLE `products` DROP COLUMN `stock_min`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
