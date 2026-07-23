-- Revisao do /ship (re-run) identificou que o fix da race de bloqueio
-- concorrente (INSERT...SELECT...WHERE NOT EXISTS em customers_controller::
-- action) nao fecha a corrida de verdade: MySQL 8.0 usa REPEATABLE READ por
-- padrao, entao o NOT EXISTS e uma leitura nao-bloqueante — dois cliques
-- concorrentes em "Bloquear" no mesmo cliente ainda podem ambos passar o
-- NOT EXISTS antes de qualquer INSERT commitar, duplicando a linha.
--
-- customer_mail e sempre preenchido (NOT NULL, vem do pedido) e e o
-- identificador primario do bloqueio — um UNIQUE nele fecha a corrida a
-- nivel de banco: o segundo INSERT concorrente falha com erro de constraint
-- (capturado no controller e tratado como "ja bloqueado", nao como falha).
--
-- customer_mail ja tinha uma KEY nao-unica (idx_blocked_customers_mail, da
-- migration 034) — troca por UNIQUE em vez de empilhar os dois indices
-- redundantes sobre a mesma coluna.
--
-- Idempotencia da propria migration: checagem em information_schema (mesmo
-- padrao de 028_add_tracking_to_orders.sql / 029_add_index_customer_mail_to_orders.sql).

SET @uniq_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_customers'
      AND INDEX_NAME = 'uniq_blocked_customers_mail'
);
SET @old_key_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_customers'
      AND INDEX_NAME = 'idx_blocked_customers_mail'
);
SET @ddl := IF(
    @uniq_exists = 0,
    IF(
        @old_key_exists = 0,
        'ALTER TABLE `blocked_customers` ADD UNIQUE KEY `uniq_blocked_customers_mail` (`customer_mail`)',
        'ALTER TABLE `blocked_customers` DROP KEY `idx_blocked_customers_mail`, ADD UNIQUE KEY `uniq_blocked_customers_mail` (`customer_mail`)'
    ),
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
