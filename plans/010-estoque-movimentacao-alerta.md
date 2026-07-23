# Plan 010: Seção de Estoque no manager (entrada, ledger `stock_movements`, alerta de nível) + baixa por venda, relações via junção

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4ad3e67..HEAD -- migrations site/app/inc/controller/checkout_controller.php site/app/inc/model/products_model.php manager/app/inc/model/products_model.php manager/public_html/index.php site/app/inc/lib/DOLModel.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M/L
- **Risk**: MED
- **Depends on**: none (coordena com 008 e 009 — todos editam `finalize()`)
- **Category**: direction / migration
- **Planned at**: commit `4ad3e67`, 2026-07-16

## Why this matters

Hoje o estoque é um único inteiro `products.stock`, editado à mão no form de
produto, sem histórico. Não há registro de **entradas** (quando/quanto chegou),
nem alerta de "acabando", e a baixa por venda acontece mas não deixa rastro
auditável. Este plano cria: (1) tela de **entrada de estoque por produto** no
manager, (2) um **ledger** `stock_movements` que registra toda entrada e saída,
ligado a produto (e a pedido, na saída) **pelo mecanismo de junção do framework**,
(3) um **limiar parametrizável por produto** (`stock_min`) com destaque de "estoque
baixo", e (4) a baixa por venda — que **já existe** em `finalize()` — passa a
também gravar um movimento no ledger. `products.stock` continua sendo o saldo
corrente (fonte da trava de venda); o ledger é o histórico.

## Fatos arquiteturais (LEGGO)

- Framework custom. `finalize()` (site) é o **único** caminho que grava pedido e já
  **baixa o estoque** (linhas 79-86: `UPDATE products SET stock = stock - ?`),
  na transação global; `basic_redir()` commita. Sem `commit()`/`rollback()` manual.
- **RELACIONAMENTO = SEMPRE TABELA DE JUNÇÃO** (regra do dono). `stock_movements`
  **não** guarda `products_id`/`orders_id` inline; as relações vão em junções
  `products_stock_movements` e `orders_stock_movements` (`{a}_id`, `{b}_id` +
  auditoria + `active` + `UNIQUE`). Exemplar: `migrations/004_create_table_users_profiles.sql`.
- `app/inc/lib/` e `app/inc/model/` **byte-idênticos** manager/site — model novo nas
  duas cópias. `bin/check-shared-sync.sh` bloqueia drift.
- CRUD via POST + `action` (dispatcher só GET/POST). CSRF em todo POST. Soft-delete
  `active`. Migrations em `migrations/` (raiz), idempotentes.
- `products.stock_min` é **atributo** (booleano/limiar), não relação → coluna normal.
- **DEV**: base pode ser dropada; backfill best-effort.

### API de junção do `DOLModel` (verificada)

- Junção = `sprintf("%s_%s", $this->table, $class)`; colunas `{this->table}_id`,
  `{class}_id`. `attach(['{class}'])` (`DOLModel.php:268`) carrega
  `row["{class}_attach"]`. `save_attach` (`:428`) substitui o set (não usar para
  append). **Para append** (evento que só cresce, como movimento), a própria
  `save_attach` insere na junção com `execute_raw_prepared(INSERT ...)` — replique
  esse padrão de INSERT direto (ver Step 6/8).

## Current state

- **`products.stock INT NOT NULL DEFAULT 0`** (`migrations/009`). Editável no form
  de produto (`products.php`, inputs `name="stock"` linhas 222 e 283). Sem `stock_min`.
- **Baixa por venda já existe** em `checkout_controller::finalize()`:
  ```php
  // linhas 79-86
  $productsModel = new products_model();
  foreach ($finalLines as $line) {
      $productsModel->execute_raw_prepared(
          "UPDATE products SET stock = stock - ? WHERE idx = ?",
          [$line['units_needed'], $line['products_id']]
      );
  }
  ```
  `$line['units_needed']` = qty em unidades (box já convertido, `lockAndValidateCart:303-304`).
  `$orderId` só existe depois do `$order->save()` (linha 109).
- **Não existem** `stock_movements`, `products_stock_movements`, `orders_stock_movements`.
- **CRUD exemplar**: `products_controller` + `products.php`. Rotas em
  `manager/public_html/index.php`; URLs em `manager/app/inc/urls.php`; nav em
  `manager/public_html/ui/page/dashboard.php`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Guard shared-sync | `bin/check-shared-sync.sh` | exit 0 |
