-- transaction_nsu (UUID gerado pela InfinitePay, so existe apos um pagamento
-- real) passa a ser persistido com UNIQUE: fecha o replay do mesmo
-- transaction_nsu real contra um pedido diferente (achado do /ship,
-- especialista de seguranca, plano 031). NULL para MercadoPago/PagBank (nao
-- usam este campo) e para cobrancas InfinitePay ainda nao reconfirmadas —
-- MySQL permite multiplos NULL numa UNIQUE KEY, entao isso nao colide.
--
-- Idempotencia da propria migration: checagem em information_schema (mesmo
-- padrao de 035_unique_customer_mail_blocked_customers.sql). Checa a COLUNA e
-- a UNIQUE KEY separadamente (nao só "coluna existe -> ja fiz tudo") — achado
-- da revisao adversarial do /ship (Codex): se a coluna algum dia existir sem
-- o indice (ex.: intervencao manual parcial), esta migration nao pode
-- considerar "ja feito" e pular a protecao real, que e a UNIQUE KEY.
--
-- NOTA DE DEPLOY (achado do /ship, Codex): pix_charges_model.php passa a
-- selecionar transaction_nsu sempre. Se o codigo novo subir ANTES desta
-- migration rodar, qualquer load_data() em pix_charges quebra com "unknown
-- column" ate a migration (cron a cada 5min, ver CLAUDE.md) alcancar. Risco
-- geral de qualquer migration deste projeto que adicione coluna referenciada
-- pelo codigo do mesmo deploy — nao especifico desta. Rodar a migration antes
-- (ou junto) do deploy do codigo evita a janela.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pix_charges'
      AND COLUMN_NAME = 'transaction_nsu'
);
SET @add_column_ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `pix_charges` ADD COLUMN `transaction_nsu` VARCHAR(64) DEFAULT NULL AFTER `gateway_charge_id`',
    'DO 0'
);
PREPARE stmt FROM @add_column_ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @key_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pix_charges'
      AND INDEX_NAME = 'uq_pix_charge_transaction_nsu'
);
SET @add_key_ddl := IF(
    @key_exists = 0,
    'ALTER TABLE `pix_charges` ADD UNIQUE KEY `uq_pix_charge_transaction_nsu` (`transaction_nsu`)',
    'DO 0'
);
PREPARE stmt FROM @add_key_ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
