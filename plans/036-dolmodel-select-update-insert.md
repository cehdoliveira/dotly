# Plan 036: Migrar `execute_raw_prepared` para `select()`/`update()`/`insert()` do DOLModel

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 54ad532..HEAD -- site/app manager/app`
> ATENÇÃO: este plano foi escrito contra o commit `54ad532` **mais mudanças
> não-commitadas** em `site/app/inc/lib/DOLModel.php` e
> `manager/app/inc/lib/DOLModel.php` (os métodos `select()` e `update()` base
> já existem lá, só na working tree). Antes de começar, confirme:
> `grep -n "public function select" site/app/inc/lib/DOLModel.php` → deve
> retornar 1 linha. Se não retornar, STOP (você está numa árvore sem as
> mudanças base — ex. um worktree novo criado a partir do HEAD).

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MED (toca caminhos de pagamento: checkout, OrderReconciler, OrderExpirer)
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `54ad532` + DOLModel.php uncommitted (2026-07-22)

## Why this matters

Convenção decidida pelo dono do repo: SQL explícito não deve viver em
controllers/libs — todo acesso passa pelo `DOLModel` via `select()`/`update()`
(já criados) e um novo `insert()`. Hoje há 47 chamadas de
`execute_raw_prepared()` espalhadas por controllers e libs de produção. 13
delas (JOINs, INSERTs, UPDATE multi-tabela) não cabem nas assinaturas atuais,
então este plano primeiro **estende** os helpers (alias/join opcionais +
`insert()`), depois migra todos os call sites de produção. Ao final,
`execute_raw_prepared` permanece no DOLModel **apenas para uso em testes**
(decisão do dono: fixtures de teste continuam raw).

## Current state

### Arquivos e papéis

Framework (2 cópias byte-idênticas — TODA mudança vai nas duas; o guard
`bin/check-shared-sync.sh` bloqueia commit se divergirem):
- `site/app/inc/lib/DOLModel.php` e `manager/app/inc/lib/DOLModel.php` —
  ORM; `select()` (linha ~57) e `update()` (linha ~65) já existem na working
  tree; `execute_raw_prepared()` na linha ~298; `save()` (linha ~86) é o
  padrão a imitar no `insert()`.

Libs compartilhadas (também byte-idênticas entre `site/` e `manager/`, mesmo
guard): `OrderPricing.php`, `OrderMailQueue.php`, `OrderExpirer.php`,
`OrderReconciler.php`, `GatewayRouter.php` — todas em `app/inc/lib/`.

Controllers (por ambiente, podem divergir):
- `site/app/inc/controller/checkout_controller.php` — 3 chamadas (linhas 127, 402, 560)
- `site/app/inc/controller/site_controller.php` — 1 chamada (linha 45)
- `manager/app/inc/controller/config_controller.php` — 2 (linhas 40, 81)
- `manager/app/inc/controller/customers_controller.php` — 7 (216, 236, 294, 314, 323, 438, 462)
- `manager/app/inc/controller/orders_controller.php` — 2 (301, 368)
- `manager/app/inc/controller/products_controller.php` — 2 (55, 68)
- `manager/app/inc/controller/site_controller.php` — 4 (70, 123, 150, 212)

Models existentes em `app/inc/model/` (ambos os envs): `blocked_customers_model`,
`email_queue_model`, `order_items_model`, `orders_model`, `payment_gateways_model`,
`pix_charges_model`, `product_images_model`, `products_model`, `profiles_model`,
`settings_model`, `users_model`. Todos estendem `DOLModel` (construtor recebe o
nome da tabela). Todas as tabelas alvo têm colunas `modified_at`/`modified_by`
e `created_at`/`created_by` (verificado nas migrations 002–034).

### Assinaturas atuais dos helpers (working tree, idênticas nas 2 cópias)

```php
// site/app/inc/lib/DOLModel.php:57
public function select(array $fields = array(), ?string $where = null, ?array $params = null): \PDOStatement
{
	return $this->con->executePrepared(
		sprintf("SELECT %s FROM %s %s", implode(", ", $fields), $this->table, $where ?? ''),
		$params ?? []
	);
}

