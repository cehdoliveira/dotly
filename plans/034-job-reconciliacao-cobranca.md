# Plan 034: Job de reconciliação de cobrança (fallback de webhook perdido)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat c9366db..HEAD -- site/app/inc/controller/webhook_controller.php site/app/inc/lib/MercadoPagoGateway.php site/app/inc/lib/PagBankGateway.php site/cgi-bin docker/interface/crontab`
> If any in-scope path changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.
>
> **Reconciliado em 2026-07-20** (commit `0c3158b` → `c9366db`): `webhook_controller.php`
> ganhou (plano 031) a reconfirmação InfinitePay via `confirmPayment()` e um guard
> `WHERE status <> 'expirado'` contra corrida com o job de expiração (plano 032) — só
> linhas deslocaram (~+70), a lógica de "marcar pago" que este plano espelha não mudou
> de forma. Excertos abaixo já atualizados para os números de linha atuais. O guard
> `status <> 'expirado'` do webhook é a mesma proteção que o `WHERE status =
> 'aguardando_pagamento'` do Step 1c já dava por construção (um pedido `expirado` não
> está em `aguardando_pagamento`) — nenhuma mudança de abordagem necessária.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: 032 (recomendado — a ordem de precedência entre expirar e
  confirmar importa; ver "Why" e "Maintenance notes")
- **Category**: bug
- **Planned at**: commit `0c3158b`, 2026-07-20 — **reconciliado** contra `c9366db`,
  2026-07-20 (ver nota de drift check acima; sem mudança de abordagem)

## Why this matters

Se o webhook de pagamento falhar ou atrasar (rede, PSP, janela de deploy), um
pedido **pago** fica preso em `aguardando_pagamento` **para sempre** — dinheiro
recebido, pedido não processado, perda de receita silenciosa. O único ator que
transiciona `aguardando_pagamento → pago` hoje é o webhook (push); não existe o
job de reconciliação (pull) que o próprio código do manager assume existir
(`manager/app/inc/controller/orders_controller.php:5-9`). Este job faz o polling
de fallback: para pedidos pendentes recentes roteados a **MercadoPago/PagBank**
(que expõem consulta de status), confirma no PSP e marca `pago` se confirmado.
**InfinitePay não tem endpoint de consulta** (`InfinitePayGateway::fetchStatus()`
sempre devolve `'pendente'`), então fica de fora — para ele, o fallback é só a
expiração por tempo (plano 032).

## Current state

- **`fetchStatus()` reconfirma no PSP** e devolve `'pago'|'expirado'|'pendente'|'erro'`:
  - `site/app/inc/lib/MercadoPagoGateway.php:159-185` — `GET /v1/payments/{id}`,
    `approved → 'pago'`.
  - `site/app/inc/lib/PagBankGateway.php:155-188` — `GET /orders/{id}`,
    `PAID → 'pago'`; 404 (pendente) tratado como `'pendente'`.
  - `site/app/inc/lib/InfinitePayGateway.php:160-169` — **sempre `'pendente'`**
    (sem endpoint). Este job NÃO chama fetchStatus para InfinitePay.
  O argumento de `fetchStatus` é o `gateway_charge_id` que já gravamos em
  `pix_charges`.

- **Como o webhook marca pago** (a lógica a espelhar) —
  `site/app/inc/controller/webhook_controller.php:194-296` (atualizado no reconcile
  de 2026-07-20; era `:119-187` no commit em que o plano foi escrito):
  ```php
  $paidAt = date('Y-m-d H:i:s');
  // pix_charges: UPDATE ... WHERE idx=? AND status <> 'expirado', status='pago', paid_at
  // orders:      UPDATE ... WHERE idx=? AND status <> 'expirado', status='pago', paid_at
  // commit() explicito
  // enfileira e-mail 'order_paid' (best-effort, DEPOIS do commit)
  ```
  O plano 031 acrescentou o guard `status <> 'expirado'` (corrida com o job de
  expiração, plano 032) — é a mesma proteção que o `WHERE status =
  'aguardando_pagamento'` do Step 1c já dá por construção. A confirmação de valor
  (`paidAmountCents >= order.total_cents`, agora `:171-192`) é do webhook; na
  reconciliação, o `fetchStatus()=='pago'` **é** a confirmação de que o PSP recebeu
  o valor cheio daquela cobrança específica (cobrança PIX de valor fixo não é paga
  a menor sem falhar) — não há `paid_amount` a comparar.

- **`pix_charges`** — colunas relevantes: `orders_id`, `payment_gateways_id`,
  `gateway_charge_id`, `status ENUM('pendente','pago','expirado','erro')`,
  `active`. **`payment_gateways`** tem `slug` (`mercadopago`/`pagbank`/`infinitepay`)
  e `idx`. Para saber o gateway de uma cobrança: `pix_charges.payment_gateways_id
  → payment_gateways.slug`.

