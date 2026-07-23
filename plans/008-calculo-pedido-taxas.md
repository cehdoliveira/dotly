# Plan 008: Cálculo obrigatório de taxas no fechamento do pedido (8% + R$60 + taxa Infinity)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4ad3e67..HEAD -- migrations site/app/inc/controller/checkout_controller.php site/app/inc/model/orders_model.php manager/app/inc/model/orders_model.php site/app/inc/lib manager/app/inc/lib`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1 (dinheiro — nenhum checkout pode omitir as taxas)
- **Effort**: M
- **Risk**: MED (toca o único caminho que grava pedido + valor cobrado no PIX)
- **Depends on**: none (mas coordena com 009 e 010 — todos editam `finalize()`; ver Maintenance notes)
- **Category**: bug / migration
- **Planned at**: commit `4ad3e67`, 2026-07-16

## Why this matters

A regra de negócio exige que **todo** pedido finalizado componha o total com,
sem exceção: (a) 8% sobre o subtotal, (b) taxa fixa de R$ 60,00 (custo de
câmbio/transferência BRL→USD) e (c) uma **taxa Infinity** parametrizável quando
o carrinho contiver produtos Infinity. Hoje o total do pedido é apenas a soma
das linhas — `checkout_controller::lockAndValidateCart()` devolve
`total_cents = Σ(unit_price × qty)` e esse valor vai direto para `orders.total_cents`
e para o valor cobrado no PIX, **sem nenhuma taxa**. Se as taxas forem espalhadas
por views ou por controller, algum caminho de checkout vai esquecê-las. Este plano
centraliza o cálculo num único ponto (`OrderPricing`, em `lib/` compartilhado),
torna a taxa Infinity parametrizável numa tabela de config (não hardcode) e
persiste o breakdown no pedido para auditoria e exibição.

## Fatos arquiteturais do framework (LEGGO) — leia antes de codar

- Não é Laravel/Symfony. Framework custom.
- **`site/finalize()` é o ÚNICO caminho que grava pedido.** Roda dentro da
  transação global aberta pelo `localPDO`; o `basic_redir()` final commita.
  Controllers não chamam `commit()`/`rollback()` na mão.
- **`app/inc/lib/` e `app/inc/model/` são cópias byte-idênticas** entre `manager/`
  e `site/`. Toda classe de lib nova (ex.: `OrderPricing`) e todo model novo têm
  que ser criados nas **duas** cópias, idênticas. `bin/check-shared-sync.sh`
  bloqueia commit se divergirem.
- **ORM = SQL cru**. Models estendem `DOLModel`. `set_filter([...cond],[...vals])`
  usa `?`. Valores monetários são inteiros em **centavos** (`*_cents`).
- **Soft-delete** `active='yes'/'no'`. Migrations em `migrations/` (raiz, cópia
  única), numeradas, idempotentes.
- O valor cobrado no PIX vem de `orders.total_cents` / `amount_cents` — se as taxas
  não entrarem aí, o cliente paga o subtotal sem taxa.

## Current state

- **`site/app/inc/controller/checkout_controller.php`** (o arquivo-chave):
  - `finalize()` (linha 49) chama `lockAndValidateCart($lines)` (linha 69), pega
    `$totalCents = $result['total_cents']` (linha 77), baixa estoque (79-86), gera
    token (88), e **grava o pedido com `'total_cents' => $totalCents`** (linha 106)
    — esse `$totalCents` é a soma crua das linhas, sem taxa.
  - Logo depois (linha 152) o gateway cobra: `createCharge($orderRow, ...)` e o
    `pix_charges.amount_cents` recebe `$totalCents` (linha 163). **Mesmo valor sem
    taxa vai para o PIX.**
  - `lockAndValidateCart()` (linhas 269-333) reconfere preço contra o banco e
    devolve `['ok'=>true, 'lines'=>$finalLines, 'total_cents'=>$totalCents]` onde
    `total_cents` é `Σ line_total_cents`. **Não aplique taxa aqui** — este método é
    "subtotal reconferido". A taxa entra num passo separado.
- **`orders` table** (`migrations/012_create_table_orders.sql`): tem
  `total_cents INT UNSIGNED`. **Não tem** colunas de breakdown (subtotal, taxas).
- **`orders_model`** (`site/` e `manager/`, idênticos):
  ```php
  class orders_model extends DOLModel {
      protected array $field = [ ... " total_cents ", ... ];
      function __construct() { parent::__construct("orders"); }
  }
  ```
  (Confirme o `$field` atual antes de editar — você vai acrescentar as colunas novas.)
- **Não existe tabela de config/settings.** Confirmado:
  `grep -rn "settings\|app_config" migrations/` → vazio.
