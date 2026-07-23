# Plan 019: Filtros por telefone e CPF na lista de pedidos do manager

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- manager/app/inc/controller/orders_controller.php manager/public_html/ui/page/orders.php manager/tests/OrdersFilterTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S/M
- **Risk**: LOW
- **Depends on**: none (mas o plano 022 — remoção de `/clientes` — depende DESTE)
- **Category**: direction (gap de escopo)
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

O escopo do produto pede, textualmente, "lista de pedidos com filtros por telefone e CPF". Hoje `/pedidos` filtra **só por status**; a busca por telefone/CPF vive numa tela separada (`/clientes`) que será removida (plano 022) por redundância. Este plano entrega o requisito no lugar onde o dono pediu — a lista de pedidos — usando as colunas denormalizadas que já existem (`orders.customer_phone`, `orders.customer_cpf`). O export CSV herda os filtros automaticamente porque index e export compartilham `buildFilter()`.

## Current state

- `manager/app/inc/controller/orders_controller.php:35-43` — único ponto de verdade do filtro (o docblock acima dele já manda estender aqui, nunca inline):

```php
private function buildFilter(array $info): array
{
    $statusParam = $info['get']['status'] ?? '';
    $statusParam = is_string($statusParam) ? trim($statusParam) : '';
    $status      = in_array($statusParam, self::VALID_STATUSES, true) ? $statusParam : null;
    if ($status !== null) {
        return [[" active = 'yes' ", " status = ? "], [$status]];
    }
    return [[" active = 'yes' "], []];
}
```

- `orders_controller::index()` (linhas ~45-92): usa `buildFilter()` para a listagem, **mas a query de COUNT é SQL cru separado** que só conhece `status`:

```php
if ($status !== null) {
    $countStmt = $model->execute_raw_prepared(
        "SELECT COUNT(*) AS total FROM orders WHERE active = 'yes' AND status = ?", [$status]);
} else {
    $countStmt = $model->execute_raw_prepared("SELECT COUNT(*) AS total FROM orders WHERE active = 'yes'");
}
```

- `orders_controller::export()` (linhas ~93-131): usa `buildFilter()` + `set_paginate([0, self::EXPORT_ROW_LIMIT])` — herda qualquer condição nova sem mudança.
- `manager/public_html/ui/page/orders.php:86-96` — form de filtro atual: um `<select name="status">` + link de export que propaga só `?status=`. Os links de paginação (linha ~162) propagam só `page` + `status` via `set_url()`.
- Colunas disponíveis: `orders.customer_phone` (migration `012`, formato de armazenamento **não normalizado garantido** — ver Step 1), `orders.customer_cpf` (migration `015`, `CHAR(11)`, dígitos — o checkout valida `ctype_digit` + 11 dígitos antes de gravar). Índices existentes em orders: nenhum sobre `customer_phone`/`customer_cpf` (029 cobre só `customer_mail`); volume atual é baixo — não crie índice neste plano.
- Convenção: input de usuário SEMPRE via `?` bound params (`set_filter([conds], [params])`).
- O site público consulta telefone com `RIGHT(customer_phone, 4)` (`site/app/inc/controller/track_order_controller.php`) — evidência de que `customer_phone` é armazenado como dígitos.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPUnit manager (filtro) | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/manager/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/manager/phpunit.xml --filter OrdersFilterTest` | todos passam |
| PHPUnit manager (full) | mesmo comando sem `--filter` | todos passam |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `manager/app/inc/controller/orders_controller.php`
- `manager/public_html/ui/page/orders.php`
- `manager/tests/OrdersFilterTest.php` (estender)

**Out of scope** (NÃO tocar):
- `site/` inteiro; `orders_model.php` (2 cópias); migrations (nenhum índice novo agora).
- `/clientes` (`customers_controller.php`) — remoção é o plano 022, não este.
- `order_detail.php` / `show()` / `ship()`.

## Git workflow

- Branch: `advisor/019-filtros-pedidos`
- Commits em PT-BR, Conventional Commits.
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Confirmar formato de `customer_phone` no banco

`docker exec mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" db_infinnityimportacao -e "SELECT customer_phone FROM orders WHERE active='yes' LIMIT 10;"` (senha em `docker/.env`; não copie o valor para nenhum arquivo).

Esperado: só dígitos (ex. `11987654321`). Se aparecer formatação (`(11) 9...`), o Step 2 deve comparar com `REPLACE`-chain — mas trate como STOP e reporte primeiro, porque o `track_order_controller` do site já assume dígitos e estaria quebrado também.

### Step 2: Estender `buildFilter()`

Substitua o corpo por uma versão que acumula condições (status + cpf + telefone combináveis):

```php
private function buildFilter(array $info): array
{
    $conds  = [" active = 'yes' "];
    $params = [];

    $statusParam = $info['get']['status'] ?? '';
    $statusParam = is_string($statusParam) ? trim($statusParam) : '';
    if (in_array($statusParam, self::VALID_STATUSES, true)) {
        $conds[]  = " status = ? ";
        $params[] = $statusParam;
    }

    $cpfParam = $info['get']['cpf'] ?? '';
    $cpf = is_string($cpfParam) ? preg_replace('/\D+/', '', $cpfParam) : '';
    if (strlen($cpf) === 11) {
        $conds[]  = " customer_cpf = ? ";
        $params[] = $cpf;
    }

    $phoneParam = $info['get']['telefone'] ?? '';
    $phone = is_string($phoneParam) ? preg_replace('/\D+/', '', $phoneParam) : '';
    if (strlen($phone) >= 4) {
        $conds[]  = " customer_phone LIKE ? ";
        $params[] = '%' . $phone;
    }

    return [$conds, $params];
}
```

