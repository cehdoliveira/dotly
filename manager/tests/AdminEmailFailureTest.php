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
 *
 * As reproduceCriarMensagem()/reproduceResetSenhaMensagem() abaixo espelham,
 * linha por linha, o bloco de decisao de mensagem de
 * config_controller::users_action() (mesma tecnica de UserCreateActionTest
 * para a escrita: reproduzir a sequencia em vez de chamar o metodo real).
 * Isso trava a wording e a presenca do link, mas NAO prova que o controller de
 * verdade executa exatamente essa sequencia — se o controller divergir deste
 * espelho, so um review manual do diff pega.
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

    /**
     * Espelha manager/app/inc/controller/config_controller.php, bloco
     * "if ($mailSent) / elseif ($setPasswordLink !== '') / else" do case 'criar'.
     */
    private function reproduceCriarMensagem(bool $mailSent, string $setPasswordLink): array
    {
        if ($mailSent) {
            return ["success", ["Usuário criado com sucesso. Um email foi enviado com as instruções para definir a senha."]];
        } elseif ($setPasswordLink !== '') {
            return ["warning", ["Usuário criado, mas o e-mail NÃO pôde ser enviado. Entregue este link ao novo usuário (validade 72h): " . $setPasswordLink]];
        }

        return ["warning", ["Usuário criado, mas não foi possível gerar o link de definição de senha nem enviar o e-mail (configuração de URL canônica ausente). Verifique MANAGER_CANONICAL_URL/ALLOWED_HOSTS no kernel.php."]];
    }

    /**
     * Espelha o bloco "if ($mailSent) / else" do case 'reset-senha'.
     */
    private function reproduceResetSenhaMensagem(bool $mailSent, string $resetLink, string $mail): array
    {
        if ($mailSent) {
            return ["success", ["Link de redefinição de senha enviado para " . $mail . "."]];
        }

        return ["warning", ["Link de redefinição gerado, mas o e-mail NÃO pôde ser enviado. Entregue este link ao usuário (validade 2h): " . $resetLink]];
    }

    public function testCriarComEmailEnviadoMostraSucesso(): void
    {
        [$tipo, $mensagens] = $this->reproduceCriarMensagem(true, 'https://manager.example.com/definir-senha/tok123');

        $this->assertSame("success", $tipo);
        $this->assertSame(["Usuário criado com sucesso. Um email foi enviado com as instruções para definir a senha."], $mensagens);
    }

    public function testCriarComEmailFalhoMostraLinkNoAviso(): void
    {
        $link = 'https://manager.example.com/definir-senha/tok123';
        [$tipo, $mensagens] = $this->reproduceCriarMensagem(false, $link);

        $this->assertSame("warning", $tipo);
        $this->assertCount(1, $mensagens);
        $this->assertStringContainsString($link, $mensagens[0], 'aviso de falha de envio precisa trazer o link de definicao de senha');
    }

    public function testCriarSemLinkDisponivelNaoPrometeLinkVazio(): void
    {
        [$tipo, $mensagens] = $this->reproduceCriarMensagem(false, '');

        $this->assertSame("warning", $tipo);
        $this->assertStringNotContainsString(': ,', $mensagens[0]);
        $this->assertStringNotContainsString('validade 72h): ' . "\n", $mensagens[0]);
        $this->assertMatchesRegularExpression('/configuração de URL canônica ausente/', $mensagens[0], 'sem link, o aviso precisa explicar a causa em vez de terminar em branco');
    }

    public function testResetSenhaComEmailEnviadoMostraSucesso(): void
    {
        [$tipo, $mensagens] = $this->reproduceResetSenhaMensagem(true, 'https://manager.example.com/definir-senha/tok456', 'admin@example.com');

        $this->assertSame("success", $tipo);
        $this->assertSame(["Link de redefinição de senha enviado para admin@example.com."], $mensagens);
    }

    public function testResetSenhaComEmailFalhoMostraLinkNoAviso(): void
    {
        $link = 'https://manager.example.com/definir-senha/tok456';
        [$tipo, $mensagens] = $this->reproduceResetSenhaMensagem(false, $link, 'admin@example.com');

        $this->assertSame("warning", $tipo);
        $this->assertStringContainsString($link, $mensagens[0], 'aviso de falha de envio precisa trazer o link de reset');
    }
}
