# Plan 032: Job de expiração de pedido + estorno de estoque

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0c3158b..HEAD -- site/app/inc/controller/checkout_controller.php site/cgi-bin docker/interface/crontab migrations`
> If any in-scope path changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none (coordena com 034 — ambos criam job em `cgi-bin`)
- **Category**: bug
- **Planned at**: commit `0c3158b`, 2026-07-20

## Why this matters

Quando um comprador não paga o PIX, o pedido fica `aguardando_pagamento` **para
sempre**: não existe transição para `expirado` em lugar nenhum do código, e o
estoque decrementado no checkout (`checkout_controller.php:117`) **nunca é
devolvido**. Todo carrinho abandonado — o caso comum em e-commerce — segura
estoque indefinidamente ("estoque fantasma"). Somado à ausência de rate limit no
checkout (plano 033), vira arma: dá para esvaziar o estoque de um produto de graça
e de forma permanente. Este job varre os pedidos vencidos, marca-os `expirado` e
devolve o estoque, de forma idempotente e segura contra corrida com o webhook.

## Current state

- **Estados do pedido** — `migrations/012_create_table_orders.sql:11`:
  ```sql
  status ENUM('aguardando_pagamento','pago','cancelado','expirado') NOT NULL DEFAULT 'aguardando_pagamento'
  ```
  O valor `expirado` existe no enum mas **nunca é gravado** por nenhum controller.
  O manager declara explicitamente que não transiciona status "quem transiciona o
  status de pagamento e o webhook e o job de reconciliacao"
  (`manager/app/inc/controller/orders_controller.php:5-9`) — esse job nunca foi
  construído.

- **Decremento de estoque** (o que este job estorna) —
  `site/app/inc/controller/checkout_controller.php:113-120`:
  ```php
  // Baixa o estoque por linha.
  $productsModel = new products_model();
  foreach ($finalLines as $line) {
      $productsModel->execute_raw_prepared(
          "UPDATE products SET stock = stock - ? WHERE idx = ?",
          [$line['units_needed'], $line['products_id']]
      );
  }
  ```
  `units_needed` = para variante `box`, `qty * box_qty`; para `unit`, `qty`
  (ver `checkout_controller.php:416`). **Não há ledger de estoque** — o antigo
  `stock_movements` foi removido (migration `031_drop_stock_ledger.sql`, referida
  em `039`). Estoque é só a coluna `products.stock`.

- **`order_items`** guarda o suficiente para recomputar `units_needed`
  (`migrations/013_create_table_order_items.sql`):
  `orders_id`, `products_id`, `variant ENUM('unit','box')`, `qty INT UNSIGNED`.
  E `products.box_qty SMALLINT UNSIGNED DEFAULT 10`
  (`migrations/009_create_table_products.sql:18`), `products.stock INT`
  (`:19`).

- **`expires_at`** já é gravado no pedido (`checkout_controller.php:123`,
  `+30 minutes`) e existe o índice `idx_orders_status_expires (status, expires_at)`
  (ver `plans/README.md`, achado sobre índice) — a query de expiração o usa.

- **`pix_charges`** tem `status ENUM('pendente','pago','expirado','erro')`
  (`migrations/014_create_table_pix_charges.sql:13`) e `orders_id`. O job também
  marca a cobrança pendente como `expirado`.

- **Padrão de job cron existente** — `site/cgi-bin/dispatch_emails.php` é o
  molde exato a seguir (bootstrap de kernel para CLI, `GET_LOCK` advisório,
  commit por unidade, fail-open). Trechos-chave (`dispatch_emails.php:19-46`):
  ```php
  date_default_timezone_set('America/Sao_Paulo');
  $_SERVER["DOCUMENT_ROOT"] = dirname(__FILE__) . "/../public_html/";
  $_SERVER["HTTP_HOST"]     = getenv("CLI_HTTP_HOST") ?: "infinnityimportacao.local";
  require_once __DIR__ . '/../app/inc/kernel.php';
  require_once __DIR__ . '/../app/inc/lib/vendor/autoload.php';
  $pdo = localPDO::getInstance();
  $rawPdo = $pdo->getPdo();
  $got = $rawPdo->query("SELECT GET_LOCK('...', 0) AS l")->fetch(\PDO::FETCH_ASSOC);
  if ((int)($got['l'] ?? 0) !== 1) { exit(0); }
  ```
  E o commit por unidade (`:73-74`): `$pdo->commit(); $pdo->beginTransaction();`.
  `localPDO` abre uma transação no construtor; o job commita explicitamente.

- **Crontab atual** — `docker/interface/crontab:27-34` roda só
  `run_migrations.php` e `dispatch_emails.php`, ambos a cada 5 min sob `flock -n`.

## Convenções do repositório (obrigatórias)

- **Transação global única por processo.** `localPDO::getInstance()` é singleton;
  todo model compartilha a mesma conexão/transação. Um erro de SQL derruba a
  transação inteira. O job gerencia commit/rollback explicitamente (como o
  webhook e o `dispatch_emails.php`), não os controllers.
- **Universal soft-delete** (`active='yes'/'no'`). Nunca `DELETE FROM`. Expiração é
  transição de `status`, não remoção.
- **Migrations** em `migrations/`, numeradas (`NNN_desc.sql`), idempotentes
  (guard `information_schema`/`IF NOT EXISTS`/`INSERT IGNORE`), uma transação por
  arquivo. Rodadas por `run_migrations.php` (auto a cada 5 min). **Este plano
  provavelmente não precisa de migration nova** — o enum `expirado` e a coluna
  `expires_at` já existem. Só crie migration se adicionar índice (ver Step 4).
- **Lógica testável extraída para `app/inc/lib` (compartilhado, 2 cópias
  byte-idênticas).** O script `cgi-bin` em si não é invocável pela suíte PHPUnit;
  por isso o `dispatch_emails.php` extraiu `EmailQueueDispatcher::processRow()` para
  `lib/`. Siga o mesmo padrão: a lógica de expiração vai numa classe em
  `app/inc/lib/` (copiada para `site/` **e** `manager/`), e o `cgi-bin` é casca
  fina. `bin/check-shared-sync.sh` bloqueia divergência entre as cópias.
- Jobs `cgi-bin/` são **só do site** (não são compartilhados). O agendamento
  (`docker/interface/crontab`) é infra do site.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHP lint | `php -l site/cgi-bin/expire_orders.php` | `No syntax errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Shared-sync guard | `bin/check-shared-sync.sh` | exit 0 |
