<?php

declare(strict_types=1);

/**
 * Cobre OrderMailQueue::enqueue() (Plano 016): insere 1 linha 'pending' em
 * `email_queue`, e e idempotente por (orders_id, event_type) — uma 2a chamada
 * com a mesma dupla nao duplica (UNIQUE + ON DUPLICATE KEY UPDATE idx = idx).
 *
 * `email_queue.orders_id` nao tem FK (mesmo padrao de `messages`, sem FK) —
 * os testes usam um int arbitrario, sem precisar criar um pedido de verdade.
 */
final class OrderMailQueueTest extends DBTestCase
{
    private function loadQueueRow(int $orderId, string $eventType): ?array
    {
        $model = new email_queue_model();
        $model->set_filter([" active = 'yes' ", " orders_id = ? ", " event_type = ? "], [$orderId, $eventType]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? null;
    }

    public function testEnqueueInsertsOnePendingRow(): void
    {
        $orderId = random_int(100000, 999999);

        OrderMailQueue::enqueue($orderId, 'order_paid', 'cliente@example.com', 'Assunto Teste', '<p>corpo</p>');

        $row = $this->loadQueueRow($orderId, 'order_paid');
        $this->assertNotNull($row, 'enqueue() deve inserir 1 linha em email_queue');
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int)$row['attempts']);
        $this->assertSame('cliente@example.com', $row['to_mail']);
        $this->assertSame('Assunto Teste', $row['subject']);
        $this->assertSame('<p>corpo</p>', $row['body']);
    }

    public function testEnqueueTwiceWithSameOrderAndEventDoesNotDuplicate(): void
    {
        $orderId = random_int(100000, 999999);

        OrderMailQueue::enqueue($orderId, 'order_shipped', 'cliente@example.com', 'Primeiro assunto', 'primeiro corpo');
        OrderMailQueue::enqueue($orderId, 'order_shipped', 'outro@example.com', 'Segundo assunto', 'segundo corpo');

        $model = new email_queue_model();
        $model->set_filter([" active = 'yes' ", " orders_id = ? ", " event_type = ? "], [$orderId, 'order_shipped']);
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'UNIQUE(orders_id,event_type) + ON DUPLICATE KEY deve manter so 1 linha');
        // ON DUPLICATE KEY UPDATE idx = idx: no-op — a linha permanece com os
        // dados da 1a chamada, a 2a chamada nao sobrescreve nada.
        $this->assertSame('Primeiro assunto', $model->data[0]['subject']);
    }

    public function testEnqueueDifferentEventTypesForSameOrderCreateTwoRows(): void
    {
        $orderId = random_int(100000, 999999);

        OrderMailQueue::enqueue($orderId, 'order_paid', 'cliente@example.com', 'Pago', 'corpo pago');
        OrderMailQueue::enqueue($orderId, 'order_shipped', 'cliente@example.com', 'Enviado', 'corpo enviado');

        $model = new email_queue_model();
        $model->set_filter([" active = 'yes' ", " orders_id = ? "], [$orderId]);
        $model->set_order([" event_type ASC "]);
        $model->load_data(false);

        $this->assertCount(2, $model->data, 'eventos diferentes do mesmo pedido nao sao deduplicados entre si');
    }
}