// site/app/inc/lib/DOLModel.php:65
public function update(array $fields = array(), ?string $where = null, ?array $params = null): \PDOStatement
{
	if ($where === null || trim($where) === '') {
		throw new \InvalidArgumentException('update() requires a WHERE clause; use "WHERE 1=1" to affect all rows intentionally.');
	}
	if (empty($fields)) {
		throw new \InvalidArgumentException('update() requires at least one field to set.');
	}
	$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
		? $_SESSION[constant("cAppKey")]["credential"]["idx"]
		: 0;

	$assignments = array_merge([" modified_at = now() ", " modified_by = ? "], $fields);
	$bindParams  = array_merge([$userId], $params ?? []);

	return $this->con->executePrepared(
		sprintf("UPDATE %s SET %s %s", $this->table, implode(", ", $assignments), $where),
		$bindParams
	);
}
```

### Convenções do repo que se aplicam

- Indentação: **tabs** no DOLModel; 4 espaços nos controllers. Imite o arquivo.
- Comentários em PT-BR, sem acento nos arquivos de lib antigos (`nao`, `sao`) —
  imite o estilo do arquivo que estiver editando.
- Soft-delete universal (`active = 'yes'/'no'`); nunca `DELETE FROM`.
- Transação global por request (`localPDO`); controllers não chamam
  commit/rollback — exceto os jobs CLI (OrderReconciler/OrderExpirer) que
  fazem commit por item deliberadamente. NÃO mexa nesses commits.
- `basic_redir($url)` dentro de controller encerra o request (commit+redirect).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Sync guard | `bin/check-shared-sync.sh` | exit 0, sem diff listado |
| Testes site | `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit` | ver nota abaixo |
| Testes manager | `docker exec -w /var/www/infinnityimportacao/manager infinnityimportacao php app/inc/lib/vendor/bin/phpunit` | all pass |
| Gate de migração | `grep -rn "execute_raw_prepared" site/app manager/app --include="*.php" \| grep -v "DOLModel.php"` | vazio (exit 1) |

NÃO use `bin/test.sh` — ele chama o PHPUnit sem `-w` e os testes não rodam
(imprime o help e sai verde). Use os comandos `docker exec -w` acima.

**Falha pré-existente conhecida**: `CheckoutPaymentChargeTest` no env `site`
tem 4 erros "Database error" que já existiam antes deste plano. Se APENAS
esses 4 falharem, considere a suíte verde. Qualquer outra falha é regressão sua.

## Scope

**In scope** (únicos arquivos que você pode modificar):
- `site/app/inc/lib/DOLModel.php` + `manager/app/inc/lib/DOLModel.php`
- `site/app/inc/lib/{OrderPricing,OrderMailQueue,OrderExpirer,OrderReconciler,GatewayRouter}.php` + as 5 cópias em `manager/app/inc/lib/`
- `site/app/inc/controller/checkout_controller.php`
- `site/app/inc/controller/site_controller.php`
- `manager/app/inc/controller/{config,customers,orders,products,site}_controller.php`
- `site/tests/DOLModelQueryHelpersTest.php` (criar)
- `plans/README.md` (linha de status)

**Out of scope** (NÃO toque, mesmo parecendo relacionado):
- `site/tests/*` e `manager/tests/*` existentes — as chamadas
  `execute_raw_prepared` em testes ficam como estão (decisão do dono).
- O método `execute_raw_prepared()` no DOLModel — NÃO remova; testes dependem.
- `validate_csrf` / dispatcher / qualquer coisa de sessão.
- `save()`, `remove()`, `load_data()`, `attach*()`, `join()` do DOLModel.
- Migrations.

## Git workflow

- Branch: `advisor/036-dolmodel-select-update-insert` a partir da working tree
  atual (as mudanças base do DOLModel não estão commitadas — elas entram no
  primeiro commit deste plano).
- Commits em PT-BR, Conventional Commits (ex. do log: `fix: ajuste de planos`,
  `perf: indice composto (active,status) em pix_charges`). Sugestão: um commit
  para os helpers + testes (`feat: helpers select/update/insert no DOLModel`),
  um para libs compartilhadas, um para controllers
  (`refactor: migra execute_raw_prepared para helpers do DOLModel`).
- Habilite os hooks antes de commitar: `git config core.hooksPath .githooks`
  (pre-commit roda PHPStan + shared-sync).
- NÃO faça push nem abra PR sem instrução do operador.

## Regra de ouro — ordem de bind (leia antes de qualquer migração)

`localPDO::executePrepared` usa placeholders posicionais `?`. A ordem de bind
é a ordem em que os `?` aparecem no SQL final montado:

- **select**: `SELECT {fields} FROM {table}[ {alias}][ {join}] {where}` →
  params na ordem: `?` dos fields primeiro, depois `?` do where.
  **O `$join` de select() NUNCA pode conter `?`** (não há parâmetro para eles).
- **update**: `UPDATE {table}[ {alias}][ {join}] SET modified_at = now(),
  modified_by = ?, {fields} {where}` → bind = `[...$joinParams, $userId,
  ...$params]`. `?` no join casam com `$joinParams`; `?` em fields+where
  casam com `$params` (nessa ordem).
- **insert**: `INSERT INTO {table} SET col = ?, ..., created_at = now(),
  created_by = ? {suffix}` → bind = `[...valores, $userId]`.
  **O `$suffix` NUNCA pode conter `?`.**

## Steps

### Step 1: Estender `select()` com alias/join (nas 2 cópias do DOLModel)

Substitua o `select()` atual por:

```php
	/**
	 * SELECT na tabela do model. $alias apelida a tabela base; $join recebe a
	 * clausula de junção crua (sem placeholders). Placeholders (?) sao
	 * permitidos em $fields e $where e casam com $params na ordem em que
	 * aparecem no SQL.
	 */
	public function select(array $fields = array(), ?string $where = null, ?array $params = null, ?string $alias = null, ?string $join = null): \PDOStatement
	{
		if ($join !== null && str_contains($join, '?')) {
			throw new \InvalidArgumentException('select() nao suporta placeholders no $join; mova a condicao para o $where.');
		}
		return $this->con->executePrepared(
			sprintf(
				"SELECT %s FROM %s%s%s %s",
				implode(", ", $fields),
				$this->table,
				$alias !== null ? " " . $alias : '',
				$join !== null ? " " . $join : '',
				$where ?? ''
			),
			$params ?? []
		);
	}
