-- TODOS.md #1 / Plano 028: users.login não tinha UNIQUE no banco (só mail tinha,
-- migration 002). userConflictExists() checava login via SELECT antes do INSERT,
-- mas dois criares concorrentes com o mesmo login (mails diferentes) podiam ambos
-- passar antes de qualquer commit — mesma race que a migration 035 fechou para
-- blocked_customers.customer_mail. login é VARCHAR(255) DEFAULT NULL: múltiplos
-- NULL continuam permitidos (MySQL trata NULL como distinto no UNIQUE), só logins
-- não-nulos iguais passam a colidir. O segundo INSERT concorrente falha na
-- constraint e o catch de config_controller::criar (linhas 360-364) trata como
-- conflito.
--
-- Idempotência: checagem em information_schema (mesmo padrão de 035).

SET @uniq_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'login_UNIQUE'
);
SET @ddl := IF(
    @uniq_exists = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `login_UNIQUE` (`login`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