| PHPUnit site | `docker exec -w /var/www/infinnityimportacao/site -e HTTP_HOST=localhost infinnityimportacao php app/inc/lib/vendor/bin/phpunit -c phpunit.xml` | all pass |
| PHPUnit manager | `docker exec -w /var/www/infinnityimportacao/manager -e HTTP_HOST=localhost infinnityimportacao php app/inc/lib/vendor/bin/phpunit -c phpunit.xml` | all pass |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | idempotentes |

## Scope

**In scope**:
- `migrations/NNN_create_table_stock_movements.sql` (criar)
- `migrations/NNN_create_table_products_stock_movements.sql` (junção — criar)
- `migrations/NNN_create_table_orders_stock_movements.sql` (junção — criar)
- `migrations/NNN_add_stock_min_to_products.sql` (criar)
- `site/app/inc/model/stock_movements_model.php` + `manager/...` (criar — **byte-idênticos**)
- `site/app/inc/model/products_model.php` + `manager/...` (adicionar `stock_min` ao `$field`, **idênticos**)
- `manager/app/inc/controller/stock_controller.php` (criar)
- `manager/app/inc/controller/products_controller.php` (aceitar `stock_min` no `validate()`)
- `manager/app/inc/urls.php` (`$stock_url`)
- `manager/public_html/index.php` (rotas GET/POST `/estoque`)
- `manager/public_html/ui/page/stock.php` (criar)
- `manager/public_html/ui/page/products.php` (input `stock_min` + destaque de baixo)
- `manager/public_html/ui/page/dashboard.php` (link de nav "Estoque")
- `site/app/inc/controller/checkout_controller.php` (gravar movimento de saída + links)
- `manager/tests/StockEntryTest.php` + `site/tests/StockMovementOnSaleTest.php` (criar)

**Out of scope**:
- `lockAndValidateCart()` — a trava/checagem de saldo já está correta.
- Colunas inline `products_id`/`orders_id` em `stock_movements` — NÃO crie (relação é junção).
- Job de reconciliação/expiração PIX (`site/cgi-bin/`) — fora do escopo autorizado.
- Devolução de estoque em pedido expirado/cancelado — follow-up.
- `DOLModel.php`.

## Git workflow

- Branch: `advisor/010-estoque`
- Commits PT-BR Conventional Commits. Sem push/PR sem ordem.

## Steps

### Step 1: Migration — `stock_movements` (ledger, SEM fk inline)

Crie `migrations/NNN_create_table_stock_movements.sql`:
```sql
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') DEFAULT 'yes',
    `kind` ENUM('entrada','saida') NOT NULL,
    `qty` INT NOT NULL,               -- sempre positivo; `kind` diz a direcao
    `note` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`idx`),
    KEY `idx_stock_mov_kind` (`kind`, `active`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```

**Verify**: rode migrations → tabela criada. Idempotente.

### Step 2: Migrations — junções `products_stock_movements` e `orders_stock_movements`