Racional: CPF só filtra completo (11 dígitos — coluna é `CHAR(11)` exata); telefone filtra por sufixo com mínimo de 4 dígitos (aceita tanto o número completo quanto os 4 finais que o admin vê no site). Input mascarado (`(11) 98765-4321`, `123.456.789-09`) funciona porque tudo é normalizado para dígitos.

**Verify**: PHPStan manager → `[OK]`.

### Step 3: Fazer o COUNT do `index()` usar o mesmo filtro

Troque o if/else de COUNT cru por uma única query montada a partir de `buildFilter()`:

```php
[$conds, $params] = $this->buildFilter($info);
$countStmt = $model->execute_raw_prepared(
    "SELECT COUNT(*) AS total FROM orders WHERE " . implode(" AND ", $conds),
    $params
);
```

E reuse `[$conds, $params]` já computados na listagem logo abaixo (elimine a chamada duplicada a `buildFilter()` e as variáveis `$statusParam`/`$status` locais que sobrarem — mantenha `$currentStatus` se a view precisar, ver Step 4). As strings de `$conds` vêm todas do próprio `buildFilter` (nunca de input), então o `implode` é seguro; os valores continuam em `?`.

**Verify**: PHPStan manager → `[OK]`.

### Step 4: Inputs na view + propagação em paginação e export

Em `manager/public_html/ui/page/orders.php`:

1. No form de filtro (junto ao `<select name="status">`, linha ~87): adicione 2 inputs de texto `name="cpf"` e `name="telefone"` (placeholder "CPF" / "Telefone (mín. 4 dígitos)"), pré-preenchidos com o valor atual escapado (`htmlspecialchars($_GET['cpf'] ?? '', ENT_QUOTES, 'UTF-8')` — mesma disciplina de escape do resto da view).
2. Link de export (linha ~96): propague `cpf` e `telefone` além de `status` (monte com `set_url($GLOBALS['orders_export_url'], $queryParams)` como a paginação já faz, em vez da concatenação manual atual).
3. Links de paginação (linha ~162): inclua `cpf`/`telefone` no array passado a `set_url()` quando não vazios.

Defina no controller (fim do `index()`, antes dos includes) as variáveis que a view usa (ex. `$currentCpf`, `$currentPhone` já normalizados) em vez de ler `$_GET` cru na view, seguindo o padrão de `$currentStatus`.

**Verify**: contra o stack vivo, logado no manager: `/pedidos?cpf=...` de um pedido real → só pedidos daquele CPF; `/pedidos?telefone=<4 últimos>` → pedidos com aquele sufixo; combinação com `status` funciona; export baixa CSV filtrado.

### Step 5: Testes

Estenda `manager/tests/OrdersFilterTest.php` (padrão já existente no arquivo — fixtures via model + chamada de `buildFilter` via `ReflectionMethod`, mesmo mecanismo usado em `CustomerUpsertTest` para métodos privados):

- CPF completo com máscara (`123.456.789-09`) → normaliza e filtra.
- CPF incompleto (<11 dígitos) → ignorado (sem condição extra).
- Telefone 4 dígitos → sufixo LIKE bate.
- Telefone <4 dígitos → ignorado.
- Status + CPF combinados → ambas as condições presentes.
- Array em vez de string (`?cpf[]=x`) → ignorado sem erro (guard `is_string`, mesmo endurecimento que o plano 014 fez em `?q[]=`).

**Verify**: `--filter OrdersFilterTest` → todos passam; suíte completa do manager → verde.

## Test plan

Ver Step 5. Padrão estrutural: o próprio `OrdersFilterTest.php` existente.

## Done criteria

- [ ] PHPStan manager `[OK]`
- [ ] PHPUnit manager completo verde, `OrdersFilterTest` com os 6 casos novos
- [ ] `grep -c "customer_cpf = ?" manager/app/inc/controller/orders_controller.php` → 1
- [ ] COUNT e listagem usam o MESMO `buildFilter()` (`grep -c "SELECT COUNT" manager/app/inc/controller/orders_controller.php` → 1)
- [ ] Verificação manual do Step 4 feita contra o stack vivo
- [ ] `git status` sem arquivos fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- `customer_phone` no banco NÃO é dígitos-only (Step 1).
- Excertos do "Current state" não batem (drift).
- Qualquer necessidade de tocar `orders_model.php` (indicaria que `customer_cpf`/`customer_phone` não estão no `$field` do model — eles não precisam estar para o WHERE, só para SELECT; se um teste exigir, reporte).

## Maintenance notes

- Se o volume de pedidos crescer, `customer_phone LIKE '%...'` não usa índice (sufixo). Alternativa futura: coluna gerada `phone_last4` indexada. Não fazer agora.
- O plano 022 (remover `/clientes`) usa estes filtros como justificativa — este plano precisa estar mergeado antes.
- Revisor: conferir que a view escapa os valores dos inputs e que nenhum `$_GET` cru chega ao SQL.
