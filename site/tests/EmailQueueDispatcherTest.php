<?php

declare(strict_types=1);

/**
 * Cobre EmailQueueDispatcher::recordOutcome() (Plano 016) — a maquina de
 * estados que decide se uma linha de `email_queue` vira sent/pending/failed.
 * Extraida de site/cgi-bin/dispatch_emails.php especificamente para ser
 * testavel (o script cron em si nao roda sob PHPUnit).
 *
 * recordOutcome() e chamado com $ok controlado pelo teste — o envio real via
 * EmailProducer/rdkafka (attemptSend()) nao e mockado (repo nao usa
 * Mockery); esse lado depende do ambiente e foi verificado manualmente.
 *
 * IMPORTANTE (revisao adversarial, plano 016): no ramo $ok=true, recordOutcome()
 * chama localPDO::getInstance()->commit() explicitamente logo apos marcar a
 * linha como 'sent'. Como localPDO e singleton por processo (mesma limitacao
 * documentada em WebhookIdempotencyTest), testRecordOutcomeSuccess* commitam
 * de verdade na base de dev compartilhada — sem rollback possivel no
 * tearDown() depois disso. Limpeza manual necessaria apos rodar esta suite.
 */
final class EmailQueueDispatcherTest extends DBTestCase
{
    private function enqueueRow(int $attempts = 0, int $maxAttempts = 5): array
    {
        $orderId = random_int(100000, 999999);
        $mail    = 'dispatch_' . uniqid() . '@example.com';

        $m = new email_queue_model();
        $m->execute_raw_prepared(
            "INSERT INTO email_queue
               (created_at, active, event_type, orders_id, to_mail, subject, body, status, attempts, max_attempts)
             VALUES (NOW(), 'yes', 'order_paid', ?, ?, 'Assunto Teste', '<p>corpo teste</p>', 'pending', ?, ?)",
            [$orderId, $mail, $attempts, $maxAttempts]
        );

        $row = new email_queue_model();
        $row->set_filter([" active = 'yes' ", " orders_id = ? "], [$orderId]);
        $row->set_paginate([1]);
        $row->load_data(false);

        return $row->data[0];
    }

    private function loadQueueRow(int $idx): array
    {
        $model = new email_queue_model();
        $model->set_filter(["idx = ?"], [$idx]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0];
    }

    public function testRecordOutcomeSuccessMarksQueued(): void
    {
        $row = $this->enqueueRow();

        // Produzir no Kafka com sucesso agora marca 'queued' (nao 'sent'):
        // 'sent' passou a significar entrega SMTP real, gravada pelo worker.
        $status = EmailQueueDispatcher::recordOutcome($row, true);

        $this->assertSame('queued', $status);

        $updated = $this->loadQueueRow((int)$row['idx']);
        $this->assertSame('queued', $updated['status']);
        $this->assertNull($updated['sent_at'], "sent_at so e gravado na entrega real (markDelivered), nao ao enfileirar");
    }

    public function testMarkDeliveredMarksSentComSentAt(): void
    {
        $row = $this->enqueueRow();

        EmailQueueDispatcher::markDelivered((int)$row['idx']);

        $updated = $this->loadQueueRow((int)$row['idx']);
        $this->assertSame('sent', $updated['status']);
        $this->assertNotNull($updated['sent_at']);
    }

    public function testMarkFailedMarksFailedComMotivo(): void
    {
        $row = $this->enqueueRow();

        EmailQueueDispatcher::markFailed((int)$row['idx'], 'SMTP falhou apos 5 tentativas');

        $updated = $this->loadQueueRow((int)$row['idx']);
        $this->assertSame('failed', $updated['status']);
        $this->assertStringContainsString('5 tentativas', (string)$updated['last_error']);
    }

    public function testRecordOutcomeFailureBelowMaxAttemptsRetriesAsPending(): void
    {
        $row = $this->enqueueRow(attempts: 1, maxAttempts: 5);

        $status = EmailQueueDispatcher::recordOutcome($row, false);

        $this->assertSame('pending', $status, 'attempts (2) < max_attempts (5) deve continuar pending p/ retry');

        $updated = $this->loadQueueRow((int)$row['idx']);
        $this->assertSame('pending', $updated['status']);
        $this->assertSame(2, (int)$updated['attempts']);
        $this->assertNotNull($updated['last_error']);
    }

    public function testRecordOutcomeFailureAtMaxAttemptsMarksFailed(): void
    {
        $row = $this->enqueueRow(attempts: 4, maxAttempts: 5);

        $status = EmailQueueDispatcher::recordOutcome($row, false);

        $this->assertSame('failed', $status, 'attempts (5) >= max_attempts (5) deve marcar failed');

        $updated = $this->loadQueueRow((int)$row['idx']);
        $this->assertSame('failed', $updated['status']);
        $this->assertSame(5, (int)$updated['attempts']);
    }

    public function testRecordOutcomeNeverTouchesStatusOfOtherRows(): void
    {
        $target = $this->enqueueRow();
        $other  = $this->enqueueRow();

        EmailQueueDispatcher::recordOutcome($target, true);

        $untouched = $this->loadQueueRow((int)$other['idx']);
        $this->assertSame('pending', $untouched['status'], 'recordOutcome() so deve gravar a linha do idx recebido');
    }
}