Crie duas migrations, modeladas em `users_profiles`. Ex. a de produto:
```sql
CREATE TABLE IF NOT EXISTS `products_stock_movements` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') NOT NULL DEFAULT 'yes',
    `products_id` INT NOT NULL,
    `stock_movements_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_products_id` (`products_id`),
    KEY `idx_stock_movements_id` (`stock_movements_id`),
    UNIQUE KEY `uq_products_stock_movements` (`products_id`, `stock_movements_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```
E `orders_stock_movements` idêntica em forma, com `orders_id` + `stock_movements_id`
(só usada em movimentos de saída por venda).

> Nomes com o "pai" primeiro (`products_...`, `orders_...`) para
> `products_model.attach(['stock_movements'])` / `orders_model.attach(['stock_movements'])`
> funcionarem sem `reverse_table`.

**Verify**: rode migrations → 2 junções criadas. Idempotentes.

### Step 3: Migration — `products.stock_min`

Crie `migrations/NNN_add_stock_min_to_products.sql` com guard `information_schema`
(padrão `migrations/015`): `ADD COLUMN stock_min INT NOT NULL DEFAULT 0 AFTER stock`.
`stock_min=0` = sem alerta; `stock<=stock_min` (e `>0`) = "acabando".

**Verify**: `DESCRIBE products` mostra `stock_min`. Idempotente.

### Step 4: Models (`stock_movements_model` 2 cópias; `stock_min` no products_model)

Crie `site/app/inc/model/stock_movements_model.php`:
```php
<?php
class stock_movements_model extends DOLModel
{
    protected array $field = [" idx ", " kind ", " qty ", " note ", " created_at "];
    protected array $filter = [" active = 'yes' "];
    function __construct() { parent::__construct("stock_movements"); }
}
```
Copie byte-idêntico para `manager/`. Acrescente `" stock_min "` ao `$field` de
`products_model` nas 2 cópias.

> Não precisa de model para as junções — os INSERTs de link vão por
> `execute_raw_prepared` (mesmo padrão interno do `save_attach`), e as leituras por
> `attach(['stock_movements'])`.

**Verify**: `bin/check-shared-sync.sh` → exit 0.

### Step 5: URL + rotas + nav de Estoque

- `manager/app/inc/urls.php`: `$stock_url = sprintf("%s%s", constant("cFrontend"), "estoque");`
- `manager/public_html/index.php`:
  ```php
  $dispatcher->add_route("GET",  "/estoque", "stock_controller:index",  $authGuard, $params);
  $dispatcher->add_route("POST", "/estoque", "stock_controller:action", $authGuard, $params);
  ```
- `manager/public_html/ui/page/dashboard.php`: link "Estoque" (ícone `bi bi-boxes`).

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`.

### Step 6: `stock_controller` — entrada + histórico

Crie `manager/app/inc/controller/stock_controller.php` (molde `products_controller`).
`index()`: lista produtos com `stock`/`stock_min` + badge "baixo" quando
`stock_min>0 && stock<=stock_min`; e as últimas N movimentações (carregadas por
`stock_movements_model` + `attach`/join com produto). `action()` com `action='entrada'`:
```php
validate_csrf($post['_csrf_token'] ?? null, $stock_url);
$productId = (int)($post['products_id'] ?? 0);
$qty       = (int)($post['qty'] ?? 0);
if ($productId <= 0 || $qty <= 0) {
    $_SESSION["messages_app"]["danger"] = ["Informe produto e quantidade (> 0)."];
    basic_redir($stock_url);
}
$userId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);

// 1) saldo corrente
$p = new products_model();
$p->execute_raw_prepared("UPDATE products SET stock = stock + ? WHERE idx = ? AND active='yes'", [$qty, $productId]);

// 2) movimento
$mov = new stock_movements_model();
$mov->populate(['kind' => 'entrada', 'qty' => $qty, 'note' => trim((string)($post['note'] ?? ''))]);
$movId = (int)$mov->save();

// 3) link produto<->movimento na juncao (append; mesmo padrao do save_attach)
$mov->execute_raw_prepared(
    "INSERT INTO products_stock_movements (products_id, stock_movements_id, created_by, created_at, active)
     VALUES (?, ?, ?, now(), 'yes')",
    [$productId, $movId, $userId]
);

$_SESSION["messages_app"]["success"] = ["Entrada registrada."];
basic_redir($stock_url);
```
Tudo na transação do request; `basic_redir()` commita saldo + movimento + link juntos.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`.

### Step 7: View `stock.php` + `stock_min` no form de produto

- Crie `manager/public_html/ui/page/stock.php`: form de entrada (select de produto,
  qty, nota) + tabela de saldos com destaque de baixo + tabela de movimentações
  recentes (com o nome do produto vindo do `attach`/join). Escape tudo.
- Em `products.php`: input `<input type="number" name="stock_min" min="0" value="0">`
  nos forms criar (perto da 222) e editar (perto da 283, `x-model="editData.stockMin"`);
  ajuste o JS de `editData`. Destaque linhas com `stock<=stock_min && stock_min>0`.

**Verify**: `/estoque` → entrada aumenta saldo e aparece no histórico; produto abaixo do limiar destacado.

### Step 8: `products_controller::validate()` — aceitar `stock_min`

Leia `stock_min` como inteiro `>= 0` (padrão de `stock`, linhas 198-203) e inclua
`'stock_min' => $stockMin` no array retornado.

**Verify**: criar/editar produto com `stock_min` → persistido.

### Step 9: Movimento de SAÍDA junto da baixa por venda

Em `checkout_controller::finalize()`, **depois** de criar o pedido (`$orderId`,
linha 109) e os itens, itere `$finalLines` gravando um movimento de saída + os dois
links por linha:
```php
$saleUserId = 0; // venda publica, sem admin logado
foreach ($finalLines as $line) {
    $mov = new stock_movements_model();
    $mov->populate(['kind' => 'saida', 'qty' => $line['units_needed'], 'note' => 'venda']);
    $movId = (int)$mov->save();

    // liga o movimento ao produto e ao pedido (juncoes; append via INSERT direto)
    $mov->execute_raw_prepared(
        "INSERT INTO products_stock_movements (products_id, stock_movements_id, created_by, created_at, active) VALUES (?, ?, ?, now(), 'yes')",
        [$line['products_id'], $movId, $saleUserId]
    );
    $mov->execute_raw_prepared(
        "INSERT INTO orders_stock_movements (orders_id, stock_movements_id, created_by, created_at, active) VALUES (?, ?, ?, now(), 'yes')",
        [$orderId, $movId, $saleUserId]
    );
}
```
Continua tudo na transação do request — se o checkout der rollback, movimentos +
links somem junto com a baixa.

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`.