| Diff das 2 cópias | `diff -q site/app/inc/lib/OrderExpirer.php manager/app/inc/lib/OrderExpirer.php` | (sem saída) |
| Testes (filtro) | `cd site && php app/inc/lib/vendor/bin/phpunit --filter OrderExpir` | all pass |
| Rodar o job à mão | `php site/cgi-bin/expire_orders.php` | imprime resumo, exit 0 |

PHPUnit precisa de `kernel.php` + banco vivo. Os testes de expiração tocam banco →
estendem `DBTestCase` (transação + auto-rollback por teste).

## Scope

**In scope** (crie/edite só estes):
- `site/cgi-bin/expire_orders.php` (novo — casca fina)
- `site/app/inc/lib/OrderExpirer.php` (novo — lógica testável)
- `manager/app/inc/lib/OrderExpirer.php` (novo — cópia byte-idêntica)
- `docker/interface/crontab` (adiciona 1 linha)
- Um teste novo (`site/app/inc/lib/OrderExpirerTest.php` + cópia manager se em `lib/`)
- **Opcional** (só se a Step 4 confirmar necessidade): 1 migration nova de índice.

**Out of scope** (NÃO toque):
- `checkout_controller.php` — o decremento de estoque atual fica como está; este
  job só o reverte para pedidos vencidos.
- Reconciliação de pagamento no PSP (plano 034 — job separado).
- Rate limit (plano 033).
- Qualquer coisa do webhook / gateways.

## Git workflow