```

Aplique EXATAMENTE o mesmo texto nas duas cópias (`site/...` e `manager/...`).
Indentação com tabs, como o restante do arquivo.

**Verify**: `bin/check-shared-sync.sh` → exit 0; `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → OK.

### Step 2: Estender `update()` com alias/join/joinParams (nas 2 cópias)

Substitua o `update()` atual por (guards existentes preservados):

```php
	/**
	 * UPDATE na tabela do model. Sempre carimba modified_at/modified_by.
	 * Ordem de bind: [...$joinParams, userId, ...$params] — `?` no $join
	 * casam com $joinParams; `?` em $fields e $where casam com $params.
	 */
	public function update(array $fields = array(), ?string $where = null, ?array $params = null, ?string $alias = null, ?string $join = null, ?array $joinParams = null): \PDOStatement
	{
		if ($where === null || trim($where) === '') {
			throw new \InvalidArgumentException('update() requires a WHERE clause; use "WHERE 1=1" to affect all rows intentionally.');
		}
		if (empty($fields)) {
			throw new \InvalidArgumentException('update() requires at least one field to set.');
		}
		$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
			? $_SESSION[constant("cAppKey")]["credential"]["idx"]
			: 0;

		$assignments = array_merge([" modified_at = now() ", " modified_by = ? "], $fields);
		$bindParams  = array_merge($joinParams ?? [], [$userId], $params ?? []);

		return $this->con->executePrepared(
			sprintf(
				"UPDATE %s%s%s SET %s %s",
				$this->table,
				$alias !== null ? " " . $alias : '',
				$join !== null ? " " . $join : '',
				implode(", ", $assignments),
				$where
			),
			$bindParams
		);
	}
```

**Verify**: `bin/check-shared-sync.sh` → exit 0; PHPStan de ambos os envs → OK.

### Step 3: Criar `insert()` (nas 2 cópias)

Adicione logo APÓS o `update()`, seguindo o padrão do ramo INSERT de `save()`
(linhas ~134–145):

```php
	/**
	 * INSERT na tabela do model. Sempre carimba created_at/created_by.
	 * $suffix e appendado cru ao final (ex. "ON DUPLICATE KEY UPDATE idx = idx")
	 * e nao pode conter placeholders. Retorna o lastInsertId (0 se o
	 * ON DUPLICATE nao inseriu linha nova).
	 */
	public function insert(array $data, ?string $suffix = null): int
	{
		if (empty($data)) {
			throw new \InvalidArgumentException('insert() requires at least one column.');
		}
		if ($suffix !== null && str_contains($suffix, '?')) {
			throw new \InvalidArgumentException('insert() nao suporta placeholders no $suffix.');
		}
		$userId = isset($_SESSION[constant("cAppKey")]["credential"]["idx"])
			? $_SESSION[constant("cAppKey")]["credential"]["idx"]
			: 0;

		$assignments = [];
		$params = [];
		foreach ($data as $col => $val) {
			$assignments[] = sprintf(" %s = ? ", $col);
			$params[] = $val;
		}
		$assignments[] = " created_at = now() ";
		$assignments[] = " created_by = ? ";
		$params[] = $userId;

		$sql = sprintf(
			"INSERT INTO %s SET %s %s",
			$this->table,
			implode(" , ", $assignments),
			$suffix ?? ''
		);
		$this->con->executePrepared($sql, $params);
		return (int)$this->con->lastInsertId();
	}
```

**Verify**: `bin/check-shared-sync.sh` → exit 0; PHPStan ambos → OK.

### Step 4: Testes dos helpers

Crie `site/tests/DOLModelQueryHelpersTest.php` estendendo `DBTestCase`
(transação + rollback automático por teste — veja `site/tests/DBTestCase.php`).
Use `site/tests/DolModelWriteTest.php` como padrão estrutural e o helper
`createProduct()` de `site/tests/OrderPricingTest.php:15-32` como modelo de
fixture. Casos mínimos:

