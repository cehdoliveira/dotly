# Plan 009: Identificação de cliente por CPF/telefone (site sem login) + relação via junção `orders_customers`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4ad3e67..HEAD -- migrations site/app/inc/controller/checkout_controller.php site/app/inc/model/orders_model.php manager/app/inc/model/orders_model.php site/app/inc/lib/DOLModel.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (toca `finalize()` + migrations novas)
- **Depends on**: none (coordena com 008 e 010 — todos editam `finalize()`)
- **Category**: migration / direction
- **Planned at**: commit `4ad3e67`, 2026-07-16

## Why this matters

O `site/` (frontend público) **não tem cadastro usuário+senha** — o comprador não
faz login (decisão de produto registrada em `plans/README.md`). Mesmo assim o
negócio precisa reconhecer clientes recorrentes para relatórios unificados. Hoje
cada pedido guarda os dados do comprador **inline** em `orders`
(`customer_name`, `customer_mail`, `customer_phone`, `customer_cpf`) — dois pedidos
do mesmo comprador são registros desconectados. O **nome não é chave** (homônimos).
Este plano cria a entidade `customers` (chave natural = **CPF normalizado**) e liga
cada pedido a um cliente **pelo mecanismo de junção do framework** (tabela
`orders_customers`). Resultado: novos pedidos com o mesmo CPF caem no mesmo cliente,
habilitando relatórios por cliente.

> O `manager/` MANTÉM seu login intacto. Esta regra é só do funil público.

## Fatos arquiteturais (LEGGO)

- Framework custom. `finalize()` (site) é o **único** caminho que grava pedido,
  dentro da transação global; `basic_redir()` commita. Sem `commit()`/`rollback()`
  manual.
- **RELACIONAMENTO = SEMPRE TABELA DE JUNÇÃO** (regra do dono). Duas tabelas que se
  relacionam têm `{a}_{b}` com `{a}_id`, `{b}_id` + auditoria + `active` +
  `UNIQUE`. **Não** use coluna FK inline (`orders.customers_id`). Exemplar:
  `migrations/004_create_table_users_profiles.sql`.
- `app/inc/lib/` e `app/inc/model/` são **cópias byte-idênticas** manager/site —
  model novo vai nas duas. `bin/check-shared-sync.sh` bloqueia drift.
- ORM = SQL cru; `DOLModel`; `set_filter([...],[...])` com `?`. Soft-delete
  `active='yes'/'no'`. Migrations em `migrations/` (raiz), numeradas, idempotentes.
- **DEV**: base pode ser dropada; backfill é best-effort.
- **CPF já é coletado e normalizado**: `checkout_controller::validateCustomer()` faz
  `preg_replace('/\D/', '', $post['cpf'])` e exige `strlen === 11`
  (`checkout_controller.php:362, 386-389`). Telefone idem (10-11 dígitos, 361, 381).

### API de junção do `DOLModel` (verificada)

- Nome da junção = `sprintf("%s_%s", $this->table, $class)` (owner primeiro);
  `$reverse_table` inverte. Colunas `{this->table}_id` e `{class}_id`.
- **`save_attach(['idx'=>$id,'post'=>['{class}_id'=>$val]], ['{class}'])`**
  (`DOLModel.php:428`): soft-delete dos links ativos do owner + insere os novos.
  Como cada pedido tem **um** cliente, chamar no `orders_model` (owner=orders,
  junção `orders_customers`) é seguro — só mexe nos links daquele `orders_id`.
- **`attach(['{class}'], $reverse, ...)`** (`DOLModel.php:268`): carrega
  `row["{class}_attach"]`. Para "pedidos de um cliente", chame no `customers_model`
  com `reverse_table` truthy (junção canônica é `orders_customers`).

## Current state

- **`orders`** (`migrations/012` + `015`) guarda o cliente inline:
  `customer_name`, `customer_mail`, `customer_phone`, `customer_cpf CHAR(11)`
  (só dígitos). Não há `customers` nem junção `orders_customers`.
- **`checkout_controller::finalize()`** (`site/app/inc/controller/checkout_controller.php`):
  - `$customer = $this->validateCustomer($post)` (linha 64) → array normalizado
    (`name, mail, phone, cpf, zip, ...`, linhas 357-414).
  - grava o pedido com `populate([...])` (92-108) e `$orderId = $order->save();`
    (109). `$order` é uma instância de `orders_model`.
- **`validateCustomer()`** (357-414): normaliza cpf/phone; valida
  `strlen($cpf) === 11` (386), `10 <= strlen($phone) <= 11` (381).
- **`orders_model`** (site/manager, idênticos): `$field` inclui os `customer_*`.
  **Não** haverá `customers_id` (relação é pela junção) — não mexa no `$field`.