- **Não existe marcação de "produto Infinity".** `products` (migration 009) não tem
  flag `is_infinity` nem coluna equivalente. Ver Step 2 + STOP condition.
- **Exemplar de classe de lib**: veja `site/app/inc/lib/GatewayRouter.php` (métodos
  estáticos, sem estado) e `site/app/inc/lib/Cart.php`. Siga o mesmo estilo.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Guard shared-sync | `bin/check-shared-sync.sh` | exit 0, sem DRIFT |
| PHPUnit site | `docker exec -w /var/www/infinnityimportacao/site -e HTTP_HOST=localhost infinnityimportacao php app/inc/lib/vendor/bin/phpunit -c phpunit.xml` | all pass |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | aplicadas, idempotentes |
| Próximo nº migration | `ls migrations/ \| sort \| tail -1` | maior nº atual |

## Scope

**In scope**:
- `migrations/NNN_create_table_settings.sql` (criar — próximo nº livre)
- `migrations/NNN_add_fee_breakdown_to_orders.sql` (criar — próximo nº livre depois)
- `site/app/inc/lib/OrderPricing.php` + `manager/app/inc/lib/OrderPricing.php` (criar — **byte-idênticos**)
- `site/app/inc/model/settings_model.php` + `manager/app/inc/model/settings_model.php` (criar — **byte-idênticos**)
- `site/app/inc/model/orders_model.php` + `manager/app/inc/model/orders_model.php` (adicionar colunas ao `$field`, **idênticos**)
- `site/app/inc/controller/checkout_controller.php` (aplicar taxas em `finalize()`)
- `site/public_html/ui/page/checkout.php` e `site/public_html/ui/page/done.php` (exibir o breakdown — opcional mas recomendado)
- `site/tests/OrderPricingTest.php` (criar)

**Out of scope**:
- `manager/` além das cópias de lib/model. (Exibir o breakdown no detalhe do
  pedido do manager é follow-up — ver Maintenance notes.)
- `webhook_controller.php`, gateways, `GatewayRouter`. O valor cobrado já flui de
  `$totalCents`; você só precisa que esse número já venha com taxa.
- NÃO altere `lockAndValidateCart()` para embutir taxa — ele é "subtotal reconferido".

## Git workflow

- Branch: `advisor/008-taxas-pedido`
- Commits PT-BR Conventional Commits (`feat:`/`fix:`).
- Sem push/PR sem ordem do operador.

## Steps

### Step 1: Migration — tabela `settings` (config key/value) + seeds

Crie `migrations/NNN_create_table_settings.sql` (próximo nº). Key/value simples,
com soft-delete, idempotente:

```sql
CREATE TABLE IF NOT EXISTS `settings` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') DEFAULT 'yes',
    `skey` VARCHAR(60) NOT NULL UNIQUE,
    `svalue` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`idx`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`created_at`, `created_by`, `active`, `skey`, `svalue`) VALUES
    (NOW(), 0, 'yes', 'fee_percent_bps',  '800'),    -- 8% em basis points
    (NOW(), 0, 'yes', 'fee_fixed_cents',  '6000'),   -- R$ 60,00
    (NOW(), 0, 'yes', 'fee_infinity_bps', '0');       -- taxa Infinity — PARAMETRIZAVEL, ajustar no manager/DB
```

> As três taxas ficam parametrizáveis. 8% e R$60 são padrão da spec; a taxa
> Infinity nasce em `0` e é ajustada pelo dono (parametrizável, não hardcode).
> Basis points (bps): 800 = 8,00%.

**Verify**: rode migrations. `SELECT skey, svalue FROM settings` → 3 linhas. 2º run idempotente.

### Step 2: Marcar produtos Infinity — DECISÃO DE MODELAGEM (confirmar antes)

A taxa Infinity só incide "quando produtos Infinity forem comercializados". **O
schema atual NÃO tem como identificar um produto Infinity.** Escolha o mecanismo:

- **[assumção — opção A, recomendada]** Adicionar `products.is_infinity ENUM('yes','no')
  NOT NULL DEFAULT 'no'`. Migration com guard `information_schema` (padrão de
  `migrations/015`). Marcação por produto no manager (checkbox no form — follow-up
  do Plano 007/produtos).

Se a opção A for adotada, inclua no `migrations/NNN_add_fee_breakdown_to_orders.sql`
(Step 3) ou numa migration própria o `ADD COLUMN products.is_infinity`. E adicione
`" is_infinity "` ao `$field` de `products_model` (nas 2 cópias).

> **STOP se ambíguo**: se o dono do repo tiver outra definição de "produto
> Infinity" (ex.: uma categoria específica, um fornecedor), NÃO invente — reporte
> e peça a regra. Sem uma forma de identificar, a taxa Infinity não pode ser
> aplicada corretamente.

