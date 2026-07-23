-- TODOS.md #3 / Plano 030: a feature de desbloquear cliente faz soft-delete
-- (active='no') das linhas de blocked_customers. O UNIQUE da migration 035
-- (uniq_blocked_customers_mail em customer_mail) NÃO era escopado a active, então
-- re-bloquear um cliente antes desbloqueado bateria no UNIQUE de uma linha já
-- inativa e o INSERT falharia (o recheck do controller, que só vê active='yes',
-- não acharia nada e reportaria "Falha ao bloquear").
--
-- Troca por um índice funcional (MySQL 8.0.13+): a chave é
-- IF(active='yes', customer_mail, NULL). Linhas inativas viram NULL — múltiplos
-- NULL são permitidos num UNIQUE — então soft-deletados não colidem. Linhas ativas
-- mantêm a unicidade de customer_mail (fecha a race entre dois "Bloquear"
-- concorrentes, igual a 035 dava).
--
-- Idempotência: checagem em information_schema por nome de índice (padrão de 035).

SET @new_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_customers'
      AND INDEX_NAME = 'uniq_blocked_customers_active_mail'
);
SET @old_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_customers'
      AND INDEX_NAME = 'uniq_blocked_customers_mail'
);
SET @ddl := IF(
    @new_exists = 0,
    IF(
        @old_exists = 0,
        'ALTER TABLE `blocked_customers` ADD UNIQUE KEY `uniq_blocked_customers_active_mail` ((IF(`active` = ''yes'', `customer_mail`, NULL)))',
        'ALTER TABLE `blocked_customers` DROP KEY `uniq_blocked_customers_mail`, ADD UNIQUE KEY `uniq_blocked_customers_active_mail` ((IF(`active` = ''yes'', `customer_mail`, NULL)))'
    ),
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
