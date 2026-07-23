-- Deteccao de pico de pedidos pagos (smurfing) para desvio de roteamento
-- (plano 043). Nao bloqueia venda: apenas tira gateways sensiveis a pico
-- (avoid_on_spike='yes') do sorteio do GatewayRouter enquanto a janela dos
-- ultimos 60 minutos tiver N ou mais pedidos pagos, N configurado em
-- settings.velocity_paid_orders_per_hour (0 = detecao desligada, default
-- seguro; o dono liga via UPDATE direto quando quiser).
--
-- Tambem fecha o item em aberto do /ship do plano 011: orders nao tinha
-- indice cobrindo (status, paid_at), usado tanto pelo calculo de MTD do
-- GatewayRouter quanto pela nova query de velocity deste plano.
--
-- Idempotencia: checagem em information_schema (mesmo padrao de
-- 045_add_max_order_cents_to_payment_gateways.sql e
-- 043_add_index_active_status_pix_charges.sql).
--
-- NOTA DE DEPLOY (achado do /ship, mesmo padrao de 045): payment_gateways_model.php
-- e GatewayRouter::pick() passam a selecionar avoid_on_spike sempre. Se o
-- codigo novo subir ANTES desta migration rodar, qualquer load_data() em
-- payment_gateways quebra com "unknown column" ate a migration (cron a cada
-- 5min, ver CLAUDE.md) alcancar — inclusive o checkout (pick() a cada pedido)
-- e o webhook (payment_gateways_model em webhook_controller.php). Rodar a
-- migration antes (ou junto) do deploy do codigo evita a janela.

-- 1) Coluna avoid_on_spike em payment_gateways
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_gateways'
      AND COLUMN_NAME = 'avoid_on_spike'
);
SET @add_column_ddl := IF(
    @col_exists = 0,
    "ALTER TABLE `payment_gateways` ADD COLUMN `avoid_on_spike` ENUM('yes','no') NOT NULL DEFAULT 'no' AFTER `max_order_cents`",
    'DO 0'
);
PREPARE stmt FROM @add_column_ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Marca o Mercado Pago como sensivel a pico (roda uma vez so; migrations
-- sao tracked em migrations_log, entao um UPDATE direto do dono depois desta
-- migration nao e revertido em re-execucoes).
UPDATE `payment_gateways` SET `avoid_on_spike` = 'yes' WHERE `slug` = 'mercadopago';

-- 3) Setting do threshold — 0 = detecao desligada (default seguro).
INSERT IGNORE INTO `settings` (`created_at`, `created_by`, `active`, `skey`, `svalue`) VALUES
    (NOW(), 0, 'yes', 'velocity_paid_orders_per_hour', '0');

-- 4) Indice (status, paid_at) em orders — usado pelo MTD do GatewayRouter e
-- pela nova query de velocity (COUNT de pedidos pagos na ultima hora).
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_status_paid'
);
SET @idx_ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `orders` ADD KEY `idx_orders_status_paid` (`status`, `paid_at`)',
    'DO 0'
);
PREPARE stmt FROM @idx_ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
