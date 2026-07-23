# Plan 043: Desviar do gateway de risco em pico de pedidos (detecção de velocity)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat d3d3293..HEAD -- site/app/inc/lib/GatewayRouter.php manager/app/inc/lib/GatewayRouter.php site/app/inc/model/payment_gateways_model.php manager/app/inc/model/payment_gateways_model.php migrations/`
> **Este plano DEPENDE do 042 já executado** — o "Current state" abaixo
> descreve o GatewayRouter APÓS o plano 042 (filtro de `max_order_cents`).
> Se `pick()` ainda não aceitar `?int $orderCents`, execute o 042 primeiro.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (query nova no hot path do checkout; falso positivo desvia
  volume de gateway — mas nunca bloqueia venda)
- **Depends on**: plans/042-max-order-cents-gateway.md
- **Category**: security (antifraude / smurfing)
- **Planned at**: commit `d3d3293`, 2026-07-22

## Why this matters

Não existe hoje nenhuma detecção cross-order de padrão temporal: N CPFs
distintos pagando ao mesmo recebedor numa janela curta (smurfing) é o gatilho
mais provável de flag no Mercado Pago para lojas com venda "em pulso"
(divulgação em grupo → pico de pedidos em minutos) — exatamente o perfil desta
loja (venda por janela/período, ver `SalesWindow`). A mitigação NÃO é bloquear
venda: é detectar a janela quente (pedidos pagos nos últimos 60 min acima de
um threshold) e desviar os próximos sorteios do(s) gateway(s) marcado(s) como
sensível(is) a pico, com warning no log para o operador acompanhar.

## Current state

**Pré-requisito**: plano 042 executado. Após ele, `GatewayRouter::pick()` tem
a assinatura `pick(?int $orderCents = null)` e um bloco de filtro pré-draw
(`max_order_cents`) logo após carregar `$gateways`, com fallback "se o filtro
esvaziar, ignora e loga warning". Confirme lendo o arquivo antes de começar.

- `site/app/inc/lib/GatewayRouter.php` (+ cópia byte-idêntica em
  `manager/app/inc/lib/`) — no commit `d3d3293` a classe carrega os gateways
  habilitados, calcula MTD por gateway e sorteia ponderado por headroom;
  fallback nunca bloqueia venda (linhas 74–97, `Logger::warning`).
- `site/app/inc/model/payment_gateways_model.php` (+ cópia manager) — após o
  042: `$field` = idx, name, slug, mode, enabled, monthly_limit_cents,
  max_order_cents.
- Tabela `settings` (`skey`/`svalue`) — criada no plano 008; lida por
  `OrderPricing::intSetting()` (`site/app/inc/lib/OrderPricing.php`), que
  valida `ctype_digit`, loga erro em valor inválido e cai no default. **Use o
  mesmo padrão de leitura** (não é preciso reusar o método privado de
  OrderPricing — replique o padrão localmente no GatewayRouter).
- `orders` — pedidos pagos têm `status = 'pago'` e `paid_at` (DATETIME).
  **FATO CRÍTICO de ambiente**: há skew real de ~3h entre o relógio do PHP
  (`America/Sao_Paulo`) e o do MySQL do container (UTC/SYSTEM) — documentado
  múltiplas vezes no índice de planos. NUNCA compare `paid_at` com `NOW()` do
  MySQL; calcule a janela em PHP e binde como parâmetro `?`.
- Índice: `orders` tem `idx_orders_status_expires (status, expires_at)` mas
  NÃO tem índice cobrindo `(status, paid_at)` — lacuna já registrada como
  item em aberto no `plans/README.md` (achado do /ship do plano 011). A query
  de velocity deste plano filtra exatamente `status='pago' AND paid_at >= ?`
  no hot path do checkout — este plano adiciona o índice.
- `migrations/` — idempotentes, guard `information_schema`; exemplar de ADD
  COLUMN: `migrations/042_add_transaction_nsu_to_pix_charges.sql`. Use o
  próximo número livre (`ls migrations/ | sort | tail -1` + 1 — o plano 042
  já terá consumido um número).
- Testes: `site/tests/GatewayRouterTest.php` (DBTestCase, fixtures de
  gateways/pedidos, chama `pick()` direto) — exemplar principal.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan site/manager | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (idem manager) | `[OK] No errors` |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | nova executada; 2ª rodada skipped |
| PHPUnit site (Docker) | `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit` | sem regressão |
| Teste focado | `... phpunit --filter GatewayRouter` | passa |

NÃO use `bin/test.sh` (bug conhecido: sem `-w`, parece verde sem rodar).

## Scope

**In scope**:
- `migrations/NNN_add_velocity_routing.sql` (novo): coluna
  `payment_gateways.avoid_on_spike`, seed do MP, settings key, índice
  `(status, paid_at)` em `orders`
