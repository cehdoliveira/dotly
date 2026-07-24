<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre o contrato de EmailProducer usado pelo Plano 007: os fluxos de convite
 * e reset de senha de admin (config_controller::users_action()) checam o bool
 * de retorno de send() e mostram o link quando o e-mail nao sai. Este teste
 * nao chama users_action() diretamente (termina em basic_redir() -> exit(),
 * mataria o processo do PHPUnit — mesmo motivo documentado em
 * UserCreateActionTest); em vez disso, trava o contrato da classe que sustenta
 * essa checagem: sem rdkafka, EmailProducer e um STUB que sempre falha (nao
 * existe fallback sincrono).
 */
final class AdminEmailFailureTest extends TestCase
{
    public function testIsAvailableRefleteExtensao(): void
    {
        $this->assertSame(extension_loaded('rdkafka'), EmailProducer::isAvailable());
    }

    public function testStubSendRetornaFalse(): void
    {
        if (extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka carregado neste ambiente');
        }

        $producer = EmailProducer::getInstance();
        $this->assertFalse($producer->send('teste@example.com', 'Assunto', 'Corpo'));
    }
}
