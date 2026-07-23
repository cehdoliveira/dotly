#!/usr/bin/env php
<?php
/**
 * kafka_email_worker.php
 *
 * Worker Kafka Consumer para processamento de emails
 * Consome mensagens do tópico Kafka e envia emails via PHPMailer
 *
 * Uso: php kafka_email_worker.php
 *
 * @package Infinnity Importacao
 * @author Infinnity Importacao Framework
 * @version 1.0
 */

// Stubs for static analysis when rdkafka extension is not loaded
if (!defined('RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS')) {
    define('RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS', -175);
    define('RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS', -174);
    define('RD_KAFKA_RESP_ERR__PARTITION_EOF', -191);
    define('RD_KAFKA_RESP_ERR_NO_ERROR', 0);
    define('RD_KAFKA_RESP_ERR__TIMED_OUT', -185);
}
if (!function_exists('rd_kafka_err2str')) {
    function rd_kafka_err2str(int $err): string
    {
        return "Unknown Kafka error: $err";
    }
}

// Configuração do timezone (importante para PHP 8.4)
date_default_timezone_set('America/Sao_Paulo');

// Simulação de ambiente HTTP para CLI
// Necessário porque scripts CLI não possuem $_SERVER configurado
$_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
$_SERVER["HTTP_HOST"] = getenv("CLI_HTTP_HOST") ?: "manager.infinnityimportacao.local";

// Ambiente HTTP (padrão)
putenv('SERVER_PORT=80');
putenv('SERVER_PROTOCOL=http');

// Configurações adicionais do servidor
putenv('SERVER_NAME=' . $_SERVER["HTTP_HOST"]);
putenv('SCRIPT_NAME=index.php');

// Configurar include_path para compatibilidade
set_include_path($_SERVER["DOCUMENT_ROOT"] . PATH_SEPARATOR . get_include_path());

// Definir flag para modo CLI (evita inicialização de sessão, etc)
define('CLI_MODE', true);

// Log inicial de debug ANTES de carregar qualquer coisa
echo "[DEBUG] Worker iniciando...\n";
echo "[DEBUG] Extensão rdkafka: " . (extension_loaded('rdkafka') ? 'OK' : 'FALHA') . "\n";

// Carregar configurações do kernel primeiro (define constantes)
require_once __DIR__ . '/../app/inc/kernel.php';

echo "[DEBUG] Kernel carregado\n";
echo "[DEBUG] KAFKA_HOST: " . (defined('KAFKA_HOST') ? KAFKA_HOST : 'NÃO DEFINIDO') . "\n";
echo "[DEBUG] KAFKA_PORT: " . (defined('KAFKA_PORT') ? KAFKA_PORT : 'NÃO DEFINIDO') . "\n";
echo "[DEBUG] KAFKA_TOPIC_EMAIL: " . (defined('KAFKA_TOPIC_EMAIL') ? KAFKA_TOPIC_EMAIL : 'NÃO DEFINIDO') . "\n";

// Carregar autoload do Composer (agora que as constantes existem)
require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';

echo "[DEBUG] Autoload carregado\n";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Padrão: log em arquivo DESATIVADO (apenas saída no console)
$ENABLE_FILE_LOG = false;
$LOG_FILE = null;

// ===== Seção de LOG (comente/descomente conforme necessário) =====
// Para ativar o log em arquivo, descomente as duas linhas abaixo:
// $ENABLE_FILE_LOG = true;
// $LOG_FILE = defined('LOG_DIR') ? LOG_DIR . 'email_worker.log' : __DIR__ . '/../logs/email_worker.log';

