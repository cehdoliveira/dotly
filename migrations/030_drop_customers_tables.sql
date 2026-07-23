-- Plano 022: remove a tela /clientes e as tabelas customers/orders_customers.
-- A lista de pedidos (colunas denormalizadas orders.customer_*, plano 019) e a
-- unica fonte de dados de comprador lida por qualquer view in-scope; estas
-- tabelas normalizadas nao tinham leitor fora da tela removida.
--
-- DROP TABLE IF EXISTS ja e idempotente por natureza (nao precisa da checagem
-- via information_schema usada em migrations de ALTER).

DROP TABLE IF EXISTS orders_customers;
DROP TABLE IF EXISTS customers;
