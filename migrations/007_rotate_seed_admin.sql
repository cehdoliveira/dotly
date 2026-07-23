-- Rotaciona o admin seedado caso a senha default nunca tenha sido trocada.
-- O hash abaixo é o valor original commitado em migrations/002 (antes da
-- edição que o removeu) — usado apenas como guard de idempotência: só afeta
-- instalações que ainda não trocaram a senha default.
UPDATE users
   SET password = '!disabled!', enabled = 'no', modified_at = NOW()
 WHERE login = 'admin'
   AND password = '$2y$10$ie5ckp.oFWWVU5UP3.P7tOY/XIGxKvuU5sZK7rwl0.88KXsBWuuG2';