**Verify**: `SELECT is_infinity, COUNT(*) FROM products GROUP BY is_infinity` → coluna existe.

### Step 3: Migration — colunas de breakdown em `orders`

Crie `migrations/NNN_add_fee_breakdown_to_orders.sql` com guard de
`information_schema` por coluna (padrão de `migrations/015`). Adicione:

```
subtotal_cents      INT UNSIGNED NOT NULL DEFAULT 0   -- soma das linhas (sem taxa)
fee_percent_cents   INT UNSIGNED NOT NULL DEFAULT 0   -- 8% do subtotal
fee_fixed_cents     INT UNSIGNED NOT NULL DEFAULT 0   -- R$60 fixo
fee_infinity_cents  INT UNSIGNED NOT NULL DEFAULT 0   -- taxa Infinity aplicada
```
(`total_cents` já existe e passa a ser `subtotal + as 3 taxas`.)

**Verify**: rode migrations. `DESCRIBE orders` mostra as 4 colunas novas. Idempotente no 2º run.

### Step 4: `settings_model` (2 cópias) + helper de leitura

Crie `site/app/inc/model/settings_model.php`:
```php
<?php
class settings_model extends DOLModel
{
    protected array $field = [" idx ", " skey ", " svalue "];
    protected array $filter = [" active = 'yes' "];
    function __construct() { parent::__construct("settings"); }
}
```
Copie byte-idêntico para `manager/app/inc/model/settings_model.php`.

**Verify**: `bin/check-shared-sync.sh` → exit 0.

### Step 5: Classe `OrderPricing` (2 cópias) — centraliza o cálculo

Crie `site/app/inc/lib/OrderPricing.php`. Uma única função pura que recebe as
linhas já reconferidas + o subtotal e devolve o breakdown. Ela lê os parâmetros da
tabela `settings`. Detecta Infinity a partir das linhas (precisa saber quais
`products_id` são Infinity — carregue via query pelos ids das linhas). Estilo:
métodos estáticos, sem estado (como `GatewayRouter`).

Forma-alvo (o cálculo é load-bearing — respeite a ordem e o arredondamento):
```php
<?php
class OrderPricing
{
    /**
     * @param array<int, array{products_id:int, line_total_cents:int, ...}> $lines
     * @param int $subtotalCents  soma reconferida das linhas
     * @return array{subtotal_cents:int, fee_percent_cents:int, fee_fixed_cents:int,
     *   fee_infinity_cents:int, total_cents:int}
     */
    public static function compute(array $lines, int $subtotalCents): array
    {
        $percentBps  = (int) self::setting('fee_percent_bps', '800');
        $fixedCents  = (int) self::setting('fee_fixed_cents', '6000');
        $infinityBps = (int) self::setting('fee_infinity_bps', '0');

        // intdiv para arredondamento consistente (centavos inteiros, trunca).
        $feePercent = intdiv($subtotalCents * $percentBps, 10000);

        $feeInfinity = 0;
        if ($infinityBps > 0 && self::cartHasInfinity($lines)) {
            $feeInfinity = intdiv($subtotalCents * $infinityBps, 10000);
        }

        $total = $subtotalCents + $feePercent + $fixedCents + $feeInfinity;

        return [
            'subtotal_cents'     => $subtotalCents,
            'fee_percent_cents'  => $feePercent,
            'fee_fixed_cents'    => $fixedCents,
            'fee_infinity_cents' => $feeInfinity,
            'total_cents'        => $total,
        ];
    }

    private static function setting(string $key, string $default): string { /* SELECT svalue FROM settings WHERE active='yes' AND skey=? via settings_model->execute_raw_prepared */ }

    /** @param array<int, array<string,mixed>> $lines */
    private static function cartHasInfinity(array $lines): bool
    {
        // SELECT idx FROM products WHERE active='yes' AND is_infinity='yes' AND idx IN (?...)
        // sobre os products_id distintos das linhas; true se retornar >=1.
    }
}
```

> Decisão de arredondamento: `intdiv` (trunca centavos). Documente no PHPDoc. Se o
> dono preferir arredondar pra cima, é 1 linha — mas não invente, siga `intdiv` e
> anote no Maintenance.

