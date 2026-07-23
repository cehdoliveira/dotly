-- Teto opcional de valor por gateway no roteamento (plano 042). NULL = sem
-- teto (comportamento atual, preservado para os 3 gateways seed). Quando
-- preenchido (em centavos), o gateway sai do sorteio do GatewayRouter para
-- pedidos com total_cents acima do teto — mitigacao de congelamento de conta
-- em PSPs agressivos com ticket alto em conta nova. Configuravel pelo dono
-- via /config no manager; sem seed de valor aqui.
--
-- Idempotencia: checagem em information_schema.COLUMNS (mesmo padrao de
-- 042_add_transaction_nsu_to_pix_charges.sql).
--
-- NOTA DE DEPLOY (achado do /ship): payment_gateways_model.php e
-- GatewayRouter::pick() passam a selecionar max_order_cents sempre. Se o
-- codigo novo subir ANTES desta migration rodar, qualquer load_data() em
-- payment_gateways quebra com "unknown column" ate a migration (cron a cada
-- 5min, ver CLAUDE.md) alcancar — inclusive o checkout, que chama pick() a
-- cada pedido. Rodar a migration antes (ou junto) do deploy do codigo evita
-- a janela.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_gateways'
      AND COLUMN_NAME = 'max_order_cents'
);
SET @add_column_ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `payment_gateways` ADD COLUMN `max_order_cents` BIGINT UNSIGNED DEFAULT NULL AFTER `monthly_limit_cents`',
    'DO 0'
);
PREPARE stmt FROM @add_column_ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