- `site/app/inc/lib/GatewayRouter.php` + cópia manager (byte-idênticos)
- `site/app/inc/model/payment_gateways_model.php` + cópia manager (campo novo)
- `site/tests/GatewayRouterTest.php` (casos novos)

**Out of scope**:
- `checkout_controller.php` — NADA muda no chamador (`pick($totalCents)` já
  passa o que precisa; a detecção é interna ao router).
- UI do manager para editar `avoid_on_spike`/threshold — deferido (ver
  Maintenance notes); configuração inicial vem da migration, ajuste via SQL.
- Qualquer bloqueio de venda, CAPTCHA ou fila — desvio de roteamento apenas.
- `SalesWindow.php`, webhook, adapters.

## Git workflow

- Branch: `advisor/043-velocity-smurfing`
- Commits em PT-BR, Conventional Commits.
- Não faça push nem abra PR sem instrução do operador.

## Steps

### Step 1: Migration

`migrations/NNN_add_velocity_routing.sql`, no padrão idempotente do repo
(guards `information_schema` para coluna e índice; `INSERT IGNORE` para
setting). Conteúdo lógico:

1. `ALTER TABLE payment_gateways ADD COLUMN avoid_on_spike ENUM('yes','no') NOT NULL DEFAULT 'no' AFTER max_order_cents`
2. `UPDATE payment_gateways SET avoid_on_spike = 'yes' WHERE slug = 'mercadopago'`
   (roda uma vez só — migrations são tracked em `migrations_log`)
3. `INSERT IGNORE INTO settings (skey, svalue) VALUES ('velocity_paid_orders_per_hour', '0')`
   — **0 = detecção DESLIGADA** (default seguro; o dono liga quando quiser).
   Confira antes as colunas reais de `settings` na migration que a criou
   (plano 008; inclui colunas de auditoria? copie o INSERT de seed que já
   existe lá).
4. `ALTER TABLE orders ADD KEY idx_orders_status_paid (status, paid_at)`
   (com guard de `information_schema.STATISTICS` — exemplar na migration 042,
   bloco do índice). Comentário: fecha o item em aberto do /ship do plano 011
   e serve a query de velocity deste plano.

**Verify**: `run_migrations.php` → executada; 2ª rodada → skipped.
**Verify** (via teste ou mysql): `SHOW COLUMNS FROM payment_gateways LIKE 'avoid_on_spike'` → existe; `SHOW INDEX FROM orders` → `idx_orders_status_paid` existe; `SELECT svalue FROM settings WHERE skey='velocity_paid_orders_per_hour'` → `0`.

### Step 2: Model (2 cópias)

`payment_gateways_model.php` (site + manager): adicionar `" avoid_on_spike "`
ao `$field`.

**Verify**: `bin/check-shared-sync.sh` → exit 0

### Step 3: GatewayRouter — filtro de velocity

Em `GatewayRouter.php` (site, replicar no manager). Adicione
`" avoid_on_spike "` ao `set_field()`. Logo APÓS o filtro de
`max_order_cents` do plano 042 (mesma região, antes do cálculo de MTD),
insira:

```php
// Deteccao de pico (plano 043): N pedidos pagos na ultima hora acima do
// threshold configurado => janela quente de smurfing; gateways marcados
// avoid_on_spike saem do sorteio ate a janela esfriar. Threshold 0 (default)
// = detecao desligada. Mesma filosofia dos demais filtros: se esvaziar o
// conjunto, ignora e loga — nunca trava a venda.
$spikeSensitive = array_filter($gateways, static fn (array $g): bool => ($g['avoid_on_spike'] ?? 'no') === 'yes');
if (!empty($spikeSensitive)) {
    $threshold = self::velocityThreshold();
    if ($threshold > 0 && self::paidOrdersLastHour() >= $threshold) {
        $calm = array_values(array_filter($gateways, static fn (array $g): bool => ($g['avoid_on_spike'] ?? 'no') !== 'yes'));
        if (!empty($calm)) {
            Logger::getInstance()->warning('GatewayRouter: pico de pedidos pagos na ultima hora — desviando de gateways avoid_on_spike', [
                'threshold' => $threshold,
            ]);
            $gateways = $calm;
        } else {
            Logger::getInstance()->warning('GatewayRouter: pico detectado mas todos os gateways sao avoid_on_spike — desvio ignorado', [
                'threshold' => $threshold,
            ]);
        }
    }
}
```

Dois métodos privados novos na classe:

- `private static function velocityThreshold(): int` — lê
  `settings.skey = 'velocity_paid_orders_per_hour'` seguindo o padrão de
  `OrderPricing::intSetting()` (valida `ctype_digit`; inválido/ausente → 0 +
  `Logger::error` só quando inválido, não quando ausente). Use os helpers de
  query do `DOLModel` (`select()`) como o restante da classe já usa após o
  refactor do branch 036 — copie o estilo da query MTD existente no próprio
  `pick()`.
