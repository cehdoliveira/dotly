# Plan 014: Busca de cliente por CPF/telefone + histórico de pedidos (manager)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat fdb4216..HEAD -- manager/app/inc/controller/orders_controller.php manager/app/inc/model/customers_model.php manager/app/inc/urls.php manager/public_html/index.php manager/public_html/ui/page/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: direction (feature — Fase 2 item #1)
- **Planned at**: commit `fdb4216`, 2026-07-16

## Why this matters

O manager não tem **nenhuma** tela de cliente: `customers_model` existe mas nunca
é instanciado (0 ocorrências em `manager/app/inc/controller` / `ui/page`). Hoje o
operador só vê o cliente como snapshot denormalizado dentro de um pedido, e não
consegue responder "mostre tudo do cliente X". Esta tela deixa o operador digitar
um CPF **ou** telefone (com ou sem máscara) e ver os pedidos daquele cliente,
usando as três tabelas já existentes: `customers`, `orders`, `orders_customers`.

## Current state

Fatos e arquivos relevantes (todos já existem no repo):

- **`manager/app/inc/model/customers_model.php`** — modelo pronto, reutilizável:
  ```php
  <?php
  class customers_model extends DOLModel
  {
      protected array $field = [" idx ", " cpf ", " name ", " mail ", " phone "];
      protected array $filter = [" active = 'yes' "];
      function __construct() { parent::__construct("customers"); }
  }
  ```
  Tabela `customers` (migration `021_create_table_customers.sql`): `cpf CHAR(11)`
  **digits-only** com `UNIQUE uq_customers_cpf (cpf)`; `phone VARCHAR(20)` digits-only
  com `KEY idx_customers_phone (phone)`; `name`, `mail`. Sem índice em `mail`.

- **Junção `orders_customers`** (migration `022_create_table_orders_customers.sql`):
  colunas `orders_id INT`, `customers_id INT`, `active`, `UNIQUE (orders_id, customers_id)`,
  `KEY idx_customers_id`, `KEY idx_orders_id`. O dono da relação é `orders`
  (`save_attach` é chamado do lado do pedido). **Não há helper pronto que navegue
  customer → orders** (o `attach()`/`join()` do DOLModel vai de orders → customers).
  Para listar os pedidos de um cliente você consulta a junção diretamente com
  `execute_raw_prepared` (ver Step 4).

- **Padrão `set_filter` com placeholders `?`** (input de usuário SEMPRE assim) — exemplo
  real em `site/app/inc/controller/checkout_controller.php:279`:
  `$model->set_filter(["cpf = ?"], [$cpf]); $model->set_paginate([1]); $model->load_data(false);`

- **Normalizador digits-only** — `sanitize_string(mixed $value, bool $digitsOnly = false)`
  em `manager/app/inc/lib/CommonFunctions.php` (por volta da linha 296): com o 2º
  argumento `true` retorna `preg_replace('/\D+/', '', $value)`. Use-o para limpar CPF/telefone.

- **`orders_model`** (`manager/app/inc/model/orders_model.php`) — `$field` inclui
  `idx, token, status, customer_name, total_cents, created_at, paid_at` entre outros.
  Enum de status: `aguardando_pagamento, pago, cancelado, expirado`.

- **Roteamento do manager** — `manager/public_html/index.php:62-112` registra rotas
  atrás de `$authGuard`. Exemplo (linhas 95-96):
  ```php
  $dispatcher->add_route("GET",  $products_url_pattern, "products_controller:index",  $authGuard, $params);
  $dispatcher->add_route("POST", $products_url_pattern, "products_controller:action", $authGuard, $params);
  ```
  Confirme o formato exato de `add_route` lido nas linhas de `/pedidos`
  (`index.php:103-104`) e replique.

- **Constantes de URL** — `manager/app/inc/urls.php`. Padrão:
  `$orders_url = sprintf("%s%s", constant("cFrontend"), "pedidos");`

