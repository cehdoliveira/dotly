# Plan 016: Esteira de e-mails transacionais (fila em tabela + cron + rastreio no pedido)

> **Executor instructions**: Follow this plan step by step. Run every verification
> command and confirm the expected result before moving on. If anything in "STOP
> conditions" occurs, stop and report — do not improvise. When done, update this
> plan's status row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat fdb4216..HEAD -- migrations/ manager/app/inc/controller/orders_controller.php manager/public_html/ui/page/order_detail.php site/app/inc/controller/webhook_controller.php site/app/inc/lib/EmailProducer.php manager/app/inc/lib/EmailProducer.php docker/interface/crontab`
> On any change, compare the "Current state" excerpts to live code before proceeding.

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MED/HIGH (toca o webhook de pagamento e adiciona a 1ª ação de escrita ao manager de pedidos)
- **Depends on**: none (mas 017 depende deste — cria as colunas de rastreio)
- **Category**: direction (feature — Fase 2 item #3)
- **Planned at**: commit `fdb4216`, 2026-07-16

## Decisão de arquitetura (fechada com o dono — NÃO reabrir)

O `EmailProducer` **não** tem fallback SMTP: sem a extensão `rdkafka` a classe vira um
stub que só faz `error_log` e `return false` (descarta o e-mail) — ver
`EmailProducer.php:266,280-283`. O envio SMTP real (PHPMailer) só existe **dentro** do
worker Kafka (`kafka_email_worker.php`).

Síntese aprovada, que honra tanto o pedido escrito (fila persistida + cron + retry na
tabela) quanto a escolha do dono (usar `EmailProducer`/Kafka, não um 2º sistema de e-mail):

- **`email_queue` (tabela nova) é o ledger persistente**: cada evento do ciclo do pedido
  vira uma linha (`status`, `attempts`, `last_error`, `body` já renderizado).
- **Um cron dispatcher** (`flock`, lotes pequenos) lê as linhas `pending`, e para cada uma
  chama **`EmailProducer::getInstance()->send()`** (enfileira no Kafka; o worker existente
  entrega por SMTP). Sucesso → marca `sent`. Falha (`false`/exceção) → incrementa `attempts`,
  grava `last_error`, deixa `pending` p/ re-tentar no próximo ciclo; ao atingir `max_attempts`
  → marca `failed`.
- **Caveat honesto (documentar, não resolver aqui):** com `rdkafka` desligado,
  `EmailProducer::send()` devolve `false` → as linhas ficam re-tentando e **não são enviadas**
  até o Kafka voltar (degradação fail-open). Isso é aceitável: nenhum e-mail se perde (fica
  `pending`), e o ledger mostra o backlog. Se o dono quiser envio garantido sem Kafka, é um
  follow-up (extrair o SMTP do worker p/ uma lib) — fora do escopo deste plano.

## Estado do envio (fechado com o dono — NÃO reabrir)

`orders.status` continua `ENUM('aguardando_pagamento','pago','cancelado','expirado')`.
**Não adicione `'enviado'` ao enum.** O estado "enviado" é derivado de duas colunas novas:
`tracking_code` e `shipped_at` (migration 028). Pedido enviado = `shipped_at IS NOT NULL`.
Isso mantém intacta a máquina de status de pagamento (transicionada só pelo webhook).

## Current state

- **Enum e schema de `orders`** — `migrations/012_create_table_orders.sql`. **Não há**
  `tracking_code`, `shipped_at` nem qualquer coluna de transporte. Colunas denormalizadas
  do cliente já existem: `customer_name`, `customer_mail`, `customer_phone`, `customer_cpf`.

- **Padrão idempotente de ADD COLUMN** (obrigatório — migrations rodam em loop no cron) —
  `migrations/015_add_customer_cpf_to_orders.sql`:
  ```sql
  SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'customer_cpf');
  SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `orders` ADD COLUMN `customer_cpf` CHAR(11) NOT NULL AFTER `customer_phone`', 'DO 0');
  PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  ```

- **Padrão de CREATE TABLE** (colunas de auditoria + `active`) — `migrations/005_create_table_messages.sql`
  e `022_create_table_orders_customers.sql`. Toda tabela tem `idx`, `created_at/by`,
  `modified_at/by`, `removed_at/by`, `active ENUM('yes','no')`.

- **`EmailProducer::send`** (`manager/app/inc/lib/EmailProducer.php:187`, idêntico em site/):
  `public function send(string $to, string $subject, string $body): bool` → enfileira no
  Kafka; retorna `false` em falha. **Fire-and-forget.**

- **Padrão de envio + auditoria já em uso** — `site/app/inc/controller/checkout_controller.php:579-608`
  (`sendConfirmationEmail`): renderiza `ui/mail/*.php` via `ob_start()`+`include`+`ob_get_clean()`,
  chama `EmailProducer::getInstance()->send()` dentro de try/catch (fail-open), e persiste
  cópia em `messages` via `messages_model->populate([...])->save()` com `redact_email_body($body)`.

- **Templates de e-mail** — `<app>/public_html/ui/mail/*.php`, HTML inline com
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` e `constant('cTitle')` p/ a marca. Exemplo:
  `site/public_html/ui/mail/order_confirmation.php`. **`ui/` NÃO é compartilhado** entre
  manager e site.

- **Webhook de pagamento** — `site/app/inc/controller/webhook_controller.php`. A transição
  para pago acontece em `:120-134`:
  ```php
  // ...quando o PSP confirma:
  'status'  => 'pago',
  'paid_at' => $paidAt,
  ```
  (dois pontos, `:124-125` e `:132-133`, para os dois ramos de gateway). Este é o gatilho
  do evento (a) "pagamento confirmado".

- **Detalhe do pedido (manager)** — controller `orders_controller::show`
  (`manager/app/inc/controller/orders_controller.php:60`), view
  `manager/public_html/ui/page/order_detail.php`. Hoje o controller de pedidos é **100%
  leitura** (comentário `:4-8`: status é transicionado só pelo webhook). Este plano
  adiciona a **primeira ação de escrita** (marcar como enviado) — deliberado e escopado.

- **Bootstrap de job em cgi-bin** — `site/cgi-bin/run_migrations.php`:
  ```php
  define('APP_PATH', realpath(__DIR__ . '/../app'));
  require_once APP_PATH . '/inc/kernel.php';
  require_once APP_PATH . '/inc/lib/localPDO.php';
  require_once APP_PATH . '/inc/lib/MigrationRunner.php';
  $pdo = new localPDO();
  // ... roda, exit(code)
  ```

- **Cron** — `docker/interface/crontab`, única linha ativa (padrão `flock -n` a reusar):
  ```
  */5 * * * * flock -n /tmp/infinnityimportacao_migrate.lock php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php >> /var/log/migrations.log 2>&1
  ```
  `docker/interface/entrypoint.sh:49-55` já sobe os workers Kafka; o dispatcher novo é
  **cron**, não entrypoint.

- **Migration novas** — próximo número livre: **027** (o maior atual é `026`).
- **CSRF** — POST valida com `validate_csrf($info['post']['_csrf_token'] ?? null, $redirectUrl)`
  como 1ª instrução (padrão em todos os `*_controller::action`). Gerar token antes de
  renderizar o form: `if (empty($_SESSION['_csrf_token'])) $_SESSION['_csrf_token'] = random_token();`

## Commands you will need

| Purpose         | Command                                                                                              | Expected         |
|-----------------|------------------------------------------------------------------------------------------------------|------------------|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse`                                            | `[OK] No errors` |
| PHPStan site    | `cd site && php app/inc/lib/vendor/bin/phpstan analyse`                                               | `[OK] No errors` |
| Migrations      | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php`   | 0 failures       |
| PHPUnit         | `cd manager && php app/inc/lib/vendor/bin/phpunit` / `cd site && ...`                                 | all pass         |
| Shared-sync     | `bin/check-shared-sync.sh`                                                                            | exit 0           |
| Lint            | `php -l <arquivo>`                                                                                    | No syntax errors |

Se não houver DB/Docker acessível, teste migrations contra um schema descartável (NÃO a
base de dev) e registre no relatório. **Nunca** rode migrations destrutivas na base real.

## Scope

**In scope**:
- `migrations/027_create_table_email_queue.sql` — **create**
- `migrations/028_add_tracking_to_orders.sql` — **create**
- `manager/app/inc/model/email_queue_model.php` + `site/app/inc/model/email_queue_model.php` — **create** (byte-idênticos)
- `manager/app/inc/model/orders_model.php` + `site/app/inc/model/orders_model.php` — add `tracking_code`, `shipped_at` a `$field` (byte-idênticos)
- `manager/app/inc/lib/OrderMailQueue.php` + `site/app/inc/lib/OrderMailQueue.php` — **create**, helper de enfileiramento (byte-idênticos)
- `manager/app/inc/lib/EmailQueueDispatcher.php` + `site/app/inc/lib/EmailQueueDispatcher.php` — **create** (adicionado na revisão adversarial, byte-idênticos): lógica de processamento de 1 linha da fila (`processRow`/`attemptSend`/`recordOutcome`), extraída de `dispatch_emails.php` para ser testável por PHPUnit — ver `Step 8`
- `manager/app/inc/controller/orders_controller.php` — add ação `ship()` (POST)
- `manager/public_html/ui/page/order_detail.php` — add form de rastreio + botão
- `manager/public_html/ui/mail/order_shipped.php` — **create** (template do e-mail de envio)
- `site/public_html/ui/mail/order_paid.php` — **create** (template do e-mail de pagamento)
- `site/app/inc/controller/webhook_controller.php` — enfileirar evento `order_paid` após transição p/ pago
- `site/cgi-bin/dispatch_emails.php` — **create** (cron dispatcher)
- `manager/app/inc/urls.php` — add `$order_ship_url`
- `manager/public_html/index.php` — add rota POST `/pedidos/{id}/enviar`
- `docker/interface/crontab` — add 1 linha `flock`
- `manager/tests/OrderShipTest.php`, `manager/tests/OrderMailQueueTest.php`, `site/tests/WebhookEnqueueTest.php` — **create**

**Out of scope**:
- Alterar o `EmailProducer` ou o `kafka_email_worker.php` (reusamos como estão).
- Adicionar `'enviado'` ao enum de status.
- Adicionar dependência ao composer.
- Extrair o SMTP do worker (follow-up documentado acima).
- Mudar `docker-compose.yml` / `entrypoint.sh` (o dispatcher é cron; cgi-bin já é montado).
- Qualquer lógica de pagamento além de **inserir** a linha na fila no ponto já existente
  de transição p/ pago.

## Git workflow

- Branch: `advisor/016-esteira-emails`
- Conventional Commits PT-BR, commit por unidade lógica (migrations, model, dispatcher, UI, webhook).
  Ex.: `feat: adiciona fila de e-mails transacionais e disparo por cron`
- Sem push/PR salvo instrução do operador.

## Steps

### Step 1: Migration 027 — tabela `email_queue`

`migrations/027_create_table_email_queue.sql`. Use `CREATE TABLE IF NOT EXISTS` (idempotente):
```sql
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
    `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
    `last_error` TEXT DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uq_email_queue_event` (`orders_id`, `event_type`),
    KEY `idx_email_queue_status` (`status`, `idx`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```
O `UNIQUE (orders_id, event_type)` garante **um** e-mail por evento por pedido (dedupe:
se o webhook disparar 2×, o 2º INSERT falha por chave duplicada e é ignorado — trate com
`INSERT ... ON DUPLICATE KEY UPDATE idx = idx` ou capture o erro no helper, Step 4).

**Verify**: aplique via `run_migrations.php`; rode 2× → a 2ª vez pula (idempotente). Se
sem Docker, aplique num schema descartável.

### Step 2: Migration 028 — `tracking_code` + `shipped_at` em `orders`

`migrations/028_add_tracking_to_orders.sql`, **duas** colunas, cada uma com o guard
idempotente de `information_schema` (padrão de 015, repetido p/ cada coluna como em 020):
- `tracking_code VARCHAR(64) DEFAULT NULL AFTER paid_at`
- `shipped_at DATETIME DEFAULT NULL AFTER tracking_code`

**Verify**: aplica idempotente (2× sem erro).

### Step 3: Models — `email_queue_model` + `tracking` em `orders_model` (2 cópias cada)

- Crie `email_queue_model.php` (mínimo, padrão de `messages_model`) em
  `manager/app/inc/model/` **e** `site/app/inc/model/`, byte-idênticos:
  ```php
  <?php
  class email_queue_model extends DOLModel
  {
      protected array $field = [" idx ", " event_type ", " orders_id ", " to_mail ",
          " subject ", " body ", " status ", " attempts ", " max_attempts ",
          " last_error ", " sent_at "];
      protected array $filter = [" active = 'yes' "];
      function __construct() { parent::__construct("email_queue"); }
  }
  ```
- Em `orders_model.php` (as 2 cópias), adicione `" tracking_code "` e `" shipped_at "` ao `$field`.

**Verify**: `diff manager/app/inc/model/email_queue_model.php site/app/inc/model/email_queue_model.php`
→ sem diff; idem `orders_model.php`; `bin/check-shared-sync.sh` → exit 0.

### Step 4: Helper de enfileiramento — `OrderMailQueue` (2 cópias)

`manager/app/inc/lib/OrderMailQueue.php` **e** `site/app/inc/lib/OrderMailQueue.php`
(byte-idênticos). Um método estático que renderiza o template do evento, monta o subject,
e **insere** a linha na fila (idempotente por `orders_id+event_type`). Como `ui/` difere
por app, o helper recebe o **body já renderizado** pelo chamador (que sabe onde está seu
template) — o helper só persiste:
```php
<?php
class OrderMailQueue
{
    /** Enfileira um e-mail transacional. Idempotente por (orders_id, event_type).
     *  Fail-open: nunca lança — só loga (não pode derrubar webhook nem checkout). */
    public static function enqueue(int $orderId, string $eventType, string $toMail,
                                   string $subject, string $body): void
    {
        try {
            $m = new email_queue_model();
            // INSERT direto p/ respeitar o UNIQUE(orders_id,event_type) como dedupe.
            $m->execute_raw_prepared(
                "INSERT INTO email_queue
                   (created_at, active, event_type, orders_id, to_mail, subject, body, status, attempts, max_attempts)
                 VALUES (NOW(), 'yes', ?, ?, ?, ?, ?, 'pending', 0, 5)
                 ON DUPLICATE KEY UPDATE idx = idx",
                [$eventType, $orderId, $toMail, $subject, $body]
            );
        } catch (\Throwable $e) {
            error_log("OrderMailQueue::enqueue falhou (order {$orderId}, {$eventType}): " . $e->getMessage());
        }
    }
}
```
> Nota LEGGO: a transação global do request commita/reverte via `basic_redir()`. No
> webhook, o INSERT na fila roda na mesma transação da confirmação do pedido — se o
> webhook der rollback, a linha da fila também some (correto: não anuncia pagamento que
> não persistiu). No cgi-bin/manager idem.
>
> **Atualizado (revisão adversarial):** essa garantia deixou de valer como descrito
> acima. `webhook_controller.php` agora faz um `commit()` explícito **antes** do
> enqueue (para isolar a falha do envio de e-mail da transação de pagamento), e
> `orders_controller::markAsShipped()` / `EmailQueueDispatcher::recordOutcome()`
> fazem o mesmo split `commit()`+`beginTransaction()`. O enqueue não reverte mais
> junto com um rollback do request — é uma escrita à parte, deliberadamente
> desacoplada. Ver `fix: adversarial review — ... commit antes de efeito colateral
> fail-open` no histórico do branch.

**Verify**: `php -l` nos 2 arquivos; `diff` → idênticos.

### Step 5: Templates de e-mail

- `site/public_html/ui/mail/order_paid.php` — "Pagamento confirmado / pedido em
  processamento". Copie a estrutura de `site/public_html/ui/mail/order_confirmation.php`
  (HTML inline, `htmlspecialchars`, `constant('cTitle')`). Variáveis esperadas em escopo:
  `$name`, `$orderToken` (ou link de acompanhamento). NÃO inclua CPF nem endereço completo.
- `manager/public_html/ui/mail/order_shipped.php` — "Pedido enviado". Variáveis: `$name`,
  `$trackingCode` (pode ser `''` → texto "sem código de rastreio"), e o link/telefone de
  contato. Mesma estrutura HTML inline.

**Verify**: `php -l` em ambos → No syntax errors.

### Step 6: Enfileirar `order_paid` no webhook (site) — ÁREA SENSÍVEL

Em `site/app/inc/controller/webhook_controller.php`, **imediatamente após** o ponto que
grava `status => 'pago'` (`:120-134`, os dois ramos), renderize o template `order_paid.php`
e chame `OrderMailQueue::enqueue(...)`. Padrão (fail-open, nunca derruba o webhook):
```php
// depois de persistir o pedido como pago:
ob_start();
$name       = $order['customer_name'];
$orderToken = $order['token'];
include(constant("cRootServer") . "ui/mail/order_paid.php");
$body = ob_get_clean();
OrderMailQueue::enqueue((int)$order['idx'], 'order_paid', $order['customer_mail'],
    "Pagamento confirmado — " . constant('cTitle'), (string)$body);
```
⚠️ Leia o webhook inteiro antes: confirme os nomes das variáveis do pedido no escopo
(`$order['...']` vs outra estrutura) e que existe **um** ponto lógico de "acabou de ficar
pago" (não enfileire em ramo que só revalida um pedido já pago — o `UNIQUE` protege, mas
o certo é enfileirar na transição). Se o webhook tratar reentrância (`if ($charge['status'] === 'pago')`
em `:73`), coloque o enqueue **só** no caminho de transição nova.

**Verify**: PHPStan site `[OK]`; teste `WebhookEnqueueTest` (Step 9).

### Step 7: Ação `ship()` no manager + form no detalhe do pedido

- **Rota + URL**: `manager/app/inc/urls.php`:
  `$order_ship_url = sprintf("%s%s/%s/%s", constant("cFrontend"), "pedidos", "%d", "enviar");`
  `manager/public_html/index.php`: `POST` atrás de `$authGuard` p/ `/pedidos/{id}/enviar`
  → `orders_controller:ship`. Confira o formato de `add_route` e o grupo de captura do id.
- **`orders_controller::ship(array $info)`**:
  1. `validate_csrf($info['post']['_csrf_token'] ?? null, $orders_url);` (1ª linha).
  2. `$idx = (int)($info[1] ?? 0);` (o id vem do path, como em `show`). `if ($idx <= 0) basic_redir($orders_url);`
  3. Carrega o pedido (`orders_model`, `set_filter([" active='yes' "," idx = ? "], [$idx])`).
     Se não existe → mensagem danger + `basic_redir($orders_url)`.
  4. Normaliza `tracking_code`: `$tracking = trim($info['post']['tracking_code'] ?? '');`
     (aceita vazio — o botão "Envio realizado" sem código). Limite a 64 chars.
  5. `populate()` + `save()` gravando `tracking_code = $tracking` (ou NULL se vazio) e
     `shipped_at = date('Y-m-d H:i:s')`. **Não** mexa em `status`.
  6. Renderiza `manager/public_html/ui/mail/order_shipped.php` (ob_start/include) e
     `OrderMailQueue::enqueue($idx, 'order_shipped', $order['customer_mail'], "Seu pedido foi enviado — ".constant('cTitle'), $body);`
  7. Mensagem de sucesso + `basic_redir(sprintf($order_url, $idx));` (o redirect commita a transação).
- **View** `order_detail.php`: adicione um painel com um form `method="POST"`
  action `sprintf($order_ship_url, $orderIdx)`, com hidden `_csrf_token`, um input
  `name="tracking_code"` (opcional, placeholder "Código de rastreio (opcional)") e um
  botão "Envio realizado". Se `$order['shipped_at']` já estiver preenchido, mostre o
  código/estado em vez do form (evita reenviar). O controller `show` precisa passar
  `tracking_code`/`shipped_at` à view — adicione essas 2 colunas ao `set_field` de `show`
  (elas já estão no `$field` do model após Step 3, mas `show` seta um `set_field` explícito
  em `:74-78`; inclua as duas ali).

**Verify**: PHPStan manager `[OK]`; teste manual: abrir um pedido, clicar "Envio realizado"
com um código → `orders.shipped_at`/`tracking_code` gravados, 1 linha `order_shipped` em
`email_queue`.

### Step 8: Cron dispatcher — `site/cgi-bin/dispatch_emails.php`

Bootstrap igual a `run_migrations.php` (define APP_PATH → require kernel/localPDO →
require model). Lógica:
```php
$pdo = new localPDO();
$m = new email_queue_model();
$m->set_field([" idx "," to_mail "," subject "," body "," attempts "," max_attempts "]);
$m->set_filter([" active = 'yes' ", " status = 'pending' "]);
$m->set_order([" idx ASC "]);
$m->set_paginate([0, 20]); // LOTE PEQUENO: no máx. 20 por execução (respeita limite Gmail)
$m->load_data(false);

foreach ($m->data as $row) {
    $ok = false;
    try {
        if (class_exists("EmailProducer")) {
            $ok = EmailProducer::getInstance()->send($row['to_mail'], $row['subject'], $row['body']);
        }
    } catch (\Throwable $e) {
        error_log("dispatch_emails: send falhou (queue {$row['idx']}): " . $e->getMessage());
    }

    $upd = new email_queue_model();
    if ($ok) {
        $upd->populate(["idx" => (int)$row['idx'], "status" => "sent", "sent_at" => date("Y-m-d H:i:s")]);
        $upd->save();
        // auditoria (mesmo padrão do checkout): grava cópia redigida em messages
        try {
            $msg = new messages_model();
            $msg->populate(["to_mail" => $row['to_mail'], "subject" => $row['subject'],
                "body" => redact_email_body($row['body']), "sent_at" => date("Y-m-d H:i:s")]);
            $msg->save();
        } catch (\Throwable $e) { error_log("dispatch_emails: log messages falhou: ".$e->getMessage()); }
    } else {
        $attempts = (int)$row['attempts'] + 1;
        $status = $attempts >= (int)$row['max_attempts'] ? 'failed' : 'pending';
        $upd->populate(["idx" => (int)$row['idx'], "attempts" => $attempts, "status" => $status,
            "last_error" => "envio retornou false/exceção em " . date("c")]);
        $upd->save();
    }
    // commit por linha: cada save() precisa persistir. No cgi-bin não há basic_redir;
    // confirme como run_migrations commita (localPDO). Se a transação global exigir commit
    // explícito no fim, faça-o UMA vez ao final do loop — ver STOP condition.
}
exit(0);
```
⚠️ **Commit no cgi-bin**: `basic_redir` não existe fora do request web. Leia
`MigrationRunner`/`localPDO` para ver como `run_migrations.php` persiste (ele commita via
`->commit()` explícito, ver `WebhookIdempotencyTest` que documenta o singleton de conexão).
Replique o mesmo mecanismo de commit que o runner usa. Se `localPDO` abre transação no
construtor, faça **um** `commit()` ao final (ou por lote) — NÃO deixe o `__destruct`
reverter tudo.

- **Crontab** — adicione em `docker/interface/crontab` (mesmo padrão flock):
  ```
  # Disparar e-mails transacionais pendentes (lote pequeno; retry fica na tabela)
  0 * * * * flock -n /tmp/infinnityimportacao_dispatch_emails.lock php /var/www/infinnityimportacao/site/cgi-bin/dispatch_emails.php >> /var/log/dispatch_emails.log 2>&1
  ```
  (1×/hora. Se quiser janela menor, use `*/15 * * * *`; o lote de 20 + `flock` já limita o
  ritmo. Não estoure o limite do Gmail: 20/hora é conservador.)

**Verify**: `php -l site/cgi-bin/dispatch_emails.php`. Com Docker: enfileire uma linha de
teste, rode o dispatcher, confirme `status` vira `sent` (ou fica `pending` com `attempts=1`
se o Kafka estiver off — comportamento esperado documentado).

### Step 9: Testes

Todos estendem `DBTestCase`. Modele por `CustomerUpsertTest`/`WebhookIdempotencyTest`.
- **`OrderMailQueueTest`** (manager): `enqueue()` insere 1 linha `pending`; chamar 2× com
  mesmo `(orders_id,event_type)` **não** duplica (UNIQUE + ON DUPLICATE). `attempts=0`.
- **`OrderShipTest`** (manager): via Reflection/chamada da lógica de `ship()` (ele faz
  `basic_redir`/`exit` no fluxo web — extraia a parte de escrita p/ um método testável, ou
  teste o efeito: grava `shipped_at`+`tracking_code` e enfileira `order_shipped`). Cubra:
  com código e sem código (botão "Envio realizado").
- **`WebhookEnqueueTest`** (site): simula a transição p/ pago e verifica que 1 linha
  `order_paid` foi enfileirada com o `to_mail` = `customer_mail` do pedido; reentrância do
  webhook não duplica.

**Verify**: PHPUnit manager e site verdes com os novos casos.

### Step 10: Verificação final

Rode toda a tabela "Commands you will need".

## Test plan

- Novos: `OrderMailQueueTest`, `OrderShipTest` (manager), `WebhookEnqueueTest` (site).
- Padrão: `CustomerUpsertTest` (Reflection p/ privados), `WebhookIdempotencyTest` (webhook + commit).
- O dispatcher (`cgi-bin`) não é testável por PHPUnit web facilmente; cubra a **lógica de
  transição de status da fila** extraindo-a se necessário, ou valide manualmente com Docker
  e registre o resultado no relatório.

## Done criteria

- [ ] Migrations 027 e 028 aplicam idempotentes (2× sem erro) via `run_migrations.php`
- [ ] `bin/check-shared-sync.sh` → exit 0 (email_queue_model, orders_model, OrderMailQueue idênticos nas 2 cópias)
- [ ] PHPStan manager e site → `[OK] No errors`
- [ ] PHPUnit manager e site verdes, com OrderMailQueueTest / OrderShipTest / WebhookEnqueueTest
- [ ] Botão "Envio realizado" (com e sem código) grava `shipped_at`/`tracking_code` e enfileira `order_shipped`
- [ ] Transição p/ pago no webhook enfileira `order_paid` (uma vez; reentrância não duplica)
- [ ] Dispatcher processa em lote de ≤20, marca `sent`/incrementa `attempts`, e há a linha no crontab
- [ ] `git status` sem arquivos fora do In scope
- [ ] Status row atualizado em `plans/README.md`

## STOP conditions

Pare e reporte se:
- O webhook (`:120-134`) não bate com o excerpt (drift) OU não há um ponto claro de
  "transição nova para pago" — **não** enfileire às cegas em ramo de reentrância.
- Você não conseguir determinar como o cgi-bin commita a transação (o `dispatch_emails`
  não pode depender de `basic_redir`, que não existe fora do web) — pare antes de deixar
  os `save()` sem commit (o `__destruct` do `localPDO` reverteria tudo silenciosamente).
- Adicionar as colunas/tabela exigir tocar arquivo fora do In scope.
- Você sentir necessidade de mexer no `EmailProducer`/worker ou de adicionar dependência
  — pare e reporte (é follow-up, não este plano).
- Uma verificação falha 2× após uma tentativa razoável de conserto.

## Maintenance notes

- **Sem rdkafka o dispatcher não envia** (send retorna false), as linhas ficam `pending`/
  re-tentando. É a degradação fail-open aceita. Follow-up p/ envio garantido: extrair
  `sendEmailViaPHPMailer` do `kafka_email_worker.php` p/ uma lib compartilhada e o dispatcher
  chamá-la direto (não fazer aqui).
- `max_attempts=5`: após 5 falhas a linha vira `failed` e sai da fila. Um relatório de
  `email_queue WHERE status='failed'` seria útil no manager (follow-up, não pedido).
- O `UNIQUE(orders_id,event_type)` limita a 1 e-mail por evento por pedido. Se o produto
  precisar reenviar (ex.: trocar código de rastreio), a ação de envio deve fazer UPDATE da
  linha existente + resetar `status='pending'`, não INSERT — revisitar quando isso for pedido.
- Revisor deve escrutinar: o enqueue no webhook está no caminho de transição (não de
  reentrância); o commit do cgi-bin persiste os `save()`; nenhum template vaza CPF/endereço.
- Interage com o **plano 017** (público): ele lê `tracking_code`/`shipped_at` criados aqui.
  017 não pode ser executado antes deste.
- **Adicionado na revisão adversarial (não previsto originalmente neste plano):**
  - `site/cgi-bin/dispatch_emails.php` usa `GET_LOCK`/`RELEASE_LOCK` (advisory lock no
    MySQL) como defesa em profundidade além do `flock -n` do crontab — mesmo padrão de
    `MigrationRunner::run()`.
  - `orders_controller::markAsShipped()` lança `RuntimeException` ("Pedido já foi
    marcado como enviado.") numa 2ª chamada para o mesmo pedido — guarda de backend
    além de esconder o form na UI quando `shipped_at` já está preenchido.
  - `WebhookEnqueueTest` (ver docblock do arquivo) **não** exercita o caminho de sucesso
    real de `processEvent()` (evitaria commitar dados de teste permanentemente no
    singleton `localPDO` compartilhado pela suite) — testa `OrderMailQueue::enqueue()`
    diretamente com os mesmos argumentos que o webhook usaria. Que a chamada está no
    branch certo (transição nova, não reentrância) foi verificado por leitura de código,
    não por teste automatizado end-to-end.