- `private static function paidOrdersLastHour(): int` — `COUNT(*)` em
  `orders` com `active = 'yes' AND status = 'pago' AND paid_at >= ?`, onde o
  parâmetro é `date('Y-m-d H:i:s', strtotime('-60 minutes'))` **calculado em
  PHP** (nunca `NOW()` do MySQL — skew de ~3h documentado). Qualquer
  exceção → retorna 0 e loga (fail-open: detecção indisponível não pode
  derrubar o checkout).

Atualize o docblock da classe. Replique byte-idêntico no manager.

**Verify**: PHPStan site + manager → `[OK]`; `bin/check-shared-sync.sh` → exit 0

### Step 4: Testes

Em `site/tests/GatewayRouterTest.php` (padrão dos casos existentes; para o
threshold, insira/ajuste a row de `settings` na transação do teste —
`DBTestCase` faz rollback automático):

1. Threshold 0 (default) + N pedidos pagos recentes → MP continua elegível
   (detecção desligada).
2. Threshold 5, 5+ pedidos pagos com `paid_at` na última hora (grave
   `paid_at` via PHP `date()`, consistente com a leitura), MP
   `avoid_on_spike='yes'`, outro gateway `'no'` → `pick()` nunca retorna MP
   (rode várias vezes ou torne determinístico como os testes existentes fazem).
3. Threshold 5, pedidos pagos ANTIGOS (paid_at > 60 min atrás) → MP elegível
   (janela respeita o corte).
4. Todos os gateways `avoid_on_spike='yes'` + pico → `pick()` ainda retorna um
   gateway (nunca lança).
5. `settings` com svalue inválido (`'abc'`) → tratado como 0 (desligado), sem
   exceção.

**Verify**: `--filter GatewayRouter` → todos passam (novos + antigos + os do 042)

### Step 5: Regressão completa

**Verify**: PHPUnit site + manager completos → sem regressão vs baseline.

## Test plan

Step 4 — 5 casos novos em `GatewayRouterTest.php`. Exemplar estrutural: os
casos do próprio arquivo (fixtures + pick()); para settings na transação de
teste, `OrderPricingTest.php` já manipula `settings` — copie a técnica.

## Done criteria

- [ ] Migration aplicada e idempotente; coluna `avoid_on_spike`, setting
      `velocity_paid_orders_per_hour` = '0' e índice `idx_orders_status_paid`
      existem
- [ ] `grep -n "avoid_on_spike" site/app/inc/lib/GatewayRouter.php manager/app/inc/lib/GatewayRouter.php site/app/inc/model/payment_gateways_model.php manager/app/inc/model/payment_gateways_model.php` → match em todos
- [ ] `grep -n "NOW()" site/app/inc/lib/GatewayRouter.php` → sem match (janela calculada em PHP)
- [ ] `bin/check-shared-sync.sh` exit 0; PHPStan `[OK]` nos 2 ambientes
- [ ] PHPUnit site + manager sem regressão; 5 casos novos passam
- [ ] `checkout_controller.php` NÃO modificado (`git status`)
- [ ] Linha de status atualizada em `plans/README.md`

## STOP conditions

- `pick()` não tiver o filtro de `max_order_cents` do plano 042 (dependência
  não satisfeita).
- A migration que criou `settings` tiver colunas obrigatórias além de
  `skey`/`svalue` que o `INSERT IGNORE` do Step 1 não saiba preencher — leia a
  migration original antes; se ambíguo, STOP.
- O índice `idx_orders_status_paid` já existir com outro nome cobrindo
  `(status, paid_at)` (alguém fechou o item em aberto por fora) — pule só o
  passo do índice e reporte.
- A query de velocity precisar de mais de ~1 query extra por `pick()` para
  funcionar — não adicione caching/Redis por conta própria; reporte.

## Maintenance notes

- **Follow-up deferido (UI)**: expor `avoid_on_spike` e o threshold na tela
  `/config` do manager, ao lado dos campos de gateway do plano 042 — só
  quando o dono pedir; até lá, ajuste via SQL (`UPDATE settings SET svalue =
  '20' WHERE skey = 'velocity_paid_orders_per_hour'` liga com threshold 20).
- A detecção conta pedidos PAGOS (sinal forte, sem falso positivo de carrinho
  abandonado), o que significa que o desvio começa DEPOIS dos primeiros
  pagamentos do pico — aceito por design (o objetivo é reduzir exposição do
  MP no meio do pulso, não eliminá-la).
- Revisor: conferir fail-open (exceção na query → detecção desligada, venda
  segue), janela calculada em PHP (skew), e que o filtro compõe com o de
  `max_order_cents` sem duplicar fallback.
- Se um dia o roteamento ganhar mais critérios, considerar extrair os filtros
  pré-draw para um método próprio — hoje (2 filtros) ainda não vale a
  abstração.