- **Padrão de job cron** — `site/cgi-bin/dispatch_emails.php` é o molde (bootstrap
  CLI, `GET_LOCK` advisório, commit por unidade, fail-open). Ver plano 032
  "Current state" para os trechos exatos; este plano segue o mesmo esqueleto.

- **`OrderMailQueue::enqueue(...)`** e o template `ui/mail/order_paid.php` já são
  usados pelo webhook (`:164-176`) — reutilize-os na reconciliação.

- **Crontab** — `docker/interface/crontab:27-34`: migrations + emails hoje;
  o plano 032 adiciona a linha de expiração. Este plano adiciona a de reconciliação.

## Convenções do repositório (obrigatórias)

- **Transação global única por processo** (`localPDO::getInstance()` singleton).
  O job commita explicitamente, como o webhook e o `dispatch_emails`.
- **Soft-delete** (`active`), nunca `DELETE`. Reconciliação é `UPDATE` de status.
- **Lógica testável em `app/inc/lib` (2 cópias byte-idênticas** site+manager),
  script `cgi-bin` casca fina. `bin/check-shared-sync.sh` bloqueia divergência.
- **Chamada HTTP ao PSP dentro de job**, não de request de comprador — ok, é o
  ponto do fallback. Use timeout curto (o `request()` dos adapters já tem).
