-- Plano 025: remove o viewer /emails e a tabela `messages`. Era um log
-- write-only duplicado dos 2 e-mails transacionais in-scope (pagamento
-- confirmado, pedido enviado), que ja tem ledger proprio e melhor em
-- `email_queue` (status pending/sent/error, retries, timestamps). Os writers
-- (EmailQueueDispatcher::recordOutcome(), site_controller::users_action())
-- e o unico reader (emails_controller) ja foram removidos neste plano.
--
-- DROP TABLE IF EXISTS ja e idempotente por natureza (mesmo padrao de
-- 030_drop_customers_tables.sql, 031_drop_stock_ledger.sql e
-- 032_drop_categories_tables.sql).

DROP TABLE IF EXISTS messages;