// Se habilitado, criar diretório de logs se não existir
if (!empty($ENABLE_FILE_LOG) && !empty($LOG_FILE)) {
    $logDir = dirname($LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
}

/**
 * Função de log
 */
function log_message(string $message, string $level = 'INFO'): void
{
    global $LOG_FILE, $ENABLE_FILE_LOG;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
    if (!empty($ENABLE_FILE_LOG) && !empty($LOG_FILE)) {
        file_put_contents($LOG_FILE, $logMessage, FILE_APPEND);
    }
    echo $logMessage;
}

/**
 * Enviar email via PHPMailer
 */
function sendEmailViaPHPMailer(array $emailData): bool
{
    $mail = null;
    try {
        $mail = new PHPMailer(true);

        // Configuração SMTP usando constantes do kernel.php
        $mail->isSMTP();
        $mail->Host       = defined('mail_from_host') ? mail_from_host : 'localhost';
        $mail->SMTPAuth   = true;
        $mail->Username   = defined('mail_from_user') ? mail_from_user : '';
        $mail->Password   = defined('mail_from_pwd') ? mail_from_pwd : '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = defined('mail_from_port') ? mail_from_port : 587;
        // Sem timeout explicito o default do PHPMailer e 300s: uma conexao SMTP
        // tarpitada (Gmail sob throttle) travava o worker por 5min por mensagem.
        $mail->Timeout    = 20;
        $mail->CharSet    = 'UTF-8';

        // Remetente
        $mail->setFrom(
            defined('mail_from_mail') ? mail_from_mail : 'noreply@localhost',
            defined('mail_from_name') ? mail_from_name : 'Infinnity Importacao'
        );

        // Destinatários
        foreach ($emailData['to'] as $recipient) {
            $mail->addAddress($recipient);
        }

        // CC
        if (!empty($emailData['cc'])) {
            foreach ($emailData['cc'] as $cc) {
                $mail->addCC($cc);
            }
        }

        // BCC
        if (!empty($emailData['bcc'])) {
            foreach ($emailData['bcc'] as $bcc) {
                $mail->addBCC($bcc);
            }
        }

        // Reply-To
        if (!empty($emailData['replyTo'])) {
            $mail->addReplyTo($emailData['replyTo']);
        }

        // Anexos
        if (!empty($emailData['attachments'])) {
            foreach ($emailData['attachments'] as $attachment) {
                if (file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }
        }

        // Conteúdo
        $mail->isHTML($emailData['isHtml']);
        $mail->Subject = $emailData['subject'];
        $mail->Body = $emailData['body'];

        // Texto alternativo se for HTML
        if ($emailData['isHtml']) {
            $mail->AltBody = strip_tags($emailData['body']);
        }

        // Enviar
        $result = $mail->send();

        if ($result) {
            log_message("Email enviado com sucesso para: " . implode(', ', $emailData['to']));
            return true;
        }

        return false;
    } catch (Exception $e) {
        $errorInfo = $mail ? $mail->ErrorInfo : $e->getMessage();
        log_message("Erro ao enviar email: {$errorInfo}", 'ERROR');
        return false;
    }
}

/**
 * Main Worker Loop
 */
function runWorker()
{
    echo "[DEBUG] Entrando em runWorker()\n";

    log_message("========================================");
    log_message("Email Worker iniciado");
    log_message("========================================");

    try {
        // Configurar Kafka Consumer
        $confClass = '\RdKafka\Conf';
        $conf = new $confClass();
        $conf->set('metadata.broker.list', KAFKA_HOST . ':' . KAFKA_PORT);
        // group.id namespaced POR AMBIENTE (kernel.php): site e manager NUNCA
        // podem compartilhar o mesmo grupo — senao os dois workers disputam
        // particoes/offsets entre ambientes e martelam a MESMA conta SMTP em
        // paralelo, o que dispara throttle do Gmail ("Could not authenticate").
        $conf->set('group.id', defined('KAFKA_CONSUMER_GROUP') ? KAFKA_CONSUMER_GROUP : 'email-worker-group');
        $conf->set('auto.offset.reset', 'earliest'); // earliest para não perder mensagens
        $conf->set('enable.auto.commit', 'false'); // Commit manual: só após envio confirmado (at-least-once)

        // Configurar callbacks para debug de rebalance
        $conf->setRebalanceCb(function ($kafka, $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    log_message("[REBALANCE] Partições ATRIBUÍDAS:");
                    foreach ($partitions as $partition) {
                        log_message("  - Partição {$partition->getPartition()}, offset {$partition->getOffset()}");
                    }
                    $kafka->assign($partitions);
                    break;

                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    log_message("[REBALANCE] Partições REVOGADAS");
                    $kafka->assign(NULL);
                    break;

                default:
                    log_message("[REBALANCE] Erro: " . rd_kafka_err2str($err));
                    break;
            }
        });

        // Adicionar debug de configuração
        log_message("Configuração Kafka:");
        log_message("  Broker: " . KAFKA_HOST . ':' . KAFKA_PORT);
        log_message("  Group ID: " . (defined('KAFKA_CONSUMER_GROUP') ? KAFKA_CONSUMER_GROUP : 'email-worker-group'));
        log_message("  Auto offset reset: earliest");
        log_message("  Auto commit: false (commit manual após envio)");
        log_message("  Tópico: " . KAFKA_TOPIC_EMAIL);

        // Criar consumer
        log_message("[DEBUG] Criando KafkaConsumer...");
        $consumerClass = '\RdKafka\KafkaConsumer';
        $consumer = new $consumerClass($conf);
        log_message("[DEBUG] KafkaConsumer criado com sucesso");

        // Inscrever no tópico
        log_message("[DEBUG] Subscrevendo ao tópico: " . KAFKA_TOPIC_EMAIL);
        $consumer->subscribe([KAFKA_TOPIC_EMAIL]);
        log_message("[DEBUG] Subscrição realizada");

        log_message("Conectado ao Kafka: " . KAFKA_HOST . ':' . KAFKA_PORT);
        log_message("Consumindo tópico: " . KAFKA_TOPIC_EMAIL);

        // Log inicial mais verboso
        log_message("Worker pronto para receber mensagens...");
        $messageCount = 0;

        // Tentativas de envio SMTP por mensagem (chave "particao:offset"). Apos
        // $maxSendAttempts falhas seguidas, o worker desiste daquela mensagem,
        // marca 'failed' na fila e comita o offset — evita que um poison de SMTP
        // (destinatario invalido, rejeicao permanente) trave a particao pra sempre.
        // O mapa e em memoria: reseta em restart/rebalance (teto por sessao).
        $sendAttempts = [];
        $maxSendAttempts = 5;

        // Loop infinito de consumo
        while (true) {
            $message = $consumer->consume(30 * 1000); // 30 segundos de timeout (reduzido)

            // Log de heartbeat a cada 20 tentativas sem mensagem.
            // null/timeout: nada a processar — continua o loop SEM dereferenciar $message
            // (consume() pode retornar null; acessar $message->err aqui causaria
            // "Attempt to read property err on null").
            if ($message === null || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
                $messageCount++;
                if ($messageCount % 20 === 0) {
                    log_message("Heartbeat: Worker ativo, aguardando mensagens... (ciclo #{$messageCount})");
                }
                pcntl_signal_dispatch(); // mantém tratamento de sinais responsivo enquanto ocioso
                continue;
            }

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    // Reset contador quando receber mensagem
                    $messageCount = 0;

                    // Mensagem recebida
                    log_message("========================================");
                    log_message("🎯 MENSAGEM RECEBIDA!");
                    log_message("Partição: {$message->partition}");
                    log_message("Offset: {$message->offset}");
                    log_message("Timestamp: " . date('Y-m-d H:i:s', $message->timestamp / 1000));
                    log_message("========================================");

                    $emailData = json_decode($message->payload, true);

                    // Mensagem malformada/poison (JSON inválido ou campos obrigatórios
                    // ausentes): nunca poderá ter sucesso. Comitamos para descartar e
                    // não bloquear a partição indefinidamente — mas logamos alto.
                    if (!is_array($emailData) || empty($emailData['to']) || empty($emailData['subject'])) {
                        log_message("❌ Mensagem inválida/poison descartada (JSON ou campos ausentes)", 'WARNING');
                        log_message("Payload raw: " . substr((string) $message->payload, 0, 200));
                        $consumer->commit($message);
                        break; // sai do switch; loop continua
                    }

                    log_message("📧 Processando email...");
                    log_message("   Assunto: {$emailData['subject']}");
                    log_message("   Destinatários: " . implode(', ', $emailData['to']));

                    // Processar email. Envolvido em Throwable para que uma mensagem
                    // poison (que dispare TypeError/Error) não mate o loop.
                    $queueId = isset($emailData['queue_id']) ? (int) $emailData['queue_id'] : 0;
                    $msgKey  = $message->partition . ':' . $message->offset;

                    try {
                        $success = sendEmailViaPHPMailer($emailData);
                    } catch (\Throwable $e) {
                        log_message("Erro inesperado ao enviar email: " . $e->getMessage(), 'ERROR');
                        $success = false;
                    }

                    if ($success) {
                        // Comita o offset ANTES de gravar na fila: a entrega ja
                        // aconteceu, entao uma falha ao atualizar o banco nao pode
                        // causar reentrega (e-mail duplicado). Writeback best-effort
                        // (mesma postura do commit-antes-do-email no webhook_controller).
                        $consumer->commit($message);
                        unset($sendAttempts[$msgKey]);
                        log_message("✅ Email entregue por SMTP");
                        if ($queueId > 0) {
                            try {
                                EmailQueueDispatcher::markDelivered($queueId);
                            } catch (\Throwable $e) {
                                log_message("Entregue, mas falhou ao marcar 'sent' na fila (queue {$queueId}): " . $e->getMessage(), 'WARNING');
                            }
                        }
                    } else {
                        $sendAttempts[$msgKey] = ($sendAttempts[$msgKey] ?? 0) + 1;

                        if ($sendAttempts[$msgKey] >= $maxSendAttempts) {
                            // Falha terminal: desiste para nao travar a particao.
                            // A mensagem nao se perde em silencio — vira 'failed'
                            // na fila (visivel, reprocessavel manualmente).
                            log_message("❌ Falha terminal apos {$sendAttempts[$msgKey]} tentativas — marcando 'failed' e descartando", 'ERROR');
                            if ($queueId > 0) {
                                try {
                                    EmailQueueDispatcher::markFailed($queueId, "SMTP falhou apos {$sendAttempts[$msgKey]} tentativas");
                                } catch (\Throwable $e) {
                                    log_message("Falha ao marcar 'failed' na fila (queue {$queueId}): " . $e->getMessage(), 'WARNING');
                                }
                            }
                            $consumer->commit($message); // destrava a particao
                            unset($sendAttempts[$msgKey]);
                        } else {
                            // Falha transitória (SMTP/rede): NÃO comita -> reentrega
                            // no próximo poll (at-least-once).
                            log_message("❌ Falha no envio ({$sendAttempts[$msgKey]}/{$maxSendAttempts}) — offset NÃO comitado, será reprocessado", 'ERROR');
                            sleep(2); // backoff pequeno para uma queda dura de SMTP não virar hot-loop
                        }
                    }

                    break;

                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    // Fim da partição, aguardando novas mensagens
                    break;

                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // Timeout, continuar aguardando
                    break;

                default:
                    log_message("Erro Kafka: " . $message->errstr(), 'ERROR');
                    break;
            }

            // Permitir que o processo seja interrompido com Ctrl+C
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    } catch (Exception $e) {
        log_message("Erro fatal no worker: " . $e->getMessage(), 'ERROR');
        log_message("Stack trace: " . $e->getTraceAsString(), 'ERROR');
        sleep(5); // Aguardar antes de tentar reconectar
    }
}

// Handler de sinais para shutdown gracioso
if (function_exists('pcntl_signal')) {
    // Sinais assíncronos: SIGTERM do `docker stop` é honrado prontamente, sem
    // esperar até o timeout de 30s do consume() (o que causaria SIGKILL no meio de um envio).
    pcntl_async_signals(true);

    pcntl_signal(SIGTERM, function () {
        log_message("Recebido SIGTERM, encerrando worker...");
        exit(0);
    });

    pcntl_signal(SIGINT, function () {
        log_message("Recebido SIGINT, encerrando worker...");
        exit(0);
    });
}

// Loop principal com auto-restart
while (true) {
    try {
        runWorker();
    } catch (Exception $e) {
        log_message("Worker encerrado com erro, reiniciando em 10 segundos...", 'ERROR');
        sleep(10);
    }
}