- Jobs `cgi-bin/` e o crontab são só do site.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHP lint | `php -l site/cgi-bin/reconcile_charges.php` | `No syntax errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Shared-sync guard | `bin/check-shared-sync.sh` | exit 0 |
| Diff das 2 cópias | `diff -q site/app/inc/lib/OrderReconciler.php manager/app/inc/lib/OrderReconciler.php` | (sem saída) |
| Testes (filtro) | `cd site && php app/inc/lib/vendor/bin/phpunit --filter Reconcil` | all pass |

## Scope

**In scope** (crie/edite só estes):
- `site/cgi-bin/reconcile_charges.php` (novo — casca fina)
- `site/app/inc/lib/OrderReconciler.php` (novo — lógica testável)
- `manager/app/inc/lib/OrderReconciler.php` (novo — cópia byte-idêntica)
- `docker/interface/crontab` (adiciona 1 linha)
- Teste novo (`OrderReconcilerTest.php`, + cópia manager se em `lib/`)

**Out of scope** (NÃO toque):
- `webhook_controller.php` — **não** o edite. A reconciliação espelha a escrita
  dele, não a refatora (ver "Decisão de abordagem"). Se você achar que precisa
  editá-lo, é STOP.
- Os adapters de gateway (`*Gateway.php`) — só **consuma** `fetchStatus()`.
- InfinitePay — excluído por design (sem endpoint).
- Expiração/estorno (plano 032), rate limit (plano 033), auth do webhook (031).

## Decisão de abordagem (leia antes das Steps)

O ideal de engenharia seria extrair "marcar pedido pago + enfileirar e-mail" para
um método compartilhado usado pelo webhook E pela reconciliação. Mas isso exigiria
editar `webhook_controller.php` (fora de escopo, é a rota mais sensível do site).
**Para este plano, o `OrderReconciler` replica a escrita do webhook** (mesmas
colunas, mesmo e-mail), com um comentário apontando o webhook como fonte da
verdade. A duplicação é consciente e registrada em "Maintenance notes" como
follow-up de refatoração. **Não** refatore o webhook aqui.

## Git workflow

- Branch: `advisor/034-job-reconciliacao-cobranca`
- Commits PT-BR Conventional Commits (`feat:`). Ex.: `fix: remover etapa "Entrega"...`.
- Sem push/PR salvo instrução do operador.

## Steps

### Step 1: Criar `OrderReconciler` com a lógica testável

Crie `site/app/inc/lib/OrderReconciler.php` com a classe `OrderReconciler` expondo:

- `reconcilePending(?string $now = null): array` — retorna resumo
  (`['checked' => int, 'confirmed' => int, 'skipped' => int]`). Sem
  `basic_redir()`/`exit()`.

Lógica:

1. `$now = $now ?? date('Y-m-d H:i:s')` (PHP, não `NOW()` — skew de fuso conhecido).

2. Selecionar cobranças pendentes elegíveis — pedidos ainda aguardando pagamento,
   **de gateways com consulta (mercadopago/pagbank)**, dentro de uma janela
   razoável (ex.: criados nas últimas 24h) para não martelar o PSP com histórico
   antigo:
   ```sql
   SELECT pc.idx AS charge_idx, pc.gateway_charge_id, pc.orders_id, pg.slug
     FROM pix_charges pc
     JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id
     JOIN orders o           ON o.idx  = pc.orders_id
    WHERE pc.active = 'yes' AND pc.status = 'pendente'
      AND o.active = 'yes' AND o.status = 'aguardando_pagamento'
      AND pg.slug IN ('mercadopago','pagbank')
      AND o.created_at >= ?            -- $now - 24h, calculado em PHP e bindado
    ORDER BY pc.idx ASC
    LIMIT 100
   ```
   (Lote pequeno; próximo tick pega o resto. Ajuste a janela se o dono preferir.)

3. Para cada cobrança:
   a. Instanciar o adapter do `slug` (mesmo `match` do
      `checkout_controller::finalize():189-194` / `webhook_controller`) e chamar
      `$gateway->fetchStatus($gateway_charge_id)`.
   b. Se o retorno **não** for `'pago'`, incrementar `skipped` e seguir (não
      transiciona nada aqui — `'expirado'` fica a cargo do plano 032; misturar
      responsabilidades aumenta o risco).
   c. Se for `'pago'`, **re-verificar sob transação** que o pedido ainda está
      `aguardando_pagamento` (o webhook pode ter chegado nesse meio-tempo) com um
      UPDATE condicional espelhando o webhook:
      ```sql
      UPDATE orders SET status='pago', paid_at=?, modified_at=?
       WHERE idx=? AND status='aguardando_pagamento'
      ```
      Se `rowCount() !== 1`, já foi confirmado por outro caminho → `skipped`, siga.
      Se `=== 1`:
      ```sql
      UPDATE pix_charges SET status='pago', paid_at=?, modified_at=?
       WHERE idx=? AND status='pendente'
      ```
      commit (ver Step 2 sobre commit por unidade), e **depois do commit**
      enfileirar o e-mail `order_paid` (best-effort, try/catch — espelhe
      `webhook_controller.php:164-185`: `ob_start()`, `include ui/mail/order_paid.php`,
      `OrderMailQueue::enqueue(...)`). Incrementar `confirmed`.

   Comentário obrigatório no topo do bloco de escrita: apontar
   `webhook_controller.php:194-296` como a fonte da verdade que este código
   espelha (ver "Decisão de abordagem").

**Verify**: `php -l site/app/inc/lib/OrderReconciler.php` → `No syntax errors`.

### Step 2: Criar o script cron `reconcile_charges.php`

Copie o esqueleto de `site/cgi-bin/dispatch_emails.php`:
- Mesmo bootstrap CLI (kernel + autoload, `$_SERVER` fake, timezone).
- `GET_LOCK('infinnityimportacao_reconcile_charges', 0)`; se não obtiver, `exit(0)`.
- `(new OrderReconciler())->reconcilePending()` em try/catch fail-open.
- **Commit por cobrança confirmada** (não único no fim — mesma razão do
  `dispatch_emails.php:65-74`): a chamada HTTP ao PSP é efeito externo; commit por
  unidade limita o blast radius. Rollback + `beginTransaction` numa falha, seguir.
- `RELEASE_LOCK` no `finally`. `exit(0)`.

**Verify**: `php -l site/cgi-bin/reconcile_charges.php` → `No syntax errors`;
com banco vivo, `php site/cgi-bin/reconcile_charges.php` → resumo, exit 0.

### Step 3: Copiar `OrderReconciler` para o manager

**Verify**:
`diff -q site/app/inc/lib/OrderReconciler.php manager/app/inc/lib/OrderReconciler.php`
→ sem saída. `bin/check-shared-sync.sh` → exit 0.

### Step 4: Agendar no crontab

Adicione em `docker/interface/crontab`, mesmo formato, sob `flock -n` com lock
próprio. Intervalo maior que 5min é aceitável (fallback, não caminho quente) —
use a cada 5min para simplicidade e consistência, ou 10min se preferir aliviar o
PSP:
```
# Reconciliar cobrancas pendentes contra o PSP (fallback de webhook perdido; MP/PagBank)
*/5 * * * * flock -n /tmp/infinnityimportacao_reconcile_charges.lock php /var/www/infinnityimportacao/site/cgi-bin/reconcile_charges.php >> /var/log/reconcile_charges.log 2>&1
```

**Verify**: `grep -n reconcile_charges docker/interface/crontab` mostra a linha.

### Step 5: PHPStan nos dois ambientes

**Verify**:
- `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`

## Test plan

Teste de integração para `OrderReconciler`, tocando banco → `DBTestCase`. O
desafio é que `fetchStatus()` faz HTTP real. Estruture `OrderReconciler` para
**injeção do resolvedor de status**: em vez de instanciar o gateway internamente
por `slug`, aceite uma callable/estratégia opcional (ex. construtor recebe um
`?callable $statusResolver = null` que, dado `slug` + `gatewayChargeId`, devolve o
status). Em produção, o default instancia o adapter real e chama `fetchStatus()`;
no teste, injete um stub que devolve `'pago'`/`'pendente'` sem rede. Este é o mesmo
espírito de `buildChargeBody()`/`lockAndValidateCart()` serem públicos "para serem
testáveis sem rede".

- Arquivo: `site/app/inc/lib/OrderReconcilerTest.php` (siga o local/namespace de
  `DBTestCase`; cópia em `manager/` se em `lib/`).
- Casos (asserts reais):
  1. **PSP confirma pago**: pedido `aguardando_pagamento` + charge `pendente`
     (slug `mercadopago`), stub devolve `'pago'` → `orders.status='pago'`,
     `pix_charges.status='pago'`, `paid_at` preenchido, resumo `confirmed=1`.
  2. **PSP ainda pendente**: stub devolve `'pendente'` → nada muda, `skipped`.
  3. **InfinitePay é ignorado**: charge com slug `infinitepay` não entra na seleção
     (mesmo que o stub devolvesse pago) — status permanece `aguardando_pagamento`.
  4. **Pedido já pago (corrida com webhook)**: pedido `pago`, charge `pendente`,
     stub `'pago'` → o UPDATE condicional afeta 0 linhas, não duplica e-mail nem
     re-grava; `skipped`.
  5. **Fora da janela de 24h**: pedido `created_at` antigo → não é selecionado.

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpunit --filter Reconcil`
→ os 5 casos passam.

