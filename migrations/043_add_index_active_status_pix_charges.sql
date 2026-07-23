-- TODOS.md #5 / Plano 034: OrderReconciler varre cobrancas pendentes a cada
-- tick do cron com o filtro `WHERE pc.active = 'yes' AND pc.status =
-- 'pendente'` sobre pix_charges. A tabela nao tem nenhum indice que cubra
-- esse filtro (so uq_pix_charge_gateway, idx_pix_charges_order e
-- uq_pix_charge_transaction_nsu — nenhum comeca por active+status).
-- pix_charges so cresce (uma linha por cobranca PIX, para sempre,
-- soft-delete); sem este indice o filtro tende a escanear mais linhas
-- conforme a tabela cresce. Este indice composto (active, status) permite
-- ao job encontrar as poucas cobrancas pendentes direto pelo indice em vez
-- de varrer o historico de cobrancas ja pagas/expiradas.
--
-- Idempotencia: checagem em information_schema (mesmo padrao de
-- 037_add_composite_index_clientes_orders.sql).

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pix_charges'
      AND INDEX_NAME = 'idx_pix_charges_active_status'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `pix_charges` ADD KEY `idx_pix_charges_active_status` (`active`, `status`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