- **Junção exemplar**: `migrations/004_create_table_users_profiles.sql`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Guard shared-sync | `bin/check-shared-sync.sh` | exit 0 |
| PHPUnit site | `docker exec -w /var/www/infinnityimportacao/site -e HTTP_HOST=localhost infinnityimportacao php app/inc/lib/vendor/bin/phpunit -c phpunit.xml` | all pass |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | idempotentes |
| Próximo nº | `ls migrations/ \| sort \| tail -1` | maior nº atual |

## Scope

**In scope**:
- `migrations/NNN_create_table_customers.sql` (criar)
- `migrations/NNN_create_table_orders_customers.sql` (criar — próximo nº)
- `site/app/inc/model/customers_model.php` + `manager/app/inc/model/customers_model.php` (criar — **byte-idênticos**)
- `site/app/inc/controller/checkout_controller.php` (upsert de cliente + `save_attach` em `finalize()`)
- `site/tests/CustomerUpsertTest.php` (criar)

**Out of scope**:
- Login/auth de qualquer ambiente. `auth_controller`, `users_model`, rotas de login.
- Coluna `orders.customers_id` — NÃO crie (relação é pela junção).
- Colunas `customer_*` de `orders` — NÃO remova (snapshot histórico do pedido).
- Tela/relatório de clientes no manager (follow-up).
- `DOLModel.php` (só usa a API).

## Git workflow

- Branch: `advisor/009-cliente-cpf`
- Commits PT-BR Conventional Commits. Sem push/PR sem ordem.

## Steps

### Step 1: Migration — tabela `customers` (chave natural = CPF)

Crie `migrations/NNN_create_table_customers.sql`. CPF `UNIQUE` (11 dígitos):
```sql
CREATE TABLE IF NOT EXISTS `customers` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') DEFAULT 'yes',
    `cpf` CHAR(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `mail` VARCHAR(255) NOT NULL DEFAULT '',
    `phone` VARCHAR(20) NOT NULL DEFAULT '',
    PRIMARY KEY (`idx`),
    UNIQUE KEY `uq_customers_cpf` (`cpf`),
    KEY `idx_customers_phone` (`phone`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Backfill (best-effort): 1 cliente por CPF distinto, dados do pedido mais recente.
INSERT IGNORE INTO `customers` (`created_at`, `created_by`, `active`, `cpf`, `name`, `mail`, `phone`)
SELECT NOW(), 0, 'yes', o.`customer_cpf`, o.`customer_name`, o.`customer_mail`, o.`customer_phone`
FROM `orders` o
JOIN (SELECT `customer_cpf`, MAX(`idx`) AS max_idx FROM `orders`
      WHERE `customer_cpf` <> '' GROUP BY `customer_cpf`) latest
  ON latest.max_idx = o.`idx`;
```

> **Chave** [assumção]: CPF, não telefone (CPF já é obrigatório e normalizado;
> telefone é mais volátil, fica só indexado). STOP se o dono quiser telefone como
> chave/merge.

**Verify**: rode migrations. `SELECT COUNT(DISTINCT cpf) FROM customers` == `SELECT COUNT(DISTINCT customer_cpf) FROM orders WHERE customer_cpf<>''`. 2º run idempotente.

### Step 2: Migration — junção `orders_customers`

Crie `migrations/NNN_create_table_orders_customers.sql`, modelada em
`users_profiles`:
```sql
CREATE TABLE IF NOT EXISTS `orders_customers` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') NOT NULL DEFAULT 'yes',
    `orders_id` INT NOT NULL,
    `customers_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_orders_id` (`orders_id`),
    KEY `idx_customers_id` (`customers_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_orders_customers` (`orders_id`, `customers_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Relacao pedido <-> cliente';

