-- `products.category` foi substituida pela taxonomia (`categories` +
-- `products_categories`, migrations 015 e 016). A partir daqui o nome da
-- categoria e lido via DOLModel::attach(["categories"], ...) chamado direto
-- em cada controller que precisa dela, com normalizacao inline de volta pro
-- contrato `$product['category']` (string) que as views ja esperavam — o que
-- sai aqui e a COLUNA, nao o campo.
--
-- Rodar SOMENTE depois da 015 e da 016: o backfill da 016 le esta coluna.

ALTER TABLE `products` DROP COLUMN `category`;
