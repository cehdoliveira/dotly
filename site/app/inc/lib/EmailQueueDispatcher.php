<?php
/**
 * Plano 016: logica de processamento de 1 linha da fila `email_queue`,
 * extraida de site/cgi-bin/dispatch_emails.php para ser testavel por PHPUnit
 * (o script cron em si nao e invocavel diretamente pela suite) — mesmo
 * padrao ja usado em orders_controller::markAsShipped() (plano 016).
 */
class EmailQueueDispatcher
{
    /**
     * Tenta enviar 1 linha via EmailProducer e grava o desfecho (sent /
     * pending com attempts++ / failed ao atingir max_attempts). Retorna o
     * status final gravado.
     *
     * @param array{idx:int,to_mail:string,subject:string,body:string,attempts:int,max_attempts:int} $row
     */
    public static function processRow(array $row): string
    {
        return self::recordOutcome($row, self::attemptSend($row));
    }

    /**
     * So a chamada ao EmailProducer (fire-and-forget, fail-open). Separada de
     * recordOutcome() porque depende do rdkafka disponivel no ambiente —
     * nao e testavel de forma determinística sem mock (repo nao usa
     * Mockery). recordOutcome() cobre a maquina de estados real com $ok
     * controlado pelo teste.
     */
    private static function attemptSend(array $row): bool
    {
        try {
            if (class_exists("EmailProducer")) {
                // queue_id viaja no payload do Kafka: o worker o usa para gravar
                // o desfecho real do SMTP de volta nesta mesma linha da fila.
                return EmailProducer::getInstance()->sendEmail(
                    (string)$row['to_mail'],
                    (string)$row['subject'],
                    (string)$row['body'],
                    ['queue_id' => (int)$row['idx']]
                );
            }
        } catch (\Throwable $e) {
            error_log("EmailQueueDispatcher::attemptSend falhou (queue {$row['idx']}): " . $e->getMessage());
        }

        return false;
    }

    /**
     * Grava o desfecho de 1 linha da fila dado o resultado do envio. Publico
     * (nao private) para ser exercitado diretamente por
     * EmailQueueDispatcherTest com $ok controlado, sem depender do
     * EmailProducer/Kafka real.
     *
     * @param array{idx:int,to_mail:string,subject:string,body:string,attempts:int,max_attempts:int} $row
     */
    public static function recordOutcome(array $row, bool $ok): string
    {
        $update = new email_queue_model();
        $update->set_filter(["idx = ?"], [(int)$row['idx']]);

        if ($ok) {
            // 'queued' (NAO 'sent'): a mensagem foi so PRODUZIDA no Kafka. Quem
            // marca 'sent'+sent_at e o worker, via markDelivered(), quando o SMTP
            // entrega de verdade. Antes marcava 'sent' aqui — a mentira do plano
            // que este ajuste corrige (status refletia enfileiramento, nao entrega).
            $update->populate([
                "status" => "queued",
            ]);
            $update->save();

            // Commit imediato: durabiliza 'queued' assim que a mensagem sai pro
            // Kafka, independente do que rodar depois no loop do dispatcher.
            localPDO::getInstance()->commit();
            localPDO::getInstance()->beginTransaction();

            return 'queued';
        }

        $attempts = (int)$row['attempts'] + 1;
        $status   = $attempts >= (int)$row['max_attempts'] ? 'failed' : 'pending';

        $update->populate([
            "attempts"   => $attempts,
            "status"     => $status,
            "last_error" => "produce no Kafka retornou false/excecao em " . date("c"),
        ]);
        $update->save();

        return $status;
    }

    /**
     * Marca uma linha da fila como ENTREGUE (status='sent' + sent_at). Chamado
     * pelo worker Kafka quando o SMTP confirma a entrega — e o unico ponto que
     * grava 'sent', para que o status reflita entrega real, nao enfileiramento.
     * Commit imediato pelo mesmo motivo de recordOutcome().
     */
    public static function markDelivered(int $queueId): void
    {
        $update = new email_queue_model();
        $update->set_filter(["idx = ?"], [$queueId]);
        $update->populate([
            "status"  => "sent",
            "sent_at" => date("Y-m-d H:i:s"),
        ]);
        $update->save();

        localPDO::getInstance()->commit();
        localPDO::getInstance()->beginTransaction();
    }

    /**
     * Marca uma linha da fila como FALHA TERMINAL (status='failed'). Chamado
     * pelo worker apos esgotar as tentativas de SMTP: em vez de reprocessar a
     * mensagem pra sempre (travando a particao do Kafka), o worker desiste,
     * marca 'failed' com o motivo e comita o offset. O e-mail nao se perde em
     * silencio — fica visivel como 'failed' para reprocesso manual.
     */
    public static function markFailed(int $queueId, string $error): void
    {
        $update = new email_queue_model();
        $update->set_filter(["idx = ?"], [$queueId]);
        $update->populate([
            "status"     => "failed",
            "last_error" => mb_substr($error, 0, 1000),
        ]);
        $update->save();

        localPDO::getInstance()->commit();
        localPDO::getInstance()->beginTransaction();
    }
}