## Test plan

- `manager/tests/StockEntryTest.php` (`DBTestCase`): entrada de N → `products.stock`
  sobe N; há 1 `stock_movements` `kind='entrada'` e 1 link em `products_stock_movements`;
  qty<=0 rejeitada.
- `site/tests/StockMovementOnSaleTest.php` (`DBTestCase`, molde `CheckoutStockTest.php`):
  finalizar pedido → há `stock_movements` `kind='saida'` com links para o produto
  (`products_stock_movements`) e para o pedido (`orders_stock_movements`);
  `qty == units_needed`; soma das saídas == redução do saldo.
- Verificação: PHPUnit manager e site verdes.

## Done criteria

- [ ] PHPStan site e manager → `[OK] No errors`
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `diff -q site/app/inc/model/stock_movements_model.php manager/app/inc/model/stock_movements_model.php` → sem saída
- [ ] Migrations idempotentes; `stock_movements`, as 2 junções e `products.stock_min` existem
- [ ] `grep -n "products_id\|orders_id" migrations/NNN_create_table_stock_movements.sql` → **zero** (sem fk inline no ledger)
- [ ] Entrada no manager incrementa `products.stock` e cria movimento + link
- [ ] Venda cria movimento `saida` ligado ao produto E ao pedido (teste + SELECT nas junções)
- [ ] Produto com `stock<=stock_min>0` destacado em `/estoque` e `/produtos`
- [ ] PHPUnit manager e site verdes incl. os novos
- [ ] Nenhum arquivo fora do In-scope modificado
- [ ] `plans/README.md` atualizado

## STOP conditions

- Os trechos de `finalize()` ou a API de `DOLModel` não baterem com "Current state" —
  008/009 podem já ter editado `finalize()`; releia inteiro e reconcilie a posição
  da gravação (depende de `$orderId` já existir).
- Alguma verificação falhar duas vezes após ajuste razoável.
- Precisar tocar `lockAndValidateCart()` — não toque, reporte.

## Maintenance notes

- **`finalize()` é editado também por 008 (taxas) e 009 (cliente).** Ordem dentro de
  `finalize()`: reconferir carrinho → taxas → cria pedido → itens → upsert cliente +
  link → movimentos de saída + links. Releia se outro plano mergeou.
- **Devolução de estoque**: pedidos que expiram/são cancelados não devolvem estoque
  hoje. Follow-up: ao marcar `expirado`/`cancelado`, registrar `kind='entrada'` de
  estorno ligado ao mesmo pedido e somar de volta. Fora daqui porque o job de
  expiração está fora do escopo autorizado.
- `products.stock` continua a fonte da trava de venda (`FOR UPDATE` em
  `lockAndValidateCart`); o ledger é histórico, não recomputa saldo em tempo de venda.
- Interage com **Plano 011 (dashboard)**: KPI "produtos acabando" =
  count de `products` ativos com `stock<=stock_min && stock_min>0`.
- Revisor: conferir que `stock_movements` não tem `products_id`/`orders_id` inline e
  que os links vão nas junções.