1. `select()` simples: cria 2 products via `populate()+save()`, depois
   `(new products_model())->select([" idx ", " name "], "WHERE active = 'yes' AND idx IN (?, ?)", [$id1, $id2])`
   → `fetchAll` retorna 2 linhas.
2. `select()` com alias+join: cria 1 product + 1 linha em `product_images`
   (via `populate()+save()` de `product_images_model`, campos `products_id`,
   `path`), depois
   `(new product_images_model())->select([" pi.idx ", " p.name "], "WHERE pi.products_id = ?", [$id], "pi", "JOIN products p ON p.idx = pi.products_id")`
   → 1 linha com o `name` do produto.
3. `select()` com `?` no `$join` → `expectException(InvalidArgumentException::class)`.
4. `update()` simples: cria product com `stock = 100`, roda
   `update([" stock = stock - ? "], "WHERE idx = ?", [30, $id])` → rowCount 1;
   releia via `select()` → stock 70 e `modified_at` não nulo.
5. `update()` com join+joinParams (valida a ordem de bind
   `[joinParams, userId, params]`): cria product ($id) e uma product_images
   ligada; rode em `products_model`:
   `update([" stock = stock + img.bump " ], "WHERE 1=1", null, "p", "JOIN ( SELECT products_id, COUNT(*) AS bump FROM product_images WHERE products_id = ? GROUP BY products_id ) img ON img.products_id = p.idx", [$id])`
   → stock do produto aumenta em 1 e NENHUM outro produto muda.
6. `insert()` com suffix dedupe: `(new email_queue_model())->insert([...campos
   obrigatórios: 'active'=>'yes','event_type'=>'order_paid','orders_id'=>$fakeId,
   'to_mail'=>'t@t.com','subject'=>'s','body'=>'b','status'=>'pending',
   'attempts'=>0,'max_attempts'=>5], "ON DUPLICATE KEY UPDATE idx = idx")` duas
   vezes com o mesmo `orders_id`+`event_type` → segunda chamada não cria
   segunda linha (COUNT via `select()` = 1) e não lança exceção.

**Verify**: `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit --filter DOLModelQueryHelpersTest` → 6 testes, 0 falhas.

### Step 5: Migrar as libs compartilhadas (editar em `site/app/inc/lib/`, depois copiar para `manager/app/inc/lib/`)

Edite a cópia do `site/`, depois `cp` byte-idêntico para `manager/`. As linhas
citadas são da cópia do site (as do manager são as mesmas ± poucas linhas).

**5a. `OrderPricing.php:66`** — de:
```php
$stmt = $model->execute_raw_prepared(
    "SELECT skey, svalue FROM settings WHERE active = 'yes' AND skey IN ($placeholders)",
    $keys
);
```
para:
```php
$stmt = $model->select(
    [" skey ", " svalue "],
    "WHERE active = 'yes' AND skey IN ($placeholders)",
    $keys
);
```

**5b. `OrderPricing.php:117`** — mesma mecânica:
`$model->select([" idx "], "WHERE active = 'yes' AND is_infinity = 'yes' AND idx IN ($placeholders)", $productIds)`.

**5c. `OrderMailQueue.php:27`** — de INSERT raw com `NOW()` para:
```php
$m->insert([
    'active'       => 'yes',
    'event_type'   => $eventType,
    'orders_id'    => $orderId,
    'to_mail'      => $toMail,
    'subject'      => $subject,
    'body'         => $body,
    'status'       => 'pending',
    'attempts'     => 0,
    'max_attempts' => 5,
], "ON DUPLICATE KEY UPDATE idx = idx");
```
Mantenha o comentário sobre o UNIQUE(orders_id,event_type). Mudança de
comportamento aceita: `created_by` passa a ser gravado (0 em contexto CLI) em
vez de NULL.

**5d. `OrderExpirer.php:98`** — de:
```php
$result = $orderUpdate->execute_raw_prepared(
    "UPDATE orders SET status = 'expirado', modified_at = ? WHERE idx = ? AND status = 'aguardando_pagamento'",
    [$now, $ordersId]
);
```
para:
```php
$result = $orderUpdate->update(
    [" status = 'expirado' "],
    "WHERE idx = ? AND status = 'aguardando_pagamento'",
    [$ordersId]
);
```
O `modified_at` explícito cai — `update()` põe `now()` (equivalente; `$now` é
gerado no mesmo request). PRESERVE o guard `if ($result->rowCount() !== 1)`.

**5e. `OrderExpirer.php:107`** — troca de model (a query é em `order_items`,
não `orders`) + alias/join:
```php
$itemsModel = new order_items_model();
$sumResult = $itemsModel->select(
    [" COALESCE(SUM(IF(oi.variant = 'box', oi.qty * p.box_qty, oi.qty)), 0) AS units "],
    "WHERE oi.orders_id = ? AND oi.active = 'yes'",
    [$ordersId],
    "oi",
    "JOIN products p ON p.idx = oi.products_id"
);
```

