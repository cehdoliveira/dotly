# Plan 042: Teto de valor por gateway no roteamento (max_order_cents)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat d3d3293..HEAD -- site/app/inc/lib/GatewayRouter.php manager/app/inc/lib/GatewayRouter.php site/app/inc/model/payment_gateways_model.php manager/app/inc/model/payment_gateways_model.php site/app/inc/controller/checkout_controller.php manager/app/inc/controller/config_controller.php manager/public_html/ui/page/config.php migrations/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (aditivo; NULL = comportamento atual)
- **Depends on**: none (mas o plano 043 depende DESTE — o filtro pré-draw criado aqui é reusado lá)
- **Category**: security (antifraude / proteção de conta no PSP)
- **Planned at**: commit `d3d3293`, 2026-07-22

## Why this matters

O `GatewayRouter` sorteia o gateway ponderado pelo headroom mensal, mas ignora
o VALOR do pedido. Cenário de risco: ticket alto caindo no Mercado Pago numa
conta com histórico fraco — o MP é agressivo em congelamento para tickets
altos de contas novas. Este plano adiciona um teto opcional por gateway
(`payment_gateways.max_order_cents`, NULL = sem teto), **configurável pelo
dono na tela `/config` do manager** — nenhum valor hardcoded: o dono decide SE
usa a funcionalidade e QUAL o teto de cada gateway. Gateways cujo teto é menor
que o total do pedido saem do sorteio. Mantém a filosofia documentada do
router: **nunca bloqueia a venda** — se o filtro esvaziar o conjunto, sorteia
entre todos e loga warning.

**Invariante de configuração (decisão do dono, 2026-07-22, não reabrir)**:
entre os gateways HABILITADOS, **pelo menos 1 deve ficar SEM teto**
(`max_order_cents` NULL = pedido de valor ilimitado, respeitando só o
`monthly_limit_cents`). Com 3 gateways, no máximo 2 podem ter teto; com N
integrações, no máximo N-1. O manager REJEITA qualquer save que deixaria
todos os gateways habilitados com teto. Isso garante que o filtro pré-draw
nunca esvazia o conjunto na prática — o fallback do router vira
defense-in-depth (cobre edição direta via SQL, que não passa pela validação).

## Current state

- `site/app/inc/lib/GatewayRouter.php` — classe inteira (99 linhas). Trechos
  relevantes:

```php
// GatewayRouter.php:19-25
public static function pick(): array
{
    $model = new payment_gateways_model();
    $model->set_field([" idx ", " slug ", " mode ", " monthly_limit_cents "]);
    $model->set_filter([" active = 'yes' ", " enabled = 'yes' "]);
    $model->load_data(false);
    $gateways = $model->data;
```

  Depois: soma MTD por gateway (28–45), sorteio ponderado por headroom
  (47–72), fallback "todos estouraram" que escolhe menor ratio e loga
  `Logger::warning` (74–97). Docblock da classe (linhas 3–13) promete:
  "`monthly_limit_cents` é meta de equilíbrio, nunca trava de venda".
- `manager/app/inc/lib/GatewayRouter.php` — **cópia byte-idêntica** (regra do
  repo; `bin/check-shared-sync.sh` bloqueia divergência).
- Único chamador de `pick()` em produção:

```php
// site/app/inc/controller/checkout_controller.php:202
$picked = GatewayRouter::pick();
```

  Nesse ponto `$totalCents` (total com taxas, o valor real da cobrança) já
  existe (linha 122: `$totalCents = $pricing['total_cents'];`).
- `site/app/inc/model/payment_gateways_model.php` (e cópia manager):

```php
protected array $field = [" idx ", " name ", " slug ", " mode ", " enabled ", " monthly_limit_cents "];
```

- `manager/app/inc/controller/config_controller.php::saveGateway()` (linhas
  258–287) — persiste `enabled` + `monthly_limit_cents` do form; o valor vem
  formatado em reais e é normalizado com
  `(int)preg_replace('/\D/', '', ...)` (linha 266).
- `manager/public_html/ui/page/config.php` (linhas ~235–245) — tabela de
  gateways com coluna de limite mensal e input `monthly_limit_cents`
  (formatado `number_format(... / 100, 2, ',', '.')`).
- `migrations/` — numeração sequencial, idempotentes via guard
  `information_schema`, uma transação por arquivo. Exemplar do guard de ADD
  COLUMN: `migrations/042_add_transaction_nsu_to_pix_charges.sql`. Maior
  número em `d3d3293`: `044`. **Use o próximo número livre no momento da
  execução** (`ls migrations/ | sort | tail -1`).
