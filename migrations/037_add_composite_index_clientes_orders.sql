-- TODOS.md #2 / Plano 029: customers_controller::index() agrupa orders por
-- customer_mail (WHERE active='yes' GROUP BY customer_mail, com MAX(idx)) duas
-- vezes por request (COUNT + página). O índice existente idx_orders_customer_mail
-- (migration 029) cobre o lookup de e-mail único do rastreio público, mas não o
-- GROUP BY do agregado de /clientes — que faz table scan a cada carregamento.
-- Este índice composto (active, customer_mail, idx) é covering para a subquery de
-- agrupamento: filtra active, agrupa por customer_mail sem sort, MAX(idx) do índice.
--
-- Idempotência: checagem em information_schema (mesmo padrão de 029).

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_active_mail_idx'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `orders` ADD KEY `idx_orders_active_mail_idx` (`active`, `customer_mail`, `idx`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