- Branch: `advisor/032-job-expiracao-estoque`
- Commits PT-BR, Conventional Commits (`feat:` para o job novo). Exemplo do
  histórico: `fix: remover etapa "Entrega" do rastreio de pedido`.
- Não faça push nem PR a menos que o operador peça.

## Steps

### Step 1: Criar `OrderExpirer` com a lógica testável

Crie `site/app/inc/lib/OrderExpirer.php` com uma classe `OrderExpirer` que expõe:

- `expireDueOrders(?string $now = null): array` — seleciona pedidos vencidos e
  expira cada um numa unidade transacional própria. Retorna um resumo
  (`['expired' => int, 'restocked_units' => int, 'skipped' => int]`) para o
  cron imprimir. NÃO chama `basic_redir()`/`exit()` (precisa ser testável).

Lógica exata:

1. `$now = $now ?? date('Y-m-d H:i:s')` — o "agora" é calculado pelo PHP
   (`America/Sao_Paulo`), **nunca** `NOW()` do MySQL, para evitar o skew de fuso já
   documentado no repo (ver `plans/README.md`, achado de timezone em
   `auth_controller`). Passe `$now` como parâmetro `?` bindado.

2. Selecionar candidatos:
   ```sql
   SELECT idx FROM orders
    WHERE active = 'yes'
      AND status = 'aguardando_pagamento'
      AND expires_at < ?
    ORDER BY idx ASC
    LIMIT 200
   ```
   (lote pequeno como o `dispatch_emails`; o próximo tick pega o resto.)

3. Para **cada** pedido candidato, numa unidade transacional própria (ver Step 2
   sobre commit por unidade):

   a. **Transição atômica com guarda de corrida** — um `UPDATE` condicional que só
      afeta a linha se ela AINDA estiver aguardando pagamento (o webhook pode ter
      marcado `pago` entre o SELECT e agora):
      ```sql
      UPDATE orders SET status = 'expirado', modified_at = ?
       WHERE idx = ? AND status = 'aguardando_pagamento'
      ```
      Se `rowCount() !== 1`, **não faça nada** (o pedido já foi resolvido por
      outro caminho) — incremente `skipped` e siga. Isto é o que garante que o
      estoque NUNCA é estornado de um pedido que virou `pago`.

   b. Só se o UPDATE afetou 1 linha, **estornar o estoque** de cada
      `order_items` do pedido, recomputando `units_needed`:
      ```sql
      -- unidades a devolver por item: box => qty * box_qty ; unit => qty
      UPDATE products p
        JOIN order_items oi ON oi.products_id = p.idx
        SET p.stock = p.stock + IF(oi.variant = 'box', oi.qty * p.box_qty, oi.qty)
      WHERE oi.orders_id = ? AND oi.active = 'yes'
      ```
      (Uma query só, JOIN, evita N round-trips. Some as unidades devolvidas para o
      resumo com um SELECT prévio se quiser o total — opcional.)

   c. Marcar a cobrança PIX pendente do pedido como expirada:
      ```sql
      UPDATE pix_charges SET status = 'expirado', modified_at = ?
       WHERE orders_id = ? AND status = 'pendente' AND active = 'yes'
      ```

   Use `execute_raw_prepared()` de um model (ex. `new orders_model()`) ou o
   `localPDO::executePrepared()` — siga como o `checkout_controller` e o
   `dispatch_emails` acessam o banco. Não introduza acesso a DB fora do padrão.

Estenda o padrão de "método público testável" já usado no repo
(`lockAndValidateCart`, `EmailQueueDispatcher::processRow`).

**Verify**: `php -l site/app/inc/lib/OrderExpirer.php` → `No syntax errors`.

### Step 2: Criar o script cron `expire_orders.php` (casca fina)

Crie `site/cgi-bin/expire_orders.php` copiando o esqueleto de
`site/cgi-bin/dispatch_emails.php`:

- Mesmo bootstrap (`date_default_timezone_set`, `$_SERVER` fake, require kernel +
  autoload).