## Done criteria

- [ ] `site/cgi-bin/reconcile_charges.php` existe, `php -l` passa.
- [ ] `OrderReconciler.php` em `site/` e `manager/`, byte-idênticos (`diff -q` sem
      saída).
- [ ] `bin/check-shared-sync.sh` exit 0.
- [ ] PHPStan `[OK] No errors` em `site/` e `manager/`.
- [ ] `cd site && php app/inc/lib/vendor/bin/phpunit --filter Reconcil` passa
      (5 casos), incluindo o de InfinitePay ignorado e o da corrida com webhook.
- [ ] `docker/interface/crontab` tem a linha do `reconcile_charges`.
- [ ] `grep -rn "infinitepay" site/app/inc/lib/OrderReconciler.php` mostra que
      InfinitePay é explicitamente excluído (na cláusula `IN (...)` ou comentário).
- [ ] `webhook_controller.php` **não** foi modificado (`git status`).
- [ ] Linha de status em `plans/README.md` atualizada.

## STOP conditions

Pare e reporte se:

- O código em "Current state" divergir (drift) — especialmente a escrita de "pago"
  do webhook (`:194-296`) ou as assinaturas de `fetchStatus()`.
- Você concluir que precisa editar `webhook_controller.php` ou um `*Gateway.php`
  para fazer a reconciliação funcionar — pare e reporte em vez de expandir escopo.
- `payment_gateways` não tiver a coluna `slug` esperada (confirme com
  `grep -rn slug migrations/*payment_gateways*`).
- A suíte não puder rodar contra banco no seu ambiente (registre; peça teste no
  stack vivo antes do merge).
- Uma verificação falhar duas vezes após correção razoável.

## Maintenance notes

- **Revisor deve escrutinar**: (1) o UPDATE condicional
  `WHERE status='aguardando_pagamento'` que impede dupla confirmação/dupla
  notificação em corrida com o webhook; (2) que InfinitePay está fora; (3) `$now`
  do PHP; (4) commit por unidade.
- **Duplicação consciente**: a escrita de "pago" está replicada entre
  `webhook_controller.php` e `OrderReconciler`. Follow-up recomendado: extrair um
  `OrderPaymentConfirmer::markPaid(int $ordersId, ...)` compartilhado e fazer os
  dois chamarem — fora deste plano para não tocar o webhook.
- **Interação com o plano 032 (expiração)**: os dois jobs podem competir por um
  mesmo pedido. Ambos usam UPDATE condicional em `status='aguardando_pagamento'`,
  então o primeiro a commitar vence e o outro vira no-op — sem corrupção. Mas
  decida a **precedência de negócio**: se um pedido venceu (>30min) e ao mesmo
  tempo o PSP diz "pago", o certo é confirmar (dinheiro entrou), não expirar.
  Como o plano 032 estorna estoque ao expirar, um "pago" que chegue DEPOIS da
  expiração precisaria re-decrementar estoque — ver o follow-up já anotado no
  plano 032 "Maintenance notes" (webhook/reconciliação não deveriam marcar `pago`
  um pedido `expirado` sem reavaliar estoque). Se este job rodar mais frequente/antes
  do de expiração, a janela desse conflito diminui. Documente a decisão do dono.
- **Janela de 24h e intervalo do cron** são parâmetros — ajustáveis conforme
  volume e custo de chamada ao PSP.