Copie byte-idêntico para `manager/app/inc/lib/OrderPricing.php`.

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`. `bin/check-shared-sync.sh` → exit 0.

### Step 6: Aplicar o breakdown em `finalize()`

Em `site/app/inc/controller/checkout_controller.php::finalize()`, **depois** de
`$result = $this->lockAndValidateCart($lines);` e de checar `$result['ok']`
(atual linha ~69-77), insira:
```php
$subtotalCents = $result['total_cents'];
$pricing = OrderPricing::compute($finalLines, $subtotalCents);
$totalCents = $pricing['total_cents'];
```
(Note: `$finalLines = $result['lines']` já existe na linha 76; use-o.)

No `populate([...])` do pedido (linha ~92-108), passe também as colunas novas:
```php
'subtotal_cents'     => $pricing['subtotal_cents'],
'fee_percent_cents'  => $pricing['fee_percent_cents'],
'fee_fixed_cents'    => $pricing['fee_fixed_cents'],
'fee_infinity_cents' => $pricing['fee_infinity_cents'],
'total_cents'        => $pricing['total_cents'],
```
Assim `$totalCents` (com taxa) é o que vai para `orders.total_cents` (linha 106),
para `$orderRow['total_cents']` (linha 139) e para `pix_charges.amount_cents`
(linha 163) — o PIX cobra o valor com taxa, automaticamente.

**Adicione as colunas ao `$field` de `orders_model`** (nas 2 cópias) para o
`populate()` conseguir gravá-las.

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`. `bin/check-shared-sync.sh` → exit 0.

### Step 7 (recomendado): Exibir o breakdown

Em `site/public_html/ui/page/checkout.php` e `done.php`, mostre subtotal + as 3
taxas + total. Os valores em centavos são formatados na view (padrão do repo:
`total_cents` já é exibido dividido por 100). Escape com `htmlspecialchars` onde
aplicável. NÃO recalcule taxa na view — leia do pedido/breakdown já calculado.

**Verify**: finalizar um pedido de teste → a tela mostra "Subtotal / Taxa 8% / Câmbio R$60 / (Infinity) / Total" e o Total bate com `orders.total_cents`.

## Test plan

- `site/tests/OrderPricingTest.php` (estende `DBTestCase` porque `OrderPricing`
  lê `settings` e `products`). Molde em `site/tests/CheckoutStockTest.php` (existe).
  Casos:
  - subtotal R$100,00 (10000c), sem Infinity, params padrão → percent=800c,
    fixed=6000c, infinity=0, total=16800c.
  - com um produto Infinity no carrinho e `fee_infinity_bps=500` → infinity=500c
    sobre 10000c, entra no total.
  - `fee_infinity_bps=0` → infinity sempre 0 mesmo com produto Infinity.
  - subtotal que gere fração de centavo (ex.: 3333c a 8% = 266,64c) → `intdiv` = 266c.
- Verificação: PHPUnit site verde, incl. os novos testes.

## Done criteria

- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `diff -q site/app/inc/lib/OrderPricing.php manager/app/inc/lib/OrderPricing.php` → sem saída
- [ ] Migrations idempotentes; `settings` tem as 3 chaves; `orders` tem as 4 colunas novas
- [ ] Um pedido de teste grava `total_cents = subtotal + 8% + 6000 + infinity` (confirme por SELECT)
- [ ] `pix_charges.amount_cents` do pedido == `orders.total_cents` (PIX cobra com taxa)
- [ ] PHPUnit site verde incl. `OrderPricingTest`
- [ ] Nenhum arquivo fora do In-scope modificado
- [ ] `plans/README.md` atualizado

## STOP conditions

- Não houver forma acordada de identificar "produto Infinity" (Step 2) — reporte,
  não invente flag sem confirmação.
- Os trechos de `finalize()` não baterem com "Current state" (drift) — outro plano
  (009/010) pode já ter editado `finalize()`; releia o método inteiro e reconcilie
  antes de aplicar.
- Algum caminho fizer `pix_charges.amount_cents` divergir de `orders.total_cents`.
- Qualquer teste financeiro falhar duas vezes após ajuste razoável.

## Maintenance notes

- **`finalize()` é editado também pelos Planos 009 (cliente) e 010 (estoque).**
  Se um deles já tiver mergeado, releia o método inteiro; suas edições são
  aditivas e não conflitam em lógica, mas os números de linha do "Current state"
  vão ter mudado.
- **Câmbio USD**: a taxa fixa de R$60 é rotulada "custo de câmbio/transferência
  BRL→USD" mas é aplicada como valor fixo em BRL (centavos). Se no futuro o preço
  base virar USD, `OrderPricing` é o único ponto a revisitar.
- Exibir o breakdown no **detalhe do pedido do manager**
  (`manager/public_html/ui/page/order_detail.php`) é follow-up recomendado — os
  dados já estarão persistidos.
- Revisor deve conferir que NENHUM outro caminho grava pedido além de `finalize()`
  (`grep -rn "new orders_model" site/` — só o checkout deve instanciar para gravar).