-- Backfill dos links a partir do CPF (best-effort).
INSERT IGNORE INTO `orders_customers` (`created_at`, `created_by`, `active`, `orders_id`, `customers_id`)
SELECT NOW(), 0, 'yes', o.`idx`, c.`idx`
FROM `orders` o
JOIN `customers` c ON c.`cpf` = o.`customer_cpf` AND c.`active` = 'yes'
WHERE o.`customer_cpf` <> '';
```

> Nome `orders_customers` (owner=orders primeiro) porque o `save_attach` no checkout
> é chamado a partir do `orders_model`. Para relatórios, `customers_model.attach(['orders'], reverse_table: true)`.

**Verify**: rode. Todo pedido com `customer_cpf` não-vazio tem 1 link. 2º run idempotente.

### Step 3: `customers_model` (2 cópias)

Crie `site/app/inc/model/customers_model.php`:
```php
<?php
class customers_model extends DOLModel
{
    protected array $field = [" idx ", " cpf ", " name ", " mail ", " phone "];
    protected array $filter = [" active = 'yes' "];
    function __construct() { parent::__construct("customers"); }
}
```
Copie byte-idêntico para `manager/app/inc/model/customers_model.php`.

**Verify**: `bin/check-shared-sync.sh` → exit 0.

### Step 4: Upsert de cliente + `save_attach` em `finalize()`

Em `checkout_controller::finalize()`, **depois** do `$orderId = $order->save();`
(linha 109), faça o upsert por CPF e ligue o pedido ao cliente pela junção.
Extraia num método privado testável (padrão do repo):
```php
$customerId = $this->upsertCustomer($customer);
$order->save_attach(
    ['idx' => $orderId, 'post' => ['customers_id' => $customerId]],
    ['customers']
);
```
> `$order` é a instância de `orders_model` já usada no `save()`. `save_attach`
> monta a junção `orders_customers` (owner = `orders`). Como o pedido é novo, não há
> link anterior; insere 1.

Novo método privado:
```php
/** Acha o cliente pelo CPF (chave unica) ou cria; devolve o idx. */
private function upsertCustomer(array $customer): int
{
    $model = new customers_model();
    $model->set_field([" idx "]);
    $model->set_filter([" active = 'yes' ", " cpf = ? "], [$customer['cpf']]);
    $model->set_paginate([1]);
    $model->load_data(false);
    $existing = $model->data[0] ?? null;

    if ($existing !== null) {
        $update = new customers_model();
        $update->set_filter(["idx = ?"], [(int)$existing['idx']]);
        $update->populate([
            'name'  => $customer['name'],
            'mail'  => $customer['mail'],
            'phone' => $customer['phone'],
        ]);
        $update->save();
        return (int)$existing['idx'];
    }

    $create = new customers_model();
    $create->populate([
        'cpf'   => $customer['cpf'],
        'name'  => $customer['name'],
        'mail'  => $customer['mail'],
        'phone' => $customer['phone'],
    ]);
    return (int)$create->save();
}
```

> Tudo na transação global do request — cliente + pedido + link commitam juntos no
> `basic_redir()` final. Se o checkout der rollback
> (`basic_redir(..., rollback: true)`, linha ~173), tudo é revertido junto.

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`.

## Test plan

- `site/tests/CustomerUpsertTest.php` (`DBTestCase`; molde em `CheckoutStockTest.php`).
  Casos:
  - CPF novo → cria cliente + 1 link ativo em `orders_customers` para o pedido.
  - **Mesmo CPF, segundo pedido** → NÃO cria cliente novo (mesmo `customers_id`);
    nome/telefone atualizados; o segundo pedido ganha seu próprio link para o mesmo
    cliente (dois pedidos, um cliente).
  - Nome diferente, mesmo CPF → um único cliente.
  - CPF diferente → clientes distintos.
- Verificação: PHPUnit site verde incl. os novos.

## Done criteria

- [ ] PHPStan site e manager → `[OK] No errors`
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `diff -q site/app/inc/model/customers_model.php manager/app/inc/model/customers_model.php` → sem saída
- [ ] Migrations idempotentes; `customers.cpf` UNIQUE; junção `orders_customers` existe
- [ ] `grep -rn "customers_id" migrations/` → aparece SÓ na junção (nenhum `ALTER TABLE orders ADD ... customers_id`)
- [ ] Backfill: nenhum pedido com `customer_cpf<>''` fica sem link na junção
- [ ] Segundo pedido com mesmo CPF reusa o `customers_id` e cria seu próprio link (teste + SELECT)
- [ ] PHPUnit site verde incl. `CustomerUpsertTest`
- [ ] `auth_controller`/login intactos (`git status` sem nada de auth)
- [ ] Nenhum arquivo fora do In-scope modificado
- [ ] `plans/README.md` atualizado

## STOP conditions

- Os trechos de `finalize()` ou a API de `DOLModel` (`save_attach`, linha 428) não
  baterem com "Current state" — 008/010 podem já ter editado `finalize()`; releia
  o método inteiro e reconcilie.
- Pedidos com `customer_cpf` vazio ou != 11 dígitos (dados sujos) — o backfill os
  deixaria sem link. Reporte a contagem.
- O dono definir telefone (e não CPF) como chave — pare e confirme a regra de merge.

## Maintenance notes

- **`finalize()` é editado também pelos Planos 008 (taxas) e 010 (estoque).** Ordem
  dentro de `finalize()`: reconferir carrinho → taxas (008) → cria pedido → itens →
  upsert cliente + `save_attach` (009) → movimentos de saída (010). Releia se outro
  plano mergeou.
- Colunas `customer_*` em `orders` = snapshot histórico do pedido; `customers` = estado
  atual do cliente. "Pedidos deste cliente" = `customers_model.attach(['orders'], reverse_table: true)`
  ou `JOIN orders_customers`.
- Follow-up: tela/relatório de clientes no manager (histórico por CPF).
- LGPD: CPF é dado pessoal. Revisor: nunca em log/URL; mascarar se exposto no manager.