- `GET_LOCK('infinnityimportacao_expire_orders', 0)` advisório (nome PRÓPRIO,
  diferente do dispatcher); se não obtiver, `exit(0)`.
- Chamar `(new OrderExpirer())->expireDueOrders()` dentro de try/catch fail-open
  (ex.: tabela ainda não existe num deploy novo → loga e sai sem erro fatal).
- **Commit por pedido, não um único no fim** (mesma justificativa do
  `dispatch_emails.php:65-74`): dentro de `expireDueOrders`, após processar cada
  pedido com sucesso, `$pdo->commit(); $pdo->beginTransaction();`. Se um pedido
  falhar, `$pdo->rollback(); $pdo->beginTransaction();` e siga para o próximo —
  o blast radius fica em 1 pedido. Decida se o commit fica dentro de `OrderExpirer`
  (recebendo o `localPDO`) ou no script; siga o que o `dispatch_emails` faz
  (commit no loop do script, chamando um método que processa 1 unidade). Se for
  mais limpo, dê a `OrderExpirer` um método `expireOne(int $ordersId, string $now): bool`
  e deixe o loop + commit no método `expireDueOrders` ou no script.
- `RELEASE_LOCK` no `finally`.
- `exit(0)` ao final.

**Verify**: `php -l site/cgi-bin/expire_orders.php` → `No syntax errors`. E, com
banco vivo, `php site/cgi-bin/expire_orders.php` → imprime resumo, exit 0.

### Step 3: Copiar `OrderExpirer` para o manager e reconciliar

`OrderExpirer.php` está em `app/inc/lib/` → precisa das 2 cópias byte-idênticas.
Copie para `manager/app/inc/lib/OrderExpirer.php`.

**Verify**:
`diff -q site/app/inc/lib/OrderExpirer.php manager/app/inc/lib/OrderExpirer.php`
→ sem saída. `bin/check-shared-sync.sh` → exit 0.

### Step 4: Confirmar índice (só adicione migration se faltar)

A query da Step 1 filtra por `status='aguardando_pagamento' AND expires_at < ?`.
Confirme que `idx_orders_status_expires (status, expires_at)` existe:
`grep -rn "idx_orders_status_expires" migrations/`. Se existir (esperado), **não
crie migration** — o índice já cobre. Se por algum motivo não existir, crie a
próxima migration livre (`ls migrations/ | sort | tail -1` + 1) adicionando
`KEY idx_orders_status_expires (status, expires_at)` com guard idempotente de
`information_schema` (modele por `migrations/026_add_stock_min_to_products.sql`).

**Verify**: `grep -rn "status.*expires_at\|idx_orders_status_expires" migrations/`
mostra o índice coberto.

### Step 5: Agendar no crontab

Em `docker/interface/crontab`, adicione uma linha no mesmo formato das existentes
(`:31,34`), a cada 5 minutos, sob `flock -n` com lock PRÓPRIO:

```
# Expirar pedidos vencidos e devolver o estoque reservado (lote pequeno)
*/5 * * * * flock -n /tmp/infinnityimportacao_expire_orders.lock php /var/www/infinnityimportacao/site/cgi-bin/expire_orders.php >> /var/log/expire_orders.log 2>&1
```

**Verify**: `grep -n expire_orders docker/interface/crontab` mostra a linha nova;
as linhas de migrations/emails continuam intactas
(`grep -c flock docker/interface/crontab` = 3).

### Step 6: PHPStan nos dois ambientes

**Verify**:
- `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`

## Test plan

Teste de integração para `OrderExpirer`, tocando banco → estende `DBTestCase`.

- Arquivo: `site/app/inc/lib/OrderExpirerTest.php` (localize o dir real dos testes
  com `find site -name '*Test.php'` e siga o local/namespace de `DBTestCase`; ex.
  `StockMovementOnSaleTest`/`CheckoutPaymentChargeTest` são modelos estruturais).
  Se ficar em `app/inc/lib/`, copie para `manager/` também (shared-sync).
