<?php

declare(strict_types=1);

/**
 * Cobre o enfileiramento de 'order_paid' adicionado a
 * webhook_controller::processEvent() (Plano 016), chamado logo apos a
 * transicao do pedido p/ 'pago' e ANTES do commit explicito (mesma transacao —
 * ver comentario em processEvent()).
 *
 * NAO exercitamos processEvent() ate o caminho de sucesso real (valor pago >=
 * total): esse caminho termina em `$orderUpdate->commit()`, que no singleton
 * compartilhado por todo o processo PHPUnit (localPDO::getInstance()) commita
 * de verdade e permanentemente todos os dados de teste acumulados ate ali —
 * exatamente o motivo pelo qual WebhookIdempotencyTest tambem evita esse
 * caminho (ver docblock daquela classe). Em vez disso, testamos aqui a mesma
 * chamada que o webhook faz — OrderMailQueue::enqueue() com os mesmos dados
 * (orders_id, 'order_paid', customer_mail, corpo renderizado) — provando que
 * o enfileiramento por si so funciona e e idempotente por reentrega. Que a
 * chamada esta no branch certo (transicao nova, nao o de reentrancia em
 * processEvent():73) foi verificado por leitura de codigo.
 */
final class WebhookEnqueueTest extends DBTestCase
{
    private function makeOrder(): array
    {
        $token = bin2hex(random_bytes(16));
        $mail  = 'pago_' . uniqid() . '@example.com';

        $order = new orders_model();
        $order->populate([
            'token'          => $token,
            'status'         => 'aguardando_pagamento',
            'customer_name'  => 'Cliente Pagamento Teste',
            'customer_mail'  => $mail,
            'customer_phone' => '11999999999',
            'customer_cpf'   => '12345678909',
            'ship_zip'       => '01310100',
            'ship_street'    => 'Av. Paulista',
            'ship_number'    => '1000',
            'ship_district'  => 'Bela Vista',
            'ship_city'      => 'São Paulo',
            'ship_uf'        => 'SP',
            'total_cents'    => 10000,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ]);
        $orderId = (int) $order->save();
        $this->assertGreaterThan(0, $orderId);

        return ['idx' => $orderId, 'token' => $token, 'customer_mail' => $mail];
    }

    /**
     * Reproduz exatamente a renderizacao + chamada que
     * webhook_controller::processEvent() faz apos marcar o pedido como pago
     * (mesmo padrao de GatewaysActionTest::buildUpdateData() — reproduzir a
     * montagem de dados do controller em vez de invocar o metodo inteiro).
     */
    private function enqueueOrderPaidLikeWebhookDoes(array $order): void
    {
        ob_start();
        $name       = $order['customer_name'] ?? 'Cliente Pagamento Teste';
        $orderToken = $order['token'];
        include(constant("cRootServer") . "ui/mail/order_paid.php");
        $body = ob_get_clean();

        OrderMailQueue::enqueue(
            $order['idx'],
            'order_paid',
            $order['customer_mail'],
            "Pagamento confirmado — " . constant('cStoreName'),
            (string)$body
        );
    }

    private function loadPaidQueueRow(int $orderId): ?array
    {
        $model = new email_queue_model();
        $model->set_filter([" active = 'yes' ", " orders_id = ? ", " event_type = 'order_paid' "], [$orderId]);
        $model->set_paginate([1]);
        $model->load_data(false);

        return $model->data[0] ?? null;
    }

    public function testTransitionToPagoEnqueuesOrderPaidWithCustomerMail(): void
    {
        $order = $this->makeOrder();

        $this->enqueueOrderPaidLikeWebhookDoes($order);

        $row = $this->loadPaidQueueRow($order['idx']);
        $this->assertNotNull($row, 'deve enfileirar 1 e-mail order_paid');
        $this->assertSame('pending', $row['status']);
        $this->assertSame($order['customer_mail'], $row['to_mail']);
        $this->assertSame(0, (int)$row['attempts']);
    }

    public function testRedeliveryDoesNotDuplicateOrderPaidRow(): void
    {
        $order = $this->makeOrder();

        // Simula o webhook sendo reentregue: 2 chamadas para o mesmo pedido.
        $this->enqueueOrderPaidLikeWebhookDoes($order);
        $this->enqueueOrderPaidLikeWebhookDoes($order);

        $model = new email_queue_model();
        $model->set_filter([" active = 'yes' ", " orders_id = ? ", " event_type = 'order_paid' "], [$order['idx']]);
        $model->load_data(false);

        $this->assertCount(1, $model->data, 'UNIQUE(orders_id,event_type) deve impedir 2 e-mails de pagamento para o mesmo pedido');
    }
}
