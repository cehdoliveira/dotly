-- `products.category` foi substituida pela taxonomia (`categories` +
-- `products_categories`, migrations 015 e 016). A partir daqui o nome da
-- categoria e lido por subquery escalar aliasada `category`
-- (products_model::CATEGORY_NAME_FIELD), entao o contrato `$product['category']`
-- das views continua valendo — o que sai e a COLUNA, nao o campo.
--
-- Rodar SOMENTE depois da 015 e da 016: o backfill da 016 le esta coluna.

ALTER TABLE `products` DROP COLUMN `category`;
