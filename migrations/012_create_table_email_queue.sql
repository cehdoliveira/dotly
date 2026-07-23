-- Fila de e-mails transacionais. Ledger persistente — cada evento do ciclo do
-- pedido (pagamento confirmado, pedido enviado) vira 1 linha aqui. Um cron
-- dispatcher (site/cgi-bin/dispatch_emails.php) lê as linhas 'pending' e chama
-- EmailProducer::send() (Kafka -> worker SMTP existente), marcando sent/failed.
-- Retry fica na própria tabela (attempts/max_attempts).
--
-- status ENUM inclui 'queued' (fold da migration 041): pending -> queued (produzido
-- no Kafka) -> sent (entregue pelo worker) -> failed (terminal).
--
-- UNIQUE(orders_id, event_type): no máximo 1 e-mail por evento por pedido —
-- dedupe caso o webhook (ou a ação de "marcar como enviado") dispare 2x.
CREATE TABLE IF NOT EXISTS `email_queue` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') DEFAULT 'yes',
    `event_type` ENUM('order_paid','order_shipped') NOT NULL,
    `orders_id` INT NOT NULL,
    `to_mail` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `body` LONGTEXT NOT NULL,
    `status` ENUM('pending','queued','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
    `last_error` TEXT DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uq_email_queue_event` (`orders_id`, `event_type`),
    KEY `idx_email_queue_status` (`status`, `idx`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