- Casos (asserts reais):
  1. **Pedido vencido é expirado e estoque devolvido**: cria produto com
     `stock=S` e `box_qty=B`; cria pedido `aguardando_pagamento` com
     `expires_at` no passado + 1 `order_item` `variant='unit', qty=Q`; roda
     `expireDueOrders($now)` com `$now` no futuro do `expires_at`. Assert:
     `orders.status='expirado'`, `products.stock == S + Q`, `pix_charges.status='expirado'`.
  2. **Variante box devolve `qty*box_qty`**: item `variant='box', qty=Q` →
     `stock == S + Q*B`.
  3. **Pedido NÃO vencido é ignorado**: `expires_at` no futuro → status permanece
     `aguardando_pagamento`, estoque inalterado.
  4. **Pedido já `pago` nunca é estornado (guarda de corrida)**: pedido com
     `expires_at` no passado mas `status='pago'` → não é tocado, estoque inalterado.
     (Prova que o `UPDATE ... WHERE status='aguardando_pagamento'` protege.)
  5. **Idempotência**: rodar `expireDueOrders` duas vezes seguidas não devolve
     estoque em dobro (a 2ª rodada não encontra mais o pedido em
     `aguardando_pagamento`).

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpunit --filter OrderExpir`
→ os 5 casos passam.

## Done criteria

- [ ] `site/cgi-bin/expire_orders.php` existe e `php -l` passa.
- [ ] `OrderExpirer.php` existe em `site/` e `manager/`, byte-idênticos
      (`diff -q` sem saída).
- [ ] `bin/check-shared-sync.sh` exit 0.
- [ ] PHPStan `[OK] No errors` em `site/` e `manager/`.
- [ ] `cd site && php app/inc/lib/vendor/bin/phpunit --filter OrderExpir` passa
      (5 casos), incluindo o caso da guarda de corrida e o de idempotência.
- [ ] `docker/interface/crontab` tem a linha do `expire_orders` (3 linhas `flock`
      no total).
- [ ] `grep -rn "'expirado'" site/app/inc/lib/OrderExpirer.php` confirma que o job
      escreve o status `expirado`.
- [ ] `git status` sem arquivo fora do escopo.
- [ ] Linha de status em `plans/README.md` atualizada.

## STOP conditions

Pare e reporte se:

- O código em "Current state" não bater (drift) — especialmente se o decremento de
  estoque em `checkout_controller.php:113-120` tiver mudado de forma (units_needed,
  colunas), pois o estorno espelha essa lógica.
- Você descobrir que existe SIM um ledger de estoque ativo (ex. `stock_movements`
  reintroduzido) — nesse caso o estorno precisa também escrever no ledger, e a
  forma muda; reporte antes de assumir.
- A suíte não puder rodar contra banco algum no seu ambiente (registre e peça ao
  operador para rodar o Test plan no stack Docker vivo antes do merge).
- Uma verificação falhar duas vezes após correção razoável.

## Maintenance notes

- **Revisor deve escrutinar**: (1) que o estorno só ocorre quando o `UPDATE`
  condicional afetou 1 linha (sem isso, corrida com o webhook devolve estoque de
  pedido pago = overselling); (2) que `$now` vem do PHP, não de `NOW()` do MySQL
  (skew de fuso conhecido no repo); (3) commit por pedido, não único no fim.
- **Edge de pagamento tardio**: se um pagamento InfinitePay legítimo chegar via
  webhook DEPOIS do pedido já ter expirado (>30min) e o estoque já ter voltado, o
  webhook hoje marcaria `pago` mesmo assim (ele não checa `expirado`), sem
  re-decrementar estoque. Isso é raro (a cobrança PIX também expira no gateway),
  mas é um ponto a endurecer quando o plano 034 (reconciliação) for feito:
  webhook/reconciliação não deveriam marcar `pago` um pedido já `expirado` sem
  reavaliar estoque. Deixado como follow-up consciente, fora deste plano.
- **Janela de 30min** é literal em `checkout_controller.php:123`
  (`strtotime('+30 minutes')`) — se mudar, o comportamento do job acompanha
  automaticamente (ele lê `expires_at`, não recalcula a janela).