- Testes: `site/tests/GatewayRouterTest.php` — exemplar direto (estende
  `DBTestCase`, cria gateways fixture, chama `pick()`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan site/manager | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (idem manager) | `[OK] No errors` |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | migration nova executada; 2ª rodada = skipped |
| PHPUnit site (Docker) | `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit` | sem regressão vs baseline |
| Teste focado | `... phpunit --filter GatewayRouter` | passa |

NÃO use `bin/test.sh` (bug conhecido: sem `-w` no docker exec, parece verde
sem rodar nada).

## Scope

**In scope**:
- `migrations/NNN_add_max_order_cents_to_payment_gateways.sql` (novo; NNN = próximo livre)
- `site/app/inc/lib/GatewayRouter.php` + `manager/app/inc/lib/GatewayRouter.php` (byte-idênticos)
- `site/app/inc/model/payment_gateways_model.php` + cópia manager (campo novo)
- `site/app/inc/controller/checkout_controller.php` (passar `$totalCents` ao `pick()` — 1 linha)
- `manager/app/inc/controller/config_controller.php` (`saveGateway()` persiste o campo + valida invariante; novo método `violatesUnlimitedInvariant()`)
- `manager/public_html/ui/page/config.php` (coluna + input do teto + texto de ajuda)
- `site/tests/GatewayRouterTest.php` (casos novos)
- `manager/tests/GatewayLimitInvariantTest.php` (novo — invariante do manager)

**Out of scope**:
- `webhook_controller.php`, adapters de gateway, `OrderReconciler`/`OrderExpirer`.
- Qualquer lógica de velocity/smurfing — é o plano 043.
- Mudar a semântica de `monthly_limit_cents` ou o fallback existente.
- Manager `config_controller` linhas 81–115 (dashboard de consumo MTD) — pode
  continuar sem mostrar o teto; UI nova é só o input da tabela.

## Git workflow

- Branch: `advisor/042-max-order-cents`
- Commits em PT-BR, Conventional Commits (`feat:` para a feature, `test:` se separar).
- Não faça push nem abra PR sem instrução do operador.

## Steps

### Step 1: Migration

Crie `migrations/NNN_add_max_order_cents_to_payment_gateways.sql` (NNN = maior
número atual + 1) copiando o padrão de guard idempotente de
`migrations/042_add_transaction_nsu_to_pix_charges.sql` (SELECT em
`information_schema.COLUMNS` + `PREPARE`/`EXECUTE` condicional). Coluna:

```sql
ALTER TABLE `payment_gateways`
    ADD COLUMN `max_order_cents` BIGINT UNSIGNED DEFAULT NULL AFTER `monthly_limit_cents`
```

Comentário no topo do arquivo: NULL = sem teto (comportamento atual);
valor em centavos = gateway sai do sorteio para pedidos acima disso.
NÃO fazer UPDATE de seed — os 3 gateways ficam NULL (sem teto) por padrão.

**Verify**: rodar `run_migrations.php` → migration executada; rodar de novo →
skipped (idempotente).
**Verify**: `docker exec infinnityimportacao mysql ... -e "SHOW COLUMNS FROM payment_gateways LIKE 'max_order_cents'"` (adapte credenciais do ambiente; alternativa: um teste com `SHOW COLUMNS` como `StockMovementOnSaleTest` já faz) → coluna existe, `NULL: YES`.

### Step 2: Model (2 cópias)

Em `payment_gateways_model.php` (site E manager, byte-idênticos), adicione
`" max_order_cents "` ao `$field` após `" monthly_limit_cents "`.

**Verify**: `bin/check-shared-sync.sh` → exit 0

### Step 3: GatewayRouter — filtro pré-draw

Em `GatewayRouter.php` (site, depois replicar byte-idêntico no manager):

1. Assinatura: `public static function pick(?int $orderCents = null): array` —
   `null` preserva o comportamento atual (testes existentes não quebram).
2. Adicione `" max_order_cents "` ao `set_field()` da linha 22.
3. Logo após `$gateways = $model->data;` e o guard de vazio (linha 27–29),
   insira o filtro:

```php
// Teto por valor de pedido (plano 042): gateway com max_order_cents definido
// nao entra no sorteio para pedidos acima do teto. NULL = sem teto. Se o
// filtro esvaziar o conjunto, ignora o teto e loga — a mesma filosofia do
// monthly_limit_cents: meta de roteamento, nunca trava de venda.
if ($orderCents !== null) {
    $eligible = array_values(array_filter($gateways, static function (array $g) use ($orderCents): bool {
        $max = $g['max_order_cents'];
        return $max === null || $max === '' || $orderCents <= (int)$max;
    }));

    if (!empty($eligible)) {
        $gateways = $eligible;
    } else {
        Logger::getInstance()->warning('GatewayRouter: todos os gateways com teto abaixo do pedido — teto ignorado', [
            'order_cents' => $orderCents,
        ]);
    }
}
```

O restante do método (MTD, headroom, draw, fallback) opera sobre `$gateways`
já filtrado — não precisa mudar. Atualize o docblock da classe (linhas 3–13)
mencionando o teto opcional. Replique o arquivo inteiro no manager.

**Verify**: PHPStan site + manager → `[OK]`; `bin/check-shared-sync.sh` → exit 0

### Step 4: Chamador no checkout

`site/app/inc/controller/checkout_controller.php:202`:

```php
$picked = GatewayRouter::pick($totalCents);
```

**Verify**: PHPStan site → `[OK]`

### Step 5: Manager — persistência + validação do invariante + UI

Em `config_controller.php::saveGateway()` (258–287), adicione após a linha 266:

```php
// Teto por pedido: input vazio = NULL (sem teto / valor ilimitado). Vem
// formatado em reais (mesma normalizacao do monthly_limit_cents).
$maxOrderRaw   = trim((string)($post['max_order_cents'] ?? ''));
$maxOrderCents = $maxOrderRaw === '' ? null : (int)preg_replace('/\D/', '', $maxOrderRaw);

// Invariante (decisao do dono, 2026-07-22): entre os gateways HABILITADOS,
// pelo menos 1 precisa ficar sem teto — o roteamento nunca pode ficar sem
// rota para pedido de valor alto. Valida o estado RESULTANTE deste save
// (o proprio gateway editado entra na conta com os valores novos).
if ($this->violatesUnlimitedInvariant($idx, $enabled, $maxOrderCents)) {
    $_SESSION["messages_app"]["danger"] = ["Pelo menos um gateway habilitado precisa ficar sem limite por pedido (campo vazio)."];
    basic_redir($config_url);
}
```

e inclua `'max_order_cents' => $maxOrderCents,` no `populate()` (273–276).

Novo método privado no mesmo controller (extraído para ser testável via
`ReflectionMethod` — `saveGateway()` termina em `basic_redir()` → `exit()` e
não é testável; mesmo padrão de extração já usado no repo, ex.
`lockAndValidateCart()`):

```php
/**
 * True se, apos aplicar (enabled, maxOrderCents) ao gateway $idx, NENHUM
 * gateway habilitado ficaria sem teto (max_order_cents NULL). Sem nenhum
 * gateway habilitado no estado resultante, nao ha o que violar (false) —
 * desabilitar todos ja era possivel antes e continua sendo.
 */
private function violatesUnlimitedInvariant(int $idx, string $enabled, ?int $maxOrderCents): bool
{
    $model = new payment_gateways_model();
    $model->set_field([" idx ", " enabled ", " max_order_cents "]);
    $model->set_filter([" active = 'yes' "]);
    $model->load_data(false);

    $hasEnabled = false;
    foreach ($model->data as $g) {
        $gEnabled = (string)$g['enabled'];
        $gMax     = $g['max_order_cents'];
        if ((int)$g['idx'] === $idx) {           // aplica o estado pendente
            $gEnabled = $enabled;
            $gMax     = $maxOrderCents;
        }
        if ($gEnabled !== 'yes') {
            continue;
        }
        $hasEnabled = true;
        if ($gMax === null || $gMax === '') {    // achou 1 habilitado sem teto
            return false;
        }
    }

    return $hasEnabled;                          // habilitados existem e todos com teto
}
```

Atenção aos dois caminhos de violação que a simulação acima cobre — o teste
do Step 6b exercita ambos:
1. Dar teto ao ÚLTIMO gateway habilitado sem teto.
2. DESABILITAR o único gateway habilitado sem teto enquanto outros
   habilitados têm teto (a violação vem do `enabled`, não do teto em si).

Em `manager/public_html/ui/page/config.php`, na tabela de gateways
(~linhas 235–245), adicione ao lado do limite mensal: célula de exibição
(`Ilimitado` quando NULL, senão `R$ n.nnn,nn` com o mesmo `number_format`) e
input `name="max_order_cents"` no form de edição (mesmo estilo do input de
`monthly_limit_cents`; `value` vazio quando NULL; `placeholder="Ilimitado"`).
Texto de ajuda curto junto ao form/card: "Vazio = sem limite por pedido. Pelo
menos um gateway habilitado deve ficar sem limite." O SELECT que alimenta a
tabela (`config_controller.php:82`, `set_field`) precisa ganhar
`" max_order_cents "`.

**Verify**: PHPStan manager → `[OK]`
**Verify**: `php -l manager/public_html/ui/page/config.php` → sem erros

### Step 6: Testes

Em `site/tests/GatewayRouterTest.php`, siga o padrão dos casos existentes
(fixtures de gateway + `pick()`), adicionando:

- Gateway A com `max_order_cents = 50000`, gateway B com NULL, pedido de
  60000 → `pick(60000)` retorna sempre B (rode o draw várias vezes ou zere o
  headroom de B de forma determinística conforme os testes existentes fazem).
- Mesmo cenário, pedido de 40000 → ambos elegíveis (asserte que A pode ser
  sorteado, na técnica que os testes existentes usam para lidar com
  aleatoriedade — se eles fixam headroom pra tornar determinístico, copie).
- TODOS com teto abaixo do pedido → `pick()` ainda retorna um gateway
  (nunca lança) — o teto é ignorado.
- `pick()` sem argumento → comportamento antigo intacto (regressão).

**Verify**: `--filter GatewayRouter` → todos passam (novos + antigos)

### Step 6b: Teste do invariante no manager

Crie `manager/tests/GatewayLimitInvariantTest.php` (estende `DBTestCase`;
chama `config_controller::violatesUnlimitedInvariant()` via `ReflectionMethod`
— mesmo padrão de `CheckoutCustomerBlockTest` no site). Fixtures: os 3
gateways seed já existem na base; manipule `enabled`/`max_order_cents` deles
dentro da transação do teste (rollback automático). Casos:

1. 2 habilitados, ambos sem teto; dar teto a 1 → false (sobra 1 ilimitado).
2. 2 habilitados, um JÁ com teto; dar teto ao outro → **true** (violaria).
3. 3 habilitados, 2 com teto; DESABILITAR o único sem teto → **true**
   (violação via `enabled`).
4. Único habilitado, sem teto; dar teto a ele → **true**.
5. Desabilitar todos (o último recebe `enabled='no'`) → false (sem
   habilitados, nada a violar — comportamento pré-existente preservado).
6. Editar gateway com teto para VOLTAR a ilimitado (`null`) → false sempre.

**Verify**: `docker exec -w /var/www/infinnityimportacao/manager infinnityimportacao php app/inc/lib/vendor/bin/phpunit --filter GatewayLimitInvariant` → 6 casos passam

### Step 7: Regressão completa

**Verify**: PHPUnit site + manager completos → sem regressão vs baseline.
**Verify**: `bin/check-shared-sync.sh` → exit 0.

## Test plan

Step 6. Exemplar: `site/tests/GatewayRouterTest.php` (DBTestCase, fixtures de
`payment_gateways`, invocação direta de `pick()`).

## Done criteria

- [ ] Migration nova aplicada e idempotente (2ª rodada = skipped)
- [ ] `grep -n "max_order_cents" site/app/inc/lib/GatewayRouter.php manager/app/inc/lib/GatewayRouter.php site/app/inc/model/payment_gateways_model.php manager/app/inc/model/payment_gateways_model.php manager/app/inc/controller/config_controller.php manager/public_html/ui/page/config.php` → match em todos
- [ ] `grep -n "GatewayRouter::pick(\$totalCents)" site/app/inc/controller/checkout_controller.php` → 1 match
- [ ] `grep -n "violatesUnlimitedInvariant" manager/app/inc/controller/config_controller.php` → ≥2 matches (definição + chamada em `saveGateway()`)
- [ ] `bin/check-shared-sync.sh` exit 0; PHPStan `[OK]` nos 2 ambientes
- [ ] PHPUnit site + manager sem regressão; casos novos do GatewayRouterTest E do GatewayLimitInvariantTest passam
- [ ] `git status` → nenhum arquivo fora do In scope
- [ ] Linha de status atualizada em `plans/README.md`

## STOP conditions

- `GatewayRouter::pick()` tiver ganho parâmetros ou filtros novos desde
  `d3d3293` (drift — o plano 043 pode ter rodado antes; reconcilie via
  índice antes de continuar).
- O número de migration calculado colidir com arquivo existente.
- `payment_gateways` no banco de dev não tiver as colunas esperadas
  (schema divergente das migrations — reporte, não "conserte" o schema na mão).
- Os testes existentes de `GatewayRouterTest` dependerem da assinatura antiga
  de um jeito que o default `null` não resolva.

## Maintenance notes

- **Plano 043 (smurfing) reusa este filtro** — ele adiciona um segundo
  critério de exclusão pré-draw no mesmo ponto. Quem revisar o 043 deve
  conferir que os dois filtros compõem (teto E velocity) sem duplicar o
  fallback de conjunto vazio.
- Revisor: conferir NULL-safety (`max_order_cents` chega como string ou null
  do PDO — o filtro trata ambos), que pedido == teto (igualdade) ainda é
  elegível (`<=`), e que a validação do invariante cobre os DOIS caminhos
  (teto no último ilimitado E desabilitar o único ilimitado).
- **O invariante só é garantido pela tela do manager** — edição direta via
  SQL pode deixar todos os habilitados com teto; nesse caso o fallback do
  router (ignora teto + warning) segura a venda. É defense-in-depth
  intencional, não redundância a remover.
- Config de produção: valores de teto são 100% decisão operacional do dono
  via `/config` — nenhum seed, nenhum default hardcoded.
