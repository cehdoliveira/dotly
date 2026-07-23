-- email_queue.status ganha o estado 'queued'.
--
-- Antes o dispatch_emails.php marcava 'sent' assim que PRODUZIA a mensagem no
-- Kafka — o que era enganoso: 'sent' nao significava entregue, so enfileirado.
-- Um e-mail podia ficar preso no worker (SMTP falhando) com a fila dizendo
-- 'sent'. Agora a maquina de estados e honesta:
--   pending  -> aguardando o dispatch
--   queued   -> produzido no Kafka pelo dispatch (aguardando entrega)
--   sent     -> ENTREGUE de verdade por SMTP (gravado pelo worker)
--   failed   -> falha terminal (produce esgotou retries OU worker desistiu)
--
-- MODIFY de ENUM e deterministico/idempotente: reexecutar deixa a coluna na
-- mesma definicao. Nenhuma linha existente muda de valor ('sent'/'pending'/
-- 'failed' seguem validos).

ALTER TABLE `email_queue`
  MODIFY `status` ENUM('pending','queued','sent','failed') NOT NULL DEFAULT 'pending';
