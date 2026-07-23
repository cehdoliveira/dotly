<?php
/**
 * Plano 016: enfileiramento de e-mails transacionais do ciclo do pedido.
 *
 * `ui/` nao e compartilhado entre manager e site, entao quem sabe onde fica
 * seu template (e como renderiza-lo) e o chamador — este helper so persiste o
 * corpo ja renderizado na fila (`email_queue`). Um cron dispatcher
 * (site/cgi-bin/dispatch_emails.php) le as linhas 'pending' e chama
 * EmailProducer::send() (fora do escopo deste helper).
 */
class OrderMailQueue
{
    /**
     * Enfileira um e-mail transacional. Idempotente por (orders_id, event_type)
     * via UNIQUE(orders_id, event_type) + ON DUPLICATE KEY (dedupe: reentregas
     * do webhook ou reenvios acidentais nao duplicam a linha).
     *
     * Fail-open: nunca lanca — so loga. Um erro aqui nao pode derrubar o
     * webhook de pagamento nem a acao de "marcar como enviado".
     */
    public static function enqueue(int $orderId, string $eventType, string $toMail,
                                   string $subject, string $body): void
    {
        try {
            $m = new email_queue_model();
            // INSERT direto p/ respeitar o UNIQUE(orders_id,event_type) como dedupe.
            $m->insert([
                'active'       => 'yes',
                'event_type'   => $eventType,
                'orders_id'    => $orderId,
                'to_mail'      => $toMail,
                'subject'      => $subject,
                'body'         => $body,
                'status'       => 'pending',
                'attempts'     => 0,
                'max_attempts' => 5,
            ], "ON DUPLICATE KEY UPDATE idx = idx");
        } catch (\Throwable $e) {
            error_log("OrderMailQueue::enqueue falhou (order {$orderId}, {$eventType}): " . $e->getMessage());
        }
    }
}
