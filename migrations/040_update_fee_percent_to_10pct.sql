-- Taxa de compra passa de 8% para 10% (800 -> 1000 basis points).
-- A linha ja foi semeada por 018_create_table_settings.sql com INSERT IGNORE,
-- entao um novo INSERT IGNORE nao a sobrescreveria — e preciso UPDATE.
-- Idempotente: rodar de novo mantem 1000. OrderPricing::compute le esse valor
-- de `settings` a cada checkout, entao isto altera o valor efetivamente cobrado.

UPDATE `settings`
SET `svalue` = '1000', `modified_at` = NOW(), `modified_by` = 0
WHERE `skey` = 'fee_percent_bps';