- **Como um controller de página renderiza** (padrão a copiar — de `orders_controller::index`):
  ```php
  include(constant("cRootServer") . "ui/common/head.php");
  include(constant("cRootServer") . "ui/common/header.php");
  include(constant("cRootServer") . "ui/page/<sua_view>.php");
  include(constant("cRootServer") . "ui/common/footer.php");
  include(constant("cRootServer") . "ui/common/foot.php");
  ```

- **Sidebar duplicada** — NÃO existe partial de menu. A sidebar é copiada inline em
  cada view de `manager/public_html/ui/page/`. O bloco completo (com todos os itens)
  está em `sales_dashboard.php:24-84`. Um item de menu é assim:
  ```php
  <li class="nav-item">
      <a href="<?php echo $GLOBALS['orders_url']; ?>" class="nav-link">
          <i class="bi bi-receipt" aria-hidden="true"></i> Pedidos
      </a>
  </li>
  ```

## Commands you will need

| Purpose        | Command                                                              | Expected on success |
|----------------|----------------------------------------------------------------------|---------------------|
| PHPStan manager| `cd manager && php app/inc/lib/vendor/bin/phpstan analyse`            | `[OK] No errors`    |
| PHPStan site   | `cd site && php app/inc/lib/vendor/bin/phpstan analyse`              | `[OK] No errors`    |
| PHPUnit manager| `cd manager && php app/inc/lib/vendor/bin/phpunit`                    | all pass            |
| Single test    | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter CustomerSearchTest` | pass       |
| Shared-sync    | `bin/check-shared-sync.sh`                                           | exit 0              |

PHPUnit precisa de `kernel.php` + DB vivo. Se não houver DB acessível, rode o
PHPStan e o `php -l` (lint) e registre no relatório que o PHPUnit não pôde rodar.

## Scope

**In scope** (only files you may create/modify):
- `manager/app/inc/urls.php` — add `$customers_url`
- `manager/public_html/index.php` — add GET route `/clientes`
- `manager/app/inc/controller/customers_controller.php` — **create**
- `manager/public_html/ui/page/customers.php` — **create** (search form + results)
- `manager/tests/CustomerSearchTest.php` — **create**
- The 10 sidebar copies in `manager/public_html/ui/page/*.php` — add ONE "Clientes" `<li>` to each (see Step 6)

**Out of scope** (do NOT touch):
- `manager/app/inc/model/customers_model.php` — já está pronto e é **byte-idêntico** a `site/`; não edite.
- `orders_model.php`, `DOLModel.php`, qualquer lib compartilhada — não precisa mudar.
- `orders_customers` / `customers` schema — as tabelas já existem; **não crie migration**.
- Qualquer alteração em autenticação/`$authGuard`.
- Não refatore a sidebar duplicada num partial (é o achado A1, follow-up separado — ver Maintenance).

## Git workflow

- Branch: `advisor/014-busca-cliente`
- Commits em PT-BR, Conventional Commits. Ex.: `feat: adiciona busca de cliente por CPF/telefone no manager`
- NÃO faça push nem abra PR a menos que o operador peça.

## Steps

### Step 1: Add URL constant

Em `manager/app/inc/urls.php`, adicione (perto de `$orders_url`):
```php
$customers_url = sprintf("%s%s", constant("cFrontend"), "clientes");
```

**Verify**: `grep -n customers_url manager/app/inc/urls.php` → 1 linha.

### Step 2: Register the route

Em `manager/public_html/index.php`, junto das rotas de `/pedidos`, adicione uma rota
GET atrás de `$authGuard`, seguindo o formato exato de `add_route` que você leu ali:
```php
$dispatcher->add_route("GET", <pattern p/ "clientes">, "customers_controller:index", $authGuard, $params);
```
A busca é somente leitura → **GET só** (o dispatcher só trata GET/POST; GET é o certo
para consulta sem efeito colateral, dispensa CSRF). Os termos vêm em `$info['get']`.

**Verify**: `grep -n "customers_controller" manager/public_html/index.php` → 1 linha.

### Step 3: Create the controller — `customers_controller.php`

`manager/app/inc/controller/customers_controller.php`. Uma action `index(array $info)`:

1. Ler e normalizar o termo:
   ```php
   $raw   = trim($info['get']['q'] ?? '');
   $digits = sanitize_string($raw, true); // só dígitos
   ```
2. Se `$digits === ''` → renderiza a view sem buscar (`$customers = []; $searched = false;`).
3. Se há dígitos, buscar em `customers` por CPF **ou** telefone, com placeholders `?`:
   ```php
   $cust = new customers_model();
   $cust->set_field([" idx ", " cpf ", " name ", " mail ", " phone "]);
   $cust->set_filter([" active = 'yes' ", " (cpf = ? OR phone = ?) "], [$digits, $digits]);
   $cust->set_order([" name ASC "]);
   $cust->load_data(false);
   $customers = $cust->data;
   ```
   (Um telefone de 11 dígitos pode coincidir com um CPF; buscar as duas colunas com
   o mesmo valor resolve os dois casos sem ambiguidade — retorna qualquer cliente
   cujo CPF **ou** telefone bata.)
4. Para cada cliente encontrado, carregar os pedidos dele **pela junção** (Step 4),
   anexando em `$customers[$i]['orders']`.
5. Renderizar a view com o padrão head/header/page/footer/foot (ver Current state).
   Envolva a lógica de DB num `try { … } catch (RuntimeException $e) { … }` como
   `orders_controller::index` faz, degradando para lista vazia + mensagem.

### Step 4: Load a customer's orders via the junction

Sem helper customer→orders, use `execute_raw_prepared` (prepared, seguro). Dentro do
controller, um método privado:
```php
private function ordersOfCustomer(orders_model $model, int $customerId): array
{
    $stmt = $model->execute_raw_prepared(
        "SELECT o.idx, o.token, o.status, o.total_cents, o.created_at, o.paid_at
           FROM orders o
           JOIN orders_customers oc ON oc.orders_id = o.idx AND oc.active = 'yes'
          WHERE oc.customers_id = ? AND o.active = 'yes'
          ORDER BY o.created_at DESC",
        [$customerId]
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```
Instancie `orders_model` uma vez e reuse. (`execute_raw_prepared` é o mesmo método
usado por `orders_controller::index:27` para o COUNT.)

**Verify**: PHPStan (Step 8). Nesta etapa, `php -l` nos 2 arquivos novos:
`php -l manager/app/inc/controller/customers_controller.php` → `No syntax errors`.

### Step 5: Create the view — `customers.php`

`manager/public_html/ui/page/customers.php`. Espelhe a estrutura visual de
`ui/page/orders.php` (mesmas classes `content-panel`, `table`, `user-badge`).
Inclua:
- A sidebar completa (copie o bloco de `sales_dashboard.php:24-84`) **já com o item
  "Clientes" marcado `active`** (Step 6 define o `<li>`).
- Um form `method="GET"` com um input `name="q"` (placeholder "CPF ou telefone") e
  botão "Buscar", apontando para `$GLOBALS['customers_url']`.
- Se `$searched && empty($customers)` → "Nenhum cliente encontrado."
- Para cada cliente: nome, CPF formatado, telefone, e-mail, e a tabela de pedidos
  dele (token 8 chars, status com badge, total `R$ number_format(cents/100,2,',','.')`,
  criado, pago). **Escape tudo** com `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
- **Não** exponha nada além de cpf/nome/telefone/mail e os pedidos (essa é tela
  interna do operador; CPF completo aqui é OK, é o backoffice autenticado).

### Step 6: Add "Clientes" to the sidebar (all copies)

A sidebar é duplicada. Adicione este `<li>` (logo após o item "Pedidos") em **cada**
uma das views que renderizam a sidebar:
```php
<li class="nav-item">
    <a href="<?php echo $GLOBALS['customers_url']; ?>" class="nav-link">
        <i class="bi bi-person-lines-fill" aria-hidden="true"></i> Clientes
    </a>
</li>
```
Arquivos a editar (confirme a lista com o grep abaixo antes):
`sales_dashboard.php`, `dashboard.php`, `products.php`, `orders.php`, `order_detail.php`,
`gateways.php`, `emails.php`, `profiles.php`, `categories.php`, `stock.php`
(todos em `manager/public_html/ui/page/`). Na `customers.php` o link fica `active`.

**Verify**:
`grep -rl "customers_url" manager/public_html/ui/page/ | wc -l` → 11 (10 sidebars + a nova view).

### Step 7: Write the test — `CustomerSearchTest.php`

Estende `DBTestCase` (transação + rollback automático). Modele por
`manager/tests/CustomerUpsertTest.php` (usa `ReflectionMethod` p/ métodos privados) e
por `OrdersFilterTest.php`. Como o controller renderiza views (inclui HTML), teste a
**lógica de busca** extraindo/chamando o método de busca, ou testando o resultado de
uma consulta que reproduz o `set_filter`. Cubra:
- **Normalização**: `"123.456.789-09"` e `"12345678909"` encontram o mesmo cliente.
- **Busca por telefone**: `"(11) 98888-7777"` normaliza p/ `11988887777` e acha o cliente.
- **Cliente → pedidos pela junção**: cria cliente + 2 pedidos linkados em
  `orders_customers` + 1 pedido não linkado; a busca retorna exatamente os 2 linkados.
- **Sem resultado**: dígitos que não batem retornam lista vazia (sem erro).

Insira as fixtures com os models (`customers_model->populate()->save()`, idem orders,
e o link via `save_attach` do `orders_model` — ver `checkout_controller.php:253-257`).

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter CustomerSearchTest`
→ todos passam.

### Step 8: Verificação final

Rode todos os comandos da tabela "Commands you will need".

## Test plan

- Novo: `manager/tests/CustomerSearchTest.php` (estende `DBTestCase`), casos acima.
- Padrão estrutural: `manager/tests/CustomerUpsertTest.php` + `OrdersFilterTest.php`.
- Verificação: PHPUnit manager verde, com os N casos novos passando.

## Done criteria

- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpunit` → verde, incl. `CustomerSearchTest`
- [ ] `bin/check-shared-sync.sh` → exit 0 (você não tocou em `lib/`/`model/`)
- [ ] `GET /clientes` renderiza; buscar por CPF mascarado e por telefone acha o cliente e lista os pedidos dele
- [ ] Item "Clientes" aparece na sidebar de todas as telas
- [ ] `git status` não mostra arquivos fora do In scope
- [ ] Linha de status deste plano atualizada em `plans/README.md`

## STOP conditions

Pare e reporte (não improvise) se:
- O `customers_model` ou o schema de `customers`/`orders_customers` divergir dos
  excerptos de "Current state" (drift).
- `orders_customers` não tiver linhas para nenhum pedido na base de teste — isso indica
  que o link `save_attach` do checkout não rodou; **não** troque para consultar
  `orders.customer_cpf` sem me avisar (mudaria a fonte de dados que o dono pediu).
- Uma verificação falhar 2× após uma tentativa razoável de conserto.
- A feature parecer exigir tocar um arquivo fora do In scope.

## Maintenance notes

- **Achado A1 (fora de escopo):** a sidebar é copiada em 10 arquivos. Este plano adiciona
  o item "Clientes" em todos, mas a duplicação continua. Um follow-up deveria extrair
  `ui/common/sidebar.php` com `$activeNav`. Se isso for feito, o `<li>` de Clientes some
  dos 10 e vai pro partial.
- **Completude do histórico:** a busca lista pedidos pela junção `orders_customers`. Pedidos
  criados **antes** da migration 022 (e não cobertos pelo backfill) podem não ter link e não
  aparecer. A coluna denormalizada `orders.customer_cpf` cobriria 100%, mas o dono pediu
  explicitamente a junção. Se aparecer reclamação de "pedido faltando", reavaliar com o dono.
- **Índices:** a busca em `customers` usa `uq_customers_cpf` e `idx_customers_phone` (ok). A
  junção usa `idx_customers_id`/`idx_orders_id` (ok). Nenhum índice novo necessário.
- Revisor deve conferir: todo input passa por `?` placeholder; nenhuma concatenação de
  string em SQL; todo output escapado com `htmlspecialchars`.
