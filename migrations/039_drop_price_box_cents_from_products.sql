-- Preco de caixa deixa de ser um valor separado por produto: a variante "caixa"
-- passa a ser sempre 10 unidades ao preco unitario (preco_unitario * box_qty).
-- O campo "Preco caixa (opcional)" saiu do CRUD do manager e o site sempre
-- oferece "Caixa x10" — logo `price_box_cents` perde o unico leitor.
--
-- DROP COLUMN atras de checagem em information_schema (mesmo padrao de
-- 031_drop_stock_ledger.sql). `box_qty` (default 10) fica: o checkout ainda
-- multiplica a caixa por ele para travar estoque e derivar o preco.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'price_box_cents'
);
SET @ddl := IF(
    @col_exists > 0,
    'ALTER TABLE `products` DROP COLUMN `price_box_cents`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