**5f. `OrderExpirer.php:123`** — UPDATE multi-tabela. PRESERVE o comentário
grande sobre pré-agregação (linhas 116–122). De `$orderUpdate->execute_raw_prepared("UPDATE products p JOIN (...) agg ... SET p.stock = ...", [$ordersId])` para:
```php
$productsModel = new products_model();
$productsModel->update(
    [" p.stock = p.stock + agg.units "],
    "WHERE 1=1",
    null,
    "p",
    "JOIN (
         SELECT oi.products_id,
                SUM(IF(oi.variant = 'box', oi.qty * p2.box_qty, oi.qty)) AS units
           FROM order_items oi
           JOIN products p2 ON p2.idx = oi.products_id
          WHERE oi.orders_id = ? AND oi.active = 'yes'
          GROUP BY oi.products_id
        ) agg ON agg.products_id = p.idx",
    [$ordersId]
);
```
Nota: o `?` do subquery vai em `$joinParams` (6º argumento) — a ordem de bind
fica `[$ordersId, $userId]`, casando com a ordem dos `?` no SQL (join antes do
SET). `modified_at`/`modified_by` sem qualificador resolvem para `p` (a
derivada `agg` só tem `products_id`/`units` — sem ambiguidade). Mudança de
comportamento aceita: products restocados ganham carimbo modified_*.

**5g. `OrderExpirer.php:137`** — a query é em `pix_charges`, não `orders`:
```php
$chargesModel = new pix_charges_model();
$chargesModel->update(
    [" status = 'expirado' "],
    "WHERE orders_id = ? AND status = 'pendente' AND active = 'yes'",
    [$ordersId]
);
```
Se após 5d–5g a variável `$now` ficar sem uso no método, remova a atribuição
órfã (só se ficou órfã POR ESTAS mudanças).

**5h. `OrderReconciler.php:109`** — em `pix_charges_model` (já é):
```php
$stmt = $model->select(
    [" pc.idx AS charge_idx ", " pc.gateway_charge_id ", " pc.orders_id ", " pg.slug "],
    "WHERE pc.active = 'yes' AND pc.status = 'pendente'
        AND o.active = 'yes' AND o.status = 'aguardando_pagamento'
        AND pg.slug IN ($inPlaceholders)
        AND o.created_at >= ?
      ORDER BY pc.idx ASC
      LIMIT " . self::BATCH_SIZE,
    [...self::ELIGIBLE_SLUGS, $windowStart],
    "pc",
    "JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id JOIN orders o ON o.idx = pc.orders_id"
);
```

**5i. `OrderReconciler.php:192`** — mesma forma de 5h, trocando as condições
(`pc.status = 'expirado'`, `o.status = 'expirado'`, `o.modified_at >= ?`).

**5j. `OrderReconciler.php:221`**:
```php
$alertResult = $alertModel->update(
    [" status = 'erro' "],
    "WHERE idx = ? AND status = 'expirado'",
    [(int)$row['charge_idx']]
);
```
PRESERVE `if ($alertResult->rowCount() === 1)` e os commit/beginTransaction ao redor.

**5k. `OrderReconciler.php:272`**:
```php
$orderResult = $model->update(
    [" status = 'pago' ", " paid_at = ? "],
    "WHERE idx = ? AND status = 'aguardando_pagamento'",
    [$now, $ordersId]
);
```
PRESERVE o guard `rowCount() !== 1` e o comentário sobre a corrida com webhook.

**5l. `OrderReconciler.php:284`** — query em `pix_charges` via orders_model hoje:
```php
$chargesModel = new pix_charges_model();
$chargesModel->update(
    [" status = 'pago' ", " paid_at = ? "],
    "WHERE idx = ? AND status = 'pendente'",
    [$now, $chargeIdx]
);
```

**5m. `GatewayRouter.php:33`** — a query é em `pix_charges` (hoje roda no
`payment_gateways_model`):
```php
$chargesModel = new pix_charges_model();
$stmt = $chargesModel->select(
    [" c.payment_gateways_id AS g ", " COALESCE(SUM(o.total_cents), 0) AS mtd "],
    "WHERE c.active = 'yes' AND o.status = 'pago' AND o.paid_at >= ? GROUP BY c.payment_gateways_id",
    [$monthStart],
    "c",
    "JOIN orders o ON o.idx = c.orders_id"
);
```

Depois de TODAS as edições: copie cada lib editada para o manager, ex.:
`cp site/app/inc/lib/OrderExpirer.php manager/app/inc/lib/OrderExpirer.php` (idem para as outras 4).

**Verify**:
- `bin/check-shared-sync.sh` → exit 0
- PHPStan ambos → OK
- `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit --filter "OrderExpirerTest|OrderReconcilerTest|OrderPricingTest|GatewayRouterTest|EmailQueueDispatcherTest|WebhookEnqueueTest"` → all pass

