# Plan 030: desbloquear cliente (soft-delete) + escopar o UNIQUE de `blocked_customers` a `active='yes'`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 6cd0d58..HEAD -- migrations manager/app/inc/controller/customers_controller.php manager/public_html/ui/page/customer_detail.php manager/public_html/ui/page/customers.php`
> Se qualquer arquivo em escopo mudou, compare os trechos de "Current state" com o
> código vivo antes de prosseguir; divergência = STOP condition.

## Status

- **Priority**: P1 (pedido explícito do dono: hoje só bloqueia, falta desbloquear)
- **Effort**: M
- **Risk**: MED (mexe no fluxo de escrita da blocklist + troca o UNIQUE por um índice funcional)
- **Depends on**: none (fecha também o TODOS.md #3, que dependia desta feature)
- **Category**: feature + migration
- **Planned at**: commit `6cd0d58`, 2026-07-18

## Why this matters

A tela `/clientes` do manager só **bloqueia** cliente — não há como desbloquear. O
dono precisa desbloquear. Bloquear grava uma linha em `blocked_customers`; o checkout
público (`checkout_controller::isBlocked`) recusa o pedido se e-mail, CPF **ou**
telefone bater. Desbloquear = **soft-delete** dessas linhas (`active='no'`, seguindo
a regra universal do projeto "nunca `DELETE FROM`") para o checkout voltar a aceitar.

**Por que isto arrasta uma migration junto**: o TODOS.md #3 antecipou exatamente
este momento. A migration `035_unique_customer_mail_blocked_customers.sql` criou
`UNIQUE KEY uniq_blocked_customers_mail (customer_mail)` **sem escopar a `active`**.
Todo caminho de *leitura* filtra `active='yes'`, mas o UNIQUE cobre linhas
soft-deletadas também. Sem a migration deste plano, o cenário quebra assim:

1. Bloqueia `joao@x.com` → linha `active='yes'`.
2. Desbloqueia → linha vira `active='no'` (mas `customer_mail` continua lá).
3. Re-bloqueia `joao@x.com` → o `INSERT` de uma linha nova bate no
   `uniq_blocked_customers_mail` (que enxerga a linha inativa) → falha.
4. O recheck do controller (só vê `active='yes'`) não acha nada → reporta
   **"Falha ao bloquear"** em vez de bloquear.

A correção é trocar o UNIQUE por um **índice funcional** que só vale para linhas
ativas: `UNIQUE ((IF(active='yes', customer_mail, NULL)))`. Linhas inativas mapeiam
para `NULL` (múltiplos NULL são permitidos num UNIQUE do MySQL), então re-bloquear
cria uma linha ativa nova sem colidir com as soft-deletadas — e a garantia
anti-race entre dois cliques concorrentes em "Bloquear" (que a migration 035 dava)
continua valendo para as linhas ativas.

## Current state

### 1. O model
`manager/app/inc/model/blocked_customers_model.php` (idêntico em `site/`, ver §Shared):
```php
class blocked_customers_model extends DOLModel
{
    protected array $field = [" idx ", " customer_mail ", " customer_cpf ", " customer_phone ", " blocked_at ", " active "];
    protected array $filter = [" active = 'yes' "];
    function __construct() { parent::__construct("blocked_customers"); }
}
```

### 2. O soft-delete do framework — `DOLModel::remove()`
`manager/app/inc/lib/DOLModel.php:118-136` (o helper que você vai usar para desbloquear):
```php
public function remove(): ?\PDOStatement
{
    $fi = " where " . implode(" and ", $this->filter) . " ";
    $pa = isset($this->paginate) ? " limit " . implode(" , ", $this->paginate) . " " : "";
    $userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
        ? $_SESSION[constant("cAppKey")]["credential"]["idx"] : 0;
    $params = [$userId];
    if (!empty($this->filterParams)) {
        $params = array_merge($params, $this->filterParams);
    }
    $sql = sprintf("UPDATE %s SET active = 'no', removed_at = now(), removed_by = ? %s", $this->table, $fi . $pa);
    return $this->con->executePrepared($sql, $params);
}
```
`remove()` monta o `WHERE` a partir do `$this->filter` corrente (com `filterParams`
para os `?`). Ou seja: um `set_filter([...], [...])` seguido de `remove()` faz
`UPDATE ... SET active='no', removed_at, removed_by WHERE <seu filtro>`.

### 3. O controller de escrita — `customers_controller::action()`
`manager/app/inc/controller/customers_controller.php:331-432`. Hoje só aceita
`action === 'bloquear'`. Trechos-chave a conhecer:

Guarda de ação (linha 341):
```php
if ($action !== 'bloquear' || $idx <= 0) {
    basic_redir($customers_url);
}
```

Lê o pedido-âncora por `idx` para obter mail/cpf/phone (linhas 347-362):
```php
$model = new orders_model();
$model->set_field([" customer_name ", " customer_mail ", " customer_cpf ", " customer_phone "]);
$model->set_filter([" active = 'yes' ", " idx = ? "], [$idx]);
$model->set_paginate([1]);
$model->load_data(false);
$order = $model->data[0] ?? null;
// ... $mail, $cpf, $phone extraídos de $order
```

E o `basic_redir` final (linha 431), que comita a transação:
```php
basic_redir($customers_url, rollback: $rollback);
```

O EXISTS de match (mail OR cpf OR phone, com guarda `<> '' AND = ?` para vazios) é o
mesmo em todo lugar — ver `blockedExistsSql()` (linhas 49-58) e `isBlocked()` do
checkout.

### 4. A migration atual do UNIQUE (a ser substituída)
`migrations/035_unique_customer_mail_blocked_customers.sql` criou
`uniq_blocked_customers_mail (customer_mail)` (não escopado). A tabela
(`034_create_table_blocked_customers.sql`) tem as colunas de auditoria
`active ENUM('yes','no') DEFAULT 'yes'`, `removed_at`, `removed_by`.

### 5. A view de detalhe — botão Bloquear
`manager/public_html/ui/page/customer_detail.php:70-81`:
```php
<?php if (!$isBlocked && $lastIdx > 0): ?>
    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>"
        @submit.prevent="confirmBlock($event.target, <?php echo $e(json_encode($name)); ?>)">
        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="bloquear">
        <input type="hidden" name="idx" value="<?php echo $lastIdx; ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger" title="Bloquear cliente">
            <i class="bi bi-slash-circle me-1" aria-hidden="true"></i> Bloquear
        </button>
    </form>
<?php endif; ?>
```
O badge "Bloqueado" já aparece quando `$isBlocked` (linhas 64-66). `$lastIdx` é o idx
do pedido-âncora; `$isBlocked` vem de `customers_controller::show()`.

### 6. A view de lista — botão Bloquear / estado Bloqueado
`manager/public_html/ui/page/customers.php:186-203`:
```php
<?php if (!$isBlocked): ?>
    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>"
          @submit.prevent="confirmBlock($event.target, <?php echo $e(json_encode($name)); ?>)">
        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="bloquear">
        <input type="hidden" name="idx" value="<?php echo $lastOrderIdx; ?>">
        <button type="submit" class="btn btn-sm btn-action-remove" title="Bloquear cliente">
            <i class="bi bi-slash-circle" aria-hidden="true"></i>
            <span class="customers-action-label">Bloquear</span>
        </button>
    </form>
<?php else: ?>
    <button type="button" class="btn btn-sm btn-action-remove" disabled title="Cliente já bloqueado">
        <i class="bi bi-slash-circle" aria-hidden="true"></i>
        <span class="customers-action-label">Bloqueado</span>
    </button>
<?php endif; ?>
```
O `else` (cliente bloqueado) hoje mostra um botão **desabilitado**. Este plano troca
esse `else` por um form de desbloqueio funcional. `$lastOrderIdx` é o idx do
pedido-âncora da linha.

### Convenções obrigatórias

- **Regra universal soft-delete**: nunca `DELETE FROM`; desbloquear = `active='no'`
  via `remove()`. (CLAUDE.md.)
- **CSRF em todo POST**: `validate_csrf($post['_csrf_token'] ?? null, $customers_url)`
  — o `action()` já chama isso uma vez no topo (linha 339); a nova ação reusa a
  mesma validação, não adicione uma segunda.
- **Transação única por request**: não chame `commit()`/`rollback()` manualmente; o
  `basic_redir($url, rollback: $bool)` no fim resolve. (CLAUDE.md.)
- **Shared framework byte-idêntico**: `app/inc/lib/` e `app/inc/model/` DEVEM ser
  idênticos entre `manager/` e `site/`. Este plano **não altera** nem model nem lib
  (só controller/views/migration do manager), então nada a sincronizar — mas ver
  STOP conditions se você achar que precisa mexer no model.
- **Migration idempotente**: padrão de `035_unique_customer_mail_blocked_customers.sql`
  (checagem em `information_schema`, DDL condicional via `PREPARE`/`EXECUTE`).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Rodar migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | exit 0 |
| Ver índice | `docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e "SHOW INDEX FROM blocked_customers;"` | ver Step 1 |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | todos passam |
| Um teste | `cd manager && php app/inc/lib/vendor/bin/phpunit --filter CustomerBlockTest` | passa |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 (sem divergência lib/model) |

(Credenciais/DB name: de `docker/docker-compose.yml` / `kernel.php` se diferirem.)

## Scope

**In scope** (os únicos arquivos a criar/modificar):
- `migrations/038_scope_blocked_customers_unique_active.sql` (criar)
- `manager/app/inc/controller/customers_controller.php` (adicionar caminho `desbloquear` em `action()`)
- `manager/public_html/ui/page/customer_detail.php` (botão Desbloquear quando `$isBlocked`)
- `manager/public_html/ui/page/customers.php` (trocar o botão desabilitado por form Desbloquear)
- `manager/tests/CustomerBlockTest.php` (adicionar casos de desbloqueio + re-bloqueio)

**Out of scope** (NÃO tocar):
- `manager/app/inc/model/blocked_customers_model.php` e a cópia em `site/` — não
  precisa mudar; `remove()` + `set_filter` bastam.
- `site/app/inc/controller/checkout_controller.php` — `isBlocked()` já filtra
  `active='yes'`, então soft-delete "desbloqueia" automaticamente no checkout. Sem
  mudança.
- `manager/app/inc/lib/DOLModel.php` (e cópia `site/`) — usar `remove()` como está.
- `migrations/034` e `035` — imutáveis; a mudança vem na 038.
- A view de detalhe do site / qualquer coisa fora do manager.

## Git workflow

- Branch: `advisor/030-desbloquear-cliente`
- Commits por unidade lógica (migration, controller, views, testes) ou um só;
  PT-BR Conventional Commits, ex.:
  `feat: desbloquear cliente em /clientes (soft-delete) + índice UNIQUE escopado a active`
- Sem push/PR salvo instrução do operador.

## Steps

Ordem pensada para o banco nunca ficar quebrado: **migration primeiro** (re-bloqueio
passa a funcionar), depois controller, depois views, depois testes.

### Step 1: Migration 038 — trocar o UNIQUE por índice funcional escopado

Crie `migrations/038_scope_blocked_customers_unique_active.sql`. Ela **dropa** o
`uniq_blocked_customers_mail` (da migration 035) e **cria** um UNIQUE funcional que
só vale para `active='yes'`. Siga o padrão idempotente de 035 (checagem em
`information_schema` para os dois índices):

```sql
-- TODOS.md #3 / Plano 030: a feature de desbloquear cliente faz soft-delete
-- (active='no') das linhas de blocked_customers. O UNIQUE da migration 035
-- (uniq_blocked_customers_mail em customer_mail) NÃO era escopado a active, então
-- re-bloquear um cliente antes desbloqueado bateria no UNIQUE de uma linha já
-- inativa e o INSERT falharia (o recheck do controller, que só vê active='yes',
-- não acharia nada e reportaria "Falha ao bloquear").
--
-- Troca por um índice funcional (MySQL 8.0.13+): a chave é
-- IF(active='yes', customer_mail, NULL). Linhas inativas viram NULL — múltiplos
-- NULL são permitidos num UNIQUE — então soft-deletados não colidem. Linhas ativas
-- mantêm a unicidade de customer_mail (fecha a race entre dois "Bloquear"
-- concorrentes, igual a 035 dava).
--
-- Idempotência: checagem em information_schema por nome de índice (padrão de 035).

SET @new_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_customers'
      AND INDEX_NAME = 'uniq_blocked_customers_active_mail'
);
SET @old_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_customers'
      AND INDEX_NAME = 'uniq_blocked_customers_mail'
);
SET @ddl := IF(
    @new_exists = 0,
    IF(
        @old_exists = 0,
        'ALTER TABLE `blocked_customers` ADD UNIQUE KEY `uniq_blocked_customers_active_mail` ((IF(`active` = ''yes'', `customer_mail`, NULL)))',
        'ALTER TABLE `blocked_customers` DROP KEY `uniq_blocked_customers_mail`, ADD UNIQUE KEY `uniq_blocked_customers_active_mail` ((IF(`active` = ''yes'', `customer_mail`, NULL)))'
    ),
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

**Atenção à sintaxe**: as aspas simples de `'yes'` dentro da string SQL do `@ddl`
precisam ser **duplicadas** (`''yes''`), porque a string toda está entre aspas
simples. O parêntese duplo `((...))` é obrigatório para índice funcional no MySQL.

**Pré-flight** (rodar antes de aplicar, para garantir que não há dois `active='yes'`
com o mesmo mail que fariam o novo UNIQUE falhar — não deveria haver, o 035 já
garantia isso, mas confirme):
```bash
docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e \
"SELECT customer_mail, COUNT(*) c FROM blocked_customers WHERE active='yes' GROUP BY customer_mail HAVING c>1;"
```
→ 0 linhas. Se retornar linhas → **STOP**.

**Verify**:
```bash
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php
docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e "SHOW INDEX FROM blocked_customers;"
```
→ deve existir `uniq_blocked_customers_active_mail` (Non_unique=0) e **não** deve
mais existir `uniq_blocked_customers_mail`. Rode o runner 2x → 2ª vez exit 0 (`DO 0`).

### Step 2: `action()` — aceitar `desbloquear`

Em `manager/app/inc/controller/customers_controller.php`, no método `action()`:

**2a.** Amplie a guarda de ação (linha 341) para aceitar as duas ações:
```php
if (($action !== 'bloquear' && $action !== 'desbloquear') || $idx <= 0) {
    basic_redir($customers_url);
}
```

**2b.** O bloco que lê o pedido-âncora (linhas 347-362) fornece `$mail/$cpf/$phone`
para as duas ações — mantenha-o. Logo depois de obter `$mail/$cpf/$phone` (e antes
do bloco de INSERT do bloqueio), ramifique por ação. Para `desbloquear`, faça o
soft-delete das linhas que casam o cliente e retorne:

```php
if ($action === 'desbloquear') {
    $block = new blocked_customers_model();
    $block->set_filter(
        [
            " active = 'yes' ",
            " ( customer_mail = ? OR ( customer_cpf <> '' AND customer_cpf = ? ) OR ( customer_phone <> '' AND customer_phone = ? ) ) ",
        ],
        [$mail, $cpf, $phone]
    );
    $stmt = $block->remove();
    $affected = $stmt ? $stmt->rowCount() : 0;

    if ($affected > 0) {
        $_SESSION["messages_app"]["success"] = [
            "Cliente " . htmlspecialchars((string)($order['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8') . " desbloqueado. Novos pedidos serão aceitos no checkout.",
        ];
    } else {
        $_SESSION["messages_app"]["info"] = ["Este cliente não estava bloqueado."];
    }
    basic_redir($customers_url);
}
```

O `remove()` usa o `$this->filter`/`filterParams` que você acabou de setar e emite
`UPDATE blocked_customers SET active='no', removed_at=now(), removed_by=? WHERE
active='yes' AND (<match>)`. Como o checkout só olha `active='yes'`, o cliente volta
a ser aceito. O `basic_redir` sem `rollback` comita a transação.

O caminho `bloquear` (INSERT...WHERE NOT EXISTS + catch da race) permanece **exatamente
como está** logo abaixo — não o altere.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0, sem erro.

### Step 3: View de detalhe — botão Desbloquear

Em `manager/public_html/ui/page/customer_detail.php`, no `<div class="d-flex gap-2">`
(logo após o `<?php endif; ?>` do form de bloquear, linha ~81), adicione o form de
desbloqueio, exibido quando `$isBlocked`:

```php
<?php if ($isBlocked && $lastIdx > 0): ?>
    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>"
        @submit.prevent="confirmUnblock($event.target, <?php echo $e(json_encode($name)); ?>)">
        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="desbloquear">
        <input type="hidden" name="idx" value="<?php echo $lastIdx; ?>">
        <button type="submit" class="btn btn-sm btn-outline-success" title="Desbloquear cliente">
            <i class="bi bi-check-circle me-1" aria-hidden="true"></i> Desbloquear
        </button>
    </form>
<?php endif; ?>
```

**Nota sobre o `@submit.prevent`**: o form de bloquear usa
`confirmBlock(...)` (Alpine, controller `customers`). Se **não existir** um
`confirmUnblock` correspondente no JS do Alpine controller `customers`, o
`@submit.prevent` cancelaria o submit e nada aconteceria. Verifique antes:
```bash
grep -rn "confirmBlock\|confirmUnblock" manager/public_html/assets manager/public_html/ui 2>/dev/null
```
- Se `confirmUnblock` **já existe** → use-o como acima.
- Se **não existe** → você tem duas opções seguras; **escolha a (i)**, que não mexe
  em JS: **(i)** submeter direto sem confirmação JS — troque a linha do form para
  só `<form method="POST" action="...">` (sem `@submit.prevent`). O desbloqueio é
  reversível (é só re-bloquear), então dispensar o `confirm()` é aceitável.
  **(ii)** Se preferir manter a confirmação e o JS do controller `customers` for
  óbvio de estender, adicione um `confirmUnblock` espelhando `confirmBlock` — mas
  isso amplia o escopo para o JS; se o JS não for trivial, **fique na opção (i)**.

**Verify**: abra a página de um cliente bloqueado no navegador (ou confira via teste
de view no Step 6) e confirme que o botão "Desbloquear" aparece e some quando não
bloqueado.

### Step 4: View de lista — trocar botão desabilitado por Desbloquear

Em `manager/public_html/ui/page/customers.php`, o ramo `else` (cliente bloqueado,
linhas ~200-203) hoje é um botão desabilitado. Troque-o por um form de desbloqueio,
mesmo esquema do Step 3 (usando `$lastOrderIdx` como idx e a mesma decisão sobre
`@submit.prevent`/opção (i)):

```php
<?php else: ?>
    <form method="POST" action="<?php echo $e($GLOBALS['customers_url']); ?>">
        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="desbloquear">
        <input type="hidden" name="idx" value="<?php echo $lastOrderIdx; ?>">
        <button type="submit" class="btn btn-sm btn-action-edit" title="Desbloquear cliente">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <span class="customers-action-label">Desbloquear</span>
        </button>
    </form>
<?php endif; ?>
```

Mantenha o badge "Bloqueado" da linha 163 (ele indica o estado; o botão faz a ação).

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.

### Step 5: Confirmar que nada em lib/model divergiu

Como este plano só toca controller/views/migration (não lib nem model), o guard de
sync deve passar limpo:
```bash
bin/check-shared-sync.sh
```
**Verify**: exit 0. Se acusar divergência → você tocou um arquivo compartilhado sem
querer → **STOP** e reverta essa mudança.

### Step 6: Testes

Ver "Test plan". Rode até tudo verde.

## Test plan

Estenda `manager/tests/CustomerBlockTest.php` (já cobre bloqueio, estende
`DBTestCase` com rollback automático por teste). Adicione métodos cobrindo o
desbloqueio e o re-bloqueio (o cenário que a migration 038 conserta). Use os helpers
`block()`/`isBlocked()`/`makeOrder()` que já existem no arquivo como padrão.

Adicione um helper de desbloqueio que reproduz o `remove()` filtrado do controller, e
testes:

```php
private function unblock(string $mail, string $cpf, string $phone): int
{
    $model = new blocked_customers_model();
    $model->set_filter(
        [
            " active = 'yes' ",
            " ( customer_mail = ? OR ( customer_cpf <> '' AND customer_cpf = ? ) OR ( customer_phone <> '' AND customer_phone = ? ) ) ",
        ],
        [$mail, $cpf, $phone]
    );
    $stmt = $model->remove();
    return $stmt ? $stmt->rowCount() : 0;
}

public function testUnblockSoftDeletesAndCheckoutAcceptsAgain(): void
{
    $mail = 'ub_' . uniqid() . '@example.com';
    $this->block($mail, '', '');
    $this->assertTrue($this->isBlocked($mail, '', ''));

    $affected = $this->unblock($mail, '', '');
    $this->assertSame(1, $affected, 'Desbloqueio deve soft-deletar 1 linha');
    $this->assertFalse($this->isBlocked($mail, '', ''), 'Após desbloquear, checkout aceita');
}

public function testReblockAfterUnblockSucceeds(): void
{
    // Este é o cenário que a migration 038 conserta: sem o índice escopado, o
    // segundo bloqueio bateria no UNIQUE da linha soft-deletada e falharia.
    $mail = 'rb_' . uniqid() . '@example.com';
    $this->block($mail, '', '');
    $this->unblock($mail, '', '');

    $id = $this->block($mail, '', '');   // não deve lançar (índice escopado a active)
    $this->assertGreaterThan(0, $id);
    $this->assertTrue($this->isBlocked($mail, '', ''), 'Re-bloqueio volta a barrar o checkout');
}

public function testUnblockMatchesByCpfOrPhone(): void
{
    $mail  = 'ubm_' . uniqid() . '@example.com';
    $cpf   = substr((string) mt_rand(10000000000, 99999999999), 0, 11);
    $phone = '1198' . mt_rand(1000000, 9999999);
    $this->block($mail, $cpf, $phone);

    // Desbloqueia casando só por CPF (mail/phone diferentes) — deve soft-deletar a linha.
    $affected = $this->unblock('outro@example.com', $cpf, '11000000000');
    $this->assertGreaterThanOrEqual(1, $affected);
    $this->assertFalse($this->isBlocked($mail, $cpf, $phone));
}
```

**IMPORTANTE — o helper `block()` existente**: ele faz `populate()` + `save()`
direto no model, que **não** passa pelo `INSERT...WHERE NOT EXISTS` do controller.
No `testReblockAfterUnblockSucceeds`, o 2º `block()` insere uma linha `active='yes'`
nova com o mesmo mail enquanto a antiga está `active='no'` — é exatamente o INSERT
que o índice funcional precisa permitir. Se esse teste falhar com erro de constraint,
a migration 038 não está aplicada ou está errada → **STOP** e revise o Step 1.

**Verify**:
```bash
cd manager && php app/inc/lib/vendor/bin/phpunit --filter CustomerBlockTest
```
→ todos passam, incluindo os 3 novos.

Depois rode a suíte inteira do manager:
```bash
cd manager && php app/inc/lib/vendor/bin/phpunit
```
→ todos passam.

> Nota de baseline: `site/tests/CheckoutPaymentChargeTest.php` tem 4 erros
> pré-existentes ("Database error" no save de `pix_charges`) **não relacionados** a
> este plano — se aparecerem só esses, não é regressão sua. Qualquer falha nova em
> testes de cliente/bloqueio/checkout, sim.

## Done criteria

Todas devem valer:

- [ ] `migrations/038_scope_blocked_customers_unique_active.sql` existe e é idempotente (runner 2x sem erro).
- [ ] `SHOW INDEX FROM blocked_customers` tem `uniq_blocked_customers_active_mail` (Non_unique=0) e **não** tem `uniq_blocked_customers_mail`.
- [ ] `customers_controller::action()` aceita `action='desbloquear'` e faz soft-delete via `remove()`.
- [ ] `customer_detail.php` mostra "Desbloquear" quando `$isBlocked`; `customers.php` mostra "Desbloquear" no lugar do botão desabilitado.
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → exit 0.
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpunit --filter CustomerBlockTest` → passa, com os 3 testes novos (`testUnblockSoftDeletesAndCheckoutAcceptsAgain`, `testReblockAfterUnblockSucceeds`, `testUnblockMatchesByCpfOrPhone`).
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpunit` → todos passam (fora o baseline conhecido do site, que nem roda aqui).
- [ ] `bin/check-shared-sync.sh` → exit 0.
- [ ] `git status`: só os 5 arquivos em escopo modificados/criados.
- [ ] Status atualizado em `plans/README.md`; marcar TODOS.md #3 como resolvido por este plano.

## STOP conditions

Pare e reporte (não improvise) se:

- O pré-flight do Step 1 achar dois `active='yes'` com o mesmo `customer_mail`.
- O MySQL do ambiente for < 8.0.13 e recusar o índice funcional (erro de sintaxe no
  `((IF(...)))`) — o `docker-compose` do projeto é MySQL 8.0, então não deveria; se
  acontecer, reporte (alternativa seria uma coluna gerada + UNIQUE, fora deste plano).
- `testReblockAfterUnblockSucceeds` falhar com erro de constraint — a migration 038
  não pegou.
- Você concluir que precisa alterar `blocked_customers_model.php`, `DOLModel.php` ou
  o `checkout_controller.php` — não deveria; se parecer necessário, reporte.
- `bin/check-shared-sync.sh` acusar divergência lib/model.
- O JS do Alpine controller `customers` for necessário e não-trivial de estender —
  fique na opção (i) do Step 3 (submit direto, sem `confirm`); se mesmo assim
  parecer que precisa de JS novo, reporte.

## Maintenance notes

- Para quem revisa o PR:
  - Confirmar que o **INSERT de bloquear não mudou** (o índice funcional é quem
    permite o re-bloqueio; o controller de bloquear continua igual).
  - Confirmar que o soft-delete casa pelos **três** identificadores (mail/cpf/phone),
    igual ao match de bloquear — desbloquear parcial (só por mail) deixaria o cliente
    ainda barrado por CPF/telefone.
  - Confirmar a sintaxe das aspas escapadas (`''yes''`) na migration.
- Interação futura: se um dia a blocklist ganhar histórico visível ("bloqueado em X,
  desbloqueado em Y"), as linhas `active='no'` já guardam `removed_at`/`removed_by` —
  a base para isso já existe.
- Este plano **fecha o TODOS.md #3** (UNIQUE não escopado). Ao concluir, remova ou
  marque como resolvido esse item no `TODOS.md`.
