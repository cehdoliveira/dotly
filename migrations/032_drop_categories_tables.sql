-- Plano 023: remove o CRUD /categorias e a taxonomia normalizada. O filtro
-- publico da vitrine sempre leu a string denormalizada `products.category`
-- (nunca a taxonomia); o form de produto volta a ter `category` como
-- texto livre, entao categories/products_categories perdem o unico leitor.
--
-- DROP TABLE IF EXISTS ja e idempotente por natureza (mesmo padrao de
-- 030_drop_customers_tables.sql e 031_drop_stock_ledger.sql). Juncao
-- primeiro, tabela principal depois.

DROP TABLE IF EXISTS products_categories;
DROP TABLE IF EXISTS categories;