### Step 6: Migrar controllers do site

**6a. `checkout_controller.php:127`** (baixa de estoque):
```php
$productsModel->update(
    [" stock = stock - ? "],
    "WHERE idx = ?",
    [$line['units_needed'], $line['products_id']]
);
```
Bind: `[userId(0 no site), units, idx]` — ordem correta. Mudança aceita:
estoque baixado passa a carimbar modified_* (antes não).

**6b. `checkout_controller.php:402`** (lock de estoque — `FOR UPDATE` viaja no `$where`):
```php
$stmt = $productsModel->select(
    [" idx ", " stock ", " price_unit_cents ", " box_qty "],
    "WHERE active = 'yes' AND idx IN ($placeholders) FOR UPDATE",
    $productIds
);
```

**6c. `checkout_controller.php:560`** (`isBlocked`):
```php
$stmt = $model->select(
    [" 1 "],
    "WHERE active = 'yes'
        AND ( customer_mail = ?
              OR ( customer_cpf <> '' AND customer_cpf = ? )
              OR ( customer_phone <> '' AND customer_phone = ? ) )
      LIMIT 1",
    [$mail, $cpf, $phone]
);
```

**6d. `site_controller.php:45`**:
```php
$categoriesStmt = $categoriesModel->select(
    [" DISTINCT category "],
    "WHERE active = 'yes' ORDER BY category ASC"
);
```

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → OK;
`docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit` → verde (exceto os 4 erros pré-existentes de `CheckoutPaymentChargeTest`).

### Step 7: Migrar controllers do manager

**7a. `config_controller.php:40`**:
```php
$countStmt = $usersModel->select(
    [" COUNT(*) AS total ", " SUM(active = 'yes') AS ativos ", " SUM(active = 'yes' AND enabled = 'yes') AS habilitados "],
    "WHERE idx > 0"
);
```

**7b. `config_controller.php:81`** — hoje roda no `payment_gateways_model`
(`$model`); a query é em `pix_charges`. Instancie
`$chargesModel = new pix_charges_model();` e aplique a MESMA forma do passo 5m
(alias `c`, join em `orders o`). Não reutilize `$model`.

**7c. `products_controller.php:55`**:
```php
$catStmt = (new products_model())->select(
    [" DISTINCT category "],
    "WHERE active = 'yes' AND category <> '' ORDER BY category ASC"
);
```

**7d. `products_controller.php:68`**:
```php
$countStmt = $model->select(
    [" COUNT(*) AS total "],
    "WHERE " . implode(" AND ", $conds),
    $params
);
```
(`$conds`/`$params` vêm do `buildFilter` — não mexa neles.)

**7e. `orders_controller.php:368`** — mesma forma de 7d, no `orders_model`.

**7f. `orders_controller.php:295-301`** (`attachGatewayNames`) — a query é em
`pix_charges`:
```php
$stmt = (new pix_charges_model())->select(
    [" pc.orders_id AS orders_id ", " pg.name AS gateway_name "],
    "WHERE pc.active = 'yes' AND pc.orders_id IN ({$placeholders})
      ORDER BY pc.orders_id ASC, pc.created_at ASC, pc.idx ASC",
    $ids,
    "pc",
    "INNER JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id"
);
```
A variável `$sql` intermediária morre — remova-a.

**7g. `customers_controller.php:216`** (count):
```php
$countStmt = $model->select(
    [" COUNT(*) AS total "],
    "WHERE {$where}",
    $params,
    "o",
    "INNER JOIN (
         SELECT customer_mail, MAX(idx) AS max_idx
           FROM orders
          WHERE active = 'yes'
          GROUP BY customer_mail
        ) g ON g.max_idx = o.idx"
);
```

**7h. `customers_controller.php:236`** (página) — mesma forma, com
`COUNT(*) AS orders_count` na subquery do join e o where estendido:
```php
$stmt = $model->select(
    [" o.idx AS last_order_idx ", " o.customer_name ", " o.customer_mail ",
     " o.customer_phone ", " o.customer_cpf ", " o.ship_city ", " o.ship_uf ",
     " o.created_at AS last_purchase ", " g.orders_count ",
     $this->blockedExistsSql('o') . " AS is_blocked",
     $this->blockedIdxSql('o') . " AS blocked_idx"],
    "WHERE {$where}
      ORDER BY {$orderExpr}
      LIMIT {$limit} OFFSET {$offset}",
    $params,
    "o",
    "INNER JOIN (
         SELECT customer_mail, MAX(idx) AS max_idx, COUNT(*) AS orders_count
           FROM orders
          WHERE active = 'yes'
          GROUP BY customer_mail
        ) g ON g.max_idx = o.idx"
);
```
(`$limit`/`$offset` já são ints cast no servidor — o comentário nas linhas
230–233 explica; preserve-o.)

