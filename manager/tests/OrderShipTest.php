<?php

declare(strict_types=1);

/**
 * Cobre orders_controller::markAsShipped() (Plano 016) — a 1a acao de escrita
 * do controller de pedidos: grava tracking_code/shipped_at e enfileira o
 * e-mail 'order_shipped'. `status` nunca e tocado (a maquina de status de
 * pagamento continua exclusiva do webhook).
 *
 * markAsShipped() e chamado diretamente (nao ship()/action() via rota): ship()
 * termina em basic_redir() -> exit(), mesmo motivo documentado em
 * CheckoutStockTest para checkout_controller::lockAndValidateCart().
 *
 * IMPORTANTE (revisao adversarial, plano 016): markAsShipped() agora chama
 * localPDO::getInstance()->commit() explicitamente antes do enfileiramento
 * de e-mail (fix para o achado de que um erro real no enqueue revertia a
 * transacao inteira, inclusive o proprio save() de tracking_code/shipped_at,
 * em silencio). Como localPDO e singleton por processo (mesma limitacao
 * documentada em WebhookIdempotencyTest), TODO teste desta classe agora
 * commita de verdade os pedidos de fixture na base de dev compartilhada —
 * nao ha rollback possivel no tearDown() depois disso. Limpeza manual
 * necessaria apos rodar esta suite (mesmo padrao ja aceito para os commits
 * deliberados de WebhookIdempotencyTest).
 */
final class OrderShipTest extends DBTestCase
{
    private function makeOrder(): int
    {
        $insert = new orders_model();
        $insert->populate([
            'token'          => bin2hex(random_bytes(16)),
            'status'         => 'pago',
            'customer_name'  => 'Cliente Envio Teste',
            'customer_mail'  => 'envio_' . uniqid() . '@example.com',
            'customer_phone' => '11999999999',
            'customer_cpf'   => '12345678909',
            'ship_zip'       => '01000000',
            'ship_street'    => 'Rua Teste',
            'ship_number'    => '100',
            'ship_district'  => 'Centro',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => 5000,
            'paid_at'        => date('Y-m-d H:i:s'),
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $id = (int) $insert->save();
        $this->assertGreaterThan(0, $id, 'Insert de fixture de pedido deve retornar um ID valido');

        return $id;
    }

    private function loadOrder(int $orderId): array
    {
        $model = new orders_model();
        $model->set_field([' idx ', ' status ', ' tracking_code ', ' shipped_at ', ' customer_mail ']);
        $model->set_filter(['idx = ?'], [$orderId]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? [];
    }

    private function loadShippedQueueRow(int $orderId): ?array
    {
        $model = new email_queue_model();
        $model->set_filter([" active = 'yes' ", " orders_id = ? ", " event_type = 'order_shipped' "], [$orderId]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? null;
    }

    public function testMarkAsShippedWithTrackingCodeWritesTrackingAndShippedAtAndEnqueuesMail(): void
    {
        $orderId = $this->makeOrder();

        $controller = new orders_controller();
        $controller->markAsShipped($orderId, 'BR123456789');

        $order = $this->loadOrder($orderId);
        $this->assertSame('BR123456789', $order['tracking_code']);
        $this->assertNotNull($order['shipped_at']);
        $this->assertSame('pago', $order['status'], 'markAsShipped() nunca deve tocar status');

        $queueRow = $this->loadShippedQueueRow($orderId);
        $this->assertNotNull($queueRow, 'deve enfileirar 1 e-mail order_shipped');
        $this->assertSame('pending', $queueRow['status']);
        $this->assertSame($order['customer_mail'], $queueRow['to_mail']);
        $this->assertStringContainsString('BR123456789', $queueRow['body'], 'o corpo renderizado deve conter o codigo de rastreio');
    }

    public function testMarkAsShippedWithoutTrackingCodeStillWritesShippedAtAndEnqueuesMail(): void
    {
        $orderId = $this->makeOrder();

        $controller = new orders_controller();
        $controller->markAsShipped($orderId, '');

        $order = $this->loadOrder($orderId);
        $this->assertNull($order['tracking_code'], 'sem codigo informado, tracking_code permanece NULL');
        $this->assertNotNull($order['shipped_at']);

        $queueRow = $this->loadShippedQueueRow($orderId);
        $this->assertNotNull($queueRow, 'deve enfileirar o e-mail mesmo sem codigo de rastreio');
        $this->assertStringContainsString('não tem código de rastreio', $queueRow['body']);
    }

    public function testMarkAsShippedTwiceThrowsAndDoesNotDuplicateQueueEntry(): void
    {
        $orderId = $this->makeOrder();

        $controller = new orders_controller();
        $controller->markAsShipped($orderId, 'FIRST-CODE');

        // Revisao adversarial (plano 016): sem guarda, o 2o call sobrescreveria
        // tracking_code em silencio e o UNIQUE da fila descartaria o e-mail
        // corrigido sem avisar ninguem. markAsShipped() agora rejeita reenvio.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pedido já foi marcado como enviado.');

        try {
            $controller->markAsShipped($orderId, 'SECOND-CODE');
        } finally {
            $model = new email_queue_model();
            $model->set_filter([" active = 'yes' ", " orders_id = ? ", " event_type = 'order_shipped' "], [$orderId]);
            $model->load_data(false);
            $this->assertCount(1, $model->data, 'UNIQUE(orders_id,event_type) deve impedir 2 e-mails de envio para o mesmo pedido');

            $order = $this->loadOrder($orderId);
            $this->assertSame('FIRST-CODE', $order['tracking_code'], 'reenvio rejeitado nao deve alterar o tracking_code ja gravado');
        }
    }

    public function testMarkAsShippedThrowsForNonExistentOrder(): void
    {
        $controller = new orders_controller();

        $this->expectException(\RuntimeException::class);
        $controller->markAsShipped(999999999, '');
    }
}
