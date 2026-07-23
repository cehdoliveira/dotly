# Plan 025: Remover o viewer /emails, a tabela messages e todos os seus writers

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- manager/app/inc/controller/emails_controller.php site/app/inc/lib/EmailQueueDispatcher.php manager/app/inc/lib/EmailQueueDispatcher.php manager/app/inc/controller/site_controller.php manager/public_html/index.php`
> Este plano ASSUME que os planos 020 e 021 já estão mergeados (eles removem
> vários writers de `messages`). Se `git log --oneline` não mostrar os merges
> de `advisor/020-*` e `advisor/021-*`, STOP.

## Status

- **Priority**: P2
- **Effort**: S/M
- **Risk**: LOW/MED (toca `EmailQueueDispatcher`, que está no caminho dos 2 e-mails in-scope)
- **Depends on**: `plans/020-consolidar-admin-usuarios.md` e `plans/021-purge-site-auth.md` mergeados
- **Category**: direction (less is more)
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

O escopo não pede tela de outbox de e-mail. A tabela `messages` é um log write-only duplicado: os 2 e-mails in-scope (pagamento confirmado, pedido enviado) já têm ledger próprio e melhor em `email_queue` (status `pending/sent/error`, retries, timestamps). Depois dos planos 020/021, os únicos writers de `messages` que restam são o `EmailQueueDispatcher` (cópia redigida do corpo, redundante com `email_queue`) e os fluxos de credencial de admin do manager. Sob "less is more": sai a tela, sai a tabela, saem os writes.

## Current state

Inventário completo de writers/readers de `messages` no commit `95cfe57` (re-valide na execução — Step 1):

| Local | O quê | Destino pós-planos |
|---|---|---|
| `site/app/inc/controller/checkout_controller.php:596` | log do 3º e-mail | já removido pelo plano 021 |
| `site/app/inc/controller/auth_controller.php:168,437` | logs de verify/reset do site | já removido pelo plano 021 (arquivo deletado) |
| `manager/app/inc/controller/auth_controller.php:178` | log do convite de admin | movido p/ `users_action` pelo plano 020 — remover o write AQUI (neste plano) |
| `manager/app/inc/controller/site_controller.php:346` | log do reset de admin | remover o write (neste plano) |
| `site+manager/app/inc/lib/EmailQueueDispatcher.php:83-95` | cópia redigida por envio da fila | remover o write (neste plano) |
| `manager/app/inc/controller/emails_controller.php` | ÚNICO reader (viewer `/emails`) | deletar (neste plano) |

- Rota: `manager/public_html/index.php:88` — `GET /emails → emails_controller:index`. View: `manager/public_html/ui/page/emails.php`. URL: `$emails_url` em `manager/app/inc/urls.php`. Teste: `manager/tests/MessagesFilterTest.php`.
- `EmailQueueDispatcher.php` (2 cópias byte-idênticas) — trecho em `recordOutcome()` (~:83-95): grava cópia em `messages` dentro de try/catch fail-open próprio ("nunca derruba o lote"). O comentário nas linhas 68-71 explica que o `save()` do update de `email_queue` e o log em `messages` compartilham a mesma transação — ao remover o log, NÃO toque no update de `email_queue`.
- Model: `messages_model.php` em 2 cópias (`site/app/inc/model/`, `manager/app/inc/model/`).
- Tabela: `migrations/005_create_table_messages.sql`. Drop = migration NOVA (próximo nº livre — `ls migrations/ | sort | tail -1`).
- Sidebar "E-mails" em ~11 views do manager (regra: `grep -ln "emails_url" manager/public_html/ui/page/*.php`).
- Fluxo in-scope que FICA: `webhook_controller.php` enfileira `order_paid` em `email_queue`; `orders_controller::ship()` enfileira `order_shipped`; cron `site/cgi-bin/dispatch_emails.php` processa a fila via `EmailQueueDispatcher` → `EmailProducer` (Kafka). Nada disso muda além da remoção do log espelho.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan (2 envs) | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` (idem site) | `[OK] No errors` |
| PHPUnit (2 envs) | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/<env>/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/<env>/phpunit.xml` | verde |
| Dispatcher manual | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/dispatch_emails.php` | processa sem erro |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | aplica 1x, skip na 2ª |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `manager/public_html/index.php` (rota `/emails`), `manager/app/inc/urls.php` (`$emails_url`)
- `manager/app/inc/controller/emails_controller.php`, `manager/public_html/ui/page/emails.php` (deletar)
- `manager/app/inc/controller/site_controller.php` (writes de `messages` em `users_action` — reset e criação)
- `site/app/inc/lib/EmailQueueDispatcher.php` E `manager/app/inc/lib/EmailQueueDispatcher.php` (mesma edição nos dois — byte-idênticos)
- `site/app/inc/model/messages_model.php` E `manager/app/inc/model/messages_model.php` (deletar os dois)
- `manager/tests/MessagesFilterTest.php` (deletar) + testes do dispatcher que assertem em `messages` (adaptar — ver Step 3)
- Views do manager com o `<li>` "E-mails"
- `migrations/0XX_drop_messages.sql` (nova)

**Out of scope** (NÃO tocar):
- `email_queue` (tabela, model, `OrderMailQueue`), `webhook_controller.php`, `orders_controller::ship()`, `dispatch_emails.php`, `EmailProducer.php` — o pipeline dos 2 e-mails in-scope fica intacto.
- A migration 005 existente.

## Git workflow

- Branch: `advisor/025-remover-emails-messages`
- Commits em PT-BR, Conventional Commits.
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Re-inventariar writers (o Current state pode ter drift dos planos 020/021)

```bash
grep -rn "messages_model" site/ manager/ --include="*.php" | grep -v vendor
```

Todo hit tem que estar no Scope. Hit fora → STOP.

### Step 2: Remover writes

1. `EmailQueueDispatcher.php` (2 cópias, edição idêntica): em `recordOutcome()`, delete o try/catch que instancia `messages_model` (~:83-95). O update de `email_queue` acima dele fica.
2. `manager/app/inc/controller/site_controller.php`: delete os blocos `messages_model` do `users_action` (reset-senha ~:346 e o do case `criar` que o plano 020 moveu para cá).

**Verify**: `diff site/app/inc/lib/EmailQueueDispatcher.php manager/app/inc/lib/EmailQueueDispatcher.php` → vazio. `bin/check-shared-sync.sh` exit 0. PHPStan 2 envs ainda vai acusar o model órfão? Não — o model ainda existe até o Step 4; PHPStan deve estar `[OK]`.

### Step 3: Adaptar testes do dispatcher

`grep -rln "messages" site/tests manager/tests` — os testes do plano 016 (`OrderMailQueueTest`, e a verificação de "linha de auditoria em messages" onde existir) podem assertar o log espelho. Remova SÓ os asserts sobre `messages`; os asserts sobre `email_queue` (status `sent`, dedupe) ficam. `MessagesFilterTest.php` é do viewer — delete o arquivo.

**Verify**: PHPUnit 2 envs verdes.

### Step 4: Remover viewer, model e rota

1. Rota `/emails` (index.php:88), `emails_controller.php`, `ui/page/emails.php`, `$emails_url`, `<li>` "E-mails" das sidebars.
2. Delete `messages_model.php` das 2 cópias.

**Verify**: `grep -rn "messages_model\|emails_url\|emails_controller" site/ manager/ --include="*.php" | grep -v vendor` → 0. PHPStan 2 envs `[OK]`. `/emails` → 404. `bin/check-shared-sync.sh` exit 0.

### Step 5: Migration de drop + teste funcional do pipeline

1. `migrations/0XX_drop_messages.sql`: `DROP TABLE IF EXISTS messages;`
2. Rode `run_migrations.php` (aplica; 2ª rodada skipped).
3. Teste funcional do pipeline que fica: insira uma linha de teste em `email_queue` (mesmo procedimento do plano 016 — `INSERT` manual com `orders_id` de um pedido de dev e `event_type` de teste), rode `dispatch_emails.php`, confirme `status='sent'` (com rdkafka disponível) e NENHUM erro de "table messages doesn't exist". Limpe a linha de teste depois.

**Verify**: dispatcher roda limpo pós-drop; `SHOW TABLES LIKE 'messages'` → vazio.

## Test plan

Adaptações do Step 3 + teste funcional do Step 5 (o risco real é o dispatcher referenciar a tabela dropada — o teste manual prova que não).

## Done criteria

- [ ] PHPStan `[OK]` e PHPUnit verde nos 2 ambientes
- [ ] `/emails` → 404
- [ ] `grep -rn "messages_model" site/ manager/ --include="*.php" | grep -v vendor` → 0
- [ ] Dispatcher processa `email_queue` sem erro após o drop (Step 5)
- [ ] Migration de drop aplicada e idempotente
- [ ] `bin/check-shared-sync.sh` exit 0; `git status` limpo fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- Planos 020/021 não mergeados (drift check do topo).
- Step 1 acha writer de `messages` fora do Scope.
- O teste funcional do Step 5 falhar (dispatcher com referência residual).

## Maintenance notes

- `email_queue` vira a única trilha de e-mail do sistema. Se um dia precisarem de histórico de OUTROS e-mails (fora os 2 transacionais), a resposta é enfileirar na `email_queue` com `event_type` novo — não recriar `messages`.
- Revisor: conferir que as 2 cópias do `EmailQueueDispatcher` continuam byte-idênticas e que nenhum assert de `email_queue` foi removido junto com os de `messages`.