**7i. `customers_controller.php:294`** (âncora — single table, subqueries no
field list):
```php
$anchorStmt = $model->select(
    [" customer_name ", " customer_mail ", " customer_phone ", " customer_cpf ", " ship_city ", " ship_uf ",
     $this->blockedExistsSql('orders') . " AS is_blocked",
     $this->blockedIdxSql('orders') . " AS blocked_idx"],
    "WHERE active = 'yes' AND idx = ? LIMIT 1",
    [$idx]
);
```

**7j. `customers_controller.php:314` e `:323`** — SELECTs single-table
diretos; mesma mecânica de 7i (histórico e resumo em `orders` por
`customer_mail`), mantendo ORDER BY no `$where`.

**7k. `customers_controller.php:436-483` (block)** — refactor do
`INSERT...SELECT...WHERE NOT EXISTS` para pré-checagem + `insert()`:

```php
$block = new blocked_customers_model();
try {
    // Pre-checagem substitui o WHERE NOT EXISTS do INSERT antigo. A corrida
    // entre dois "Bloquear" concorrentes por e-mail continua fechada pelo
    // indice unico funcional uniq_blocked_customers_active_mail (migration
    // 038) — a violacao cai no catch abaixo e vira "ja bloqueado". Para
    // colisao apenas por CPF/telefone a janela e um pouco maior que a do
    // INSERT...SELECT (duas statements em vez de uma), aceito por decisao
    // de convencao: admin-UI, corrida improvavel e inofensiva.
    $pre = $block->select(
        [" 1 "],
        "WHERE active = 'yes'
            AND ( customer_mail = ?
                  OR ( customer_cpf <> '' AND customer_cpf = ? )
                  OR ( customer_phone <> '' AND customer_phone = ? ) )
          LIMIT 1",
        [$mail, $cpf, $phone]
    );
    if ($pre->fetchColumn()) {
        $_SESSION["messages_app"]["info"] = ["Este cliente já está bloqueado."];
        basic_redir($customers_url);
    }

    $block->insert([
        'customer_mail'  => $mail,
        'customer_cpf'   => $cpf,
        'customer_phone' => $phone,
        'blocked_at'     => date('Y-m-d H:i:s'),
    ]);
} catch (RuntimeException $e) {
    // ... catch EXISTENTE inalterado, exceto o recheck que vira select():
```
No catch existente (linhas 450–478), troque só o `$recheck =
$block->execute_raw_prepared(...)` pela MESMA chamada `select()` da
pré-checagem. Mantenha logger, mensagens e `basic_redir` como estão.
**Remova** o bloco `if ($insert->rowCount() === 0) { ... }` (linhas 480–483) —
a pré-checagem o substitui — e a variável `$insert`. Substitua o comentário
antigo das linhas 430–435 pelo novo (inline no código acima). Mudança de
comportamento aceita: `blocked_customers` ganha `created_at`/`created_by`
preenchidos pelo `insert()` (antes só `blocked_at`).

**7l. `site_controller.php:70`** (dashboard) — single-table com `?` no field
list (permitido; bind na ordem fields→where):
```php
$stmt = $model->select(
    [" COALESCE(SUM(CASE WHEN status = 'pago' AND paid_at >= ? AND paid_at < ? THEN total_cents ELSE 0 END), 0) AS revenue_cents ",
     " COALESCE(SUM(CASE WHEN status = 'pago' AND paid_at >= ? AND paid_at < ? THEN 1 ELSE 0 END), 0) AS paid_orders ",
     " COALESCE(SUM(CASE WHEN status = 'aguardando_pagamento' AND expires_at > ? THEN 1 ELSE 0 END), 0) AS awaiting "],
    "WHERE active = 'yes'
        AND (
             (status = 'pago' AND paid_at >= ? AND paid_at < ?)
             OR (status = 'aguardando_pagamento' AND expires_at > ?)
        )",
    [$monthStart, $monthEnd, $monthStart, $monthEnd, $now, $monthStart, $monthEnd, $now]
);
```
A ordem do array de params é a MESMA de hoje — não a altere.

**7m. `site_controller.php:123`**:
```php
$stmt = $model->select(
    [" status ", " COUNT(*) AS total "],
    "WHERE active = 'yes' AND created_at >= ? GROUP BY status",
    [$since]
);
```

**7n. `site_controller.php:150`** (top produtos — `order_items_model` já é o model):
```php
$stmt = $model->select(
    [" oi.products_id ", " oi.product_name ", " SUM(oi.qty) AS total_qty "],
    "WHERE oi.active = 'yes' AND o.status = 'pago' AND o.paid_at >= ?
      GROUP BY oi.products_id, oi.product_name
      ORDER BY total_qty DESC
      LIMIT 5",
    [$since],
    "oi",
    "JOIN orders o ON o.idx = oi.orders_id AND o.active = 'yes'"
);
```

**7o. `site_controller.php:212`** — igual a 7b: instancie
`new pix_charges_model()` e use a forma do 5m.

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → OK;
`docker exec -w /var/www/infinnityimportacao/manager infinnityimportacao php app/inc/lib/vendor/bin/phpunit` → all pass (os testes do manager cobrem dashboard, customers, block/unblock e gateways — regressão aparece aqui).

### Step 8: Gate final

**Verify** (todos):
1. `grep -rn "execute_raw_prepared" site/app manager/app --include="*.php" | grep -v "DOLModel.php"` → vazio.
2. `bin/check-shared-sync.sh` → exit 0.
3. PHPStan ambos os envs → OK.
4. PHPUnit ambos os envs via `docker exec -w ...` → verde (modulo os 4 erros
   pré-existentes de `CheckoutPaymentChargeTest` no site).
5. `git status` → só arquivos do escopo modificados.

## Test plan

- Novo: `site/tests/DOLModelQueryHelpersTest.php` (6 casos do Step 4) — padrão
  estrutural: `DolModelWriteTest.php`; fixtures via `populate()+save()` como
  em `OrderPricingTest.php`.
- Regressão: suítes existentes já cobrem os caminhos migrados
  (`OrderExpirerTest`, `OrderReconcilerTest`, `OrderPricingTest`,
  `GatewayRouterTest`, `CheckoutStockTest`, `CheckoutCustomerBlockTest`,
  `CustomerBlockTest`, `DashboardCountsTest`, `SalesDashboardFailureTest`,
  `CustomersAggregateTest`, `GatewaysActionTest`). NÃO edite esses arquivos;
  se algum quebrar, o erro está na sua migração.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -rn "execute_raw_prepared" site/app manager/app --include="*.php" | grep -v DOLModel.php` → 0 matches
- [ ] `bin/check-shared-sync.sh` exit 0
- [ ] PHPStan `site` e `manager` → `[OK] No errors`
- [ ] PHPUnit site: verde exceto os 4 erros pré-existentes de `CheckoutPaymentChargeTest`; PHPUnit manager: 100% verde
- [ ] `DOLModelQueryHelpersTest` existe com ≥6 testes, todos passando
- [ ] Nenhum arquivo fora do escopo em `git status`
- [ ] Linha de status em `plans/README.md` atualizada

## STOP conditions

Stop and report back (do not improvise) if:

- `grep -n "public function select" site/app/inc/lib/DOLModel.php` não
  retorna nada (a base uncommitted não está na sua árvore).
- Qualquer excerpt de "Current state"/Steps não bate com o código vivo.
- Um call site que você encontrar usa placeholder `?` dentro do texto de JOIN
  de um SELECT (o helper não suporta; este plano verificou que nenhum dos 47
  usa — se aparecer, é drift).
- `bin/check-shared-sync.sh` falhar e você não conseguir tornar as cópias
  idênticas com um `cp` simples.
- Uma tabela alvo não tiver `modified_at`/`modified_by` ou
  `created_at`/`created_by` (erro SQL "Unknown column" num teste) — indica
  drift de schema em relação ao verificado neste plano.
- Os testes de `OrderReconcilerTest`/`OrderExpirerTest` falharem por diferença
  de contagem de `rowCount` após a migração — isso indicaria que o carimbo
  `modified_at = now()` mudou a semântica de linhas afetadas; NÃO "conserte"
  o teste, reporte.

## Maintenance notes

- **Contrato de bind é posicional**: quem adicionar um `?` num `$join` de
  `update()` deve lembrar que ele casa com `$joinParams`, que binda ANTES do
  `userId`. O reviewer deve conferir a ordem de params em todo diff que tocar
  `update(...join...)`.
- `update()`/`insert()` agora carimbam `modified_*`/`created_*` em caminhos
  que antes não carimbavam (baixa de estoque no checkout, restock do
  expirer, fila de e-mail, blocked_customers). Relatórios que filtram por
  `modified_at` de products passam a ver movimentação de estoque.
- O bloqueio de cliente (7k) trocou `INSERT...SELECT NOT EXISTS` por
  pré-checagem + `insert()`: a corrida por e-mail segue fechada pelo índice
  único da migration 038; a corrida por CPF/telefone-apenas tem janela
  ligeiramente maior (aceito). Se um dia o bloqueio virar API de alto volume,
  reavaliar (índices únicos adicionais em cpf/phone).
- `execute_raw_prepared` fica no DOLModel por causa dos testes. Se surgirem
  novas queries de produção que não caibam nos helpers, a resposta é estender
  o helper (como este plano fez), não voltar ao raw.
- Follow-up deferido: os testes existentes continuam usando
  `execute_raw_prepared` (decisão do dono — fixtures são SQL legítimo).
