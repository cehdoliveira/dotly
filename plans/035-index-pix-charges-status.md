# Plan 035: Índice composto `(active, status)` em `pix_charges`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat ae994b7..HEAD -- migrations site/app/inc/lib/OrderReconciler.php`
> If any in-scope path changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch involving `migrations/` (ex.: outra migration já criou um índice em
> `pix_charges.status`), treat it as a STOP condition.

## Status

- **Priority**: P4
- **Effort**: S (na prática XS — uma migration nova, zero PHP)
- **Risk**: LOW
- **Depends on**: none (plano 034 já shipou o `OrderReconciler` que se beneficia; este
  plano só adiciona o índice, funciona com ou sem 034 mergeado)
- **Category**: perf
- **Planned at**: commit `ae994b7`, 2026-07-20

## Why this matters

O plano 034 introduziu o `OrderReconciler`, que varre cobranças pendentes a cada tick
do cron com o filtro `WHERE pc.active = 'yes' AND pc.status = 'pendente'` sobre
`pix_charges`. A tabela `pix_charges` **não tem nenhum índice que cubra esse filtro** —
os únicos índices são `uq_pix_charge_gateway (payment_gateways_id, gateway_charge_id)`,
`idx_pix_charges_order (orders_id, active)` e `uq_pix_charge_transaction_nsu
(transaction_nsu)` (ver "Current state"). Nenhum começa por `active`+`status`.

Hoje o lado `orders` provavelmente dirige a query (o job filtra pedidos recentes numa
janela de 24h), então o impacto prático é baixo — por isso este é P4, robustez e não
urgência. Mas `pix_charges` é uma tabela que só cresce (uma linha por cobrança PIX,
para sempre, soft-delete), e conforme ela cresce o filtro `active='yes' AND
status='pendente'` sem índice tende a escanear mais linhas. Um índice composto
`(active, status)` é seguro barato: o job encontra as poucas cobranças pendentes
direto pelo índice em vez de varrer o histórico de cobranças já pagas/expiradas.

Este é o item **#5 de `TODOS.md`** ("Falta índice dedicado para pix_charges.status='pendente'").

## Current state

- `migrations/014_create_table_pix_charges.sql` — cria a tabela. Índices atuais
  (linhas 20-22):

  ```sql
  PRIMARY KEY (`idx`),
  UNIQUE KEY `uq_pix_charge_gateway` (`payment_gateways_id`, `gateway_charge_id`),
  KEY `idx_pix_charges_order` (`orders_id`, `active`)
  ```

  A coluna alvo (linha 13): `status ENUM('pendente','pago','expirado','erro') NOT NULL DEFAULT 'pendente'`
  e `active ENUM('yes','no') DEFAULT 'yes'` (linha 9).

- `migrations/042_add_transaction_nsu_to_pix_charges.sql` — última migration de
  `pix_charges`, adicionou `uq_pix_charge_transaction_nsu`. **Nenhuma** migration
  posterior a 014 adicionou índice em `status`.

- `site/app/inc/lib/OrderReconciler.php:110-121` — a query que se beneficia (o filtro
  de `pix_charges` está na linha 114):

  ```php
  $stmt = $model->execute_raw_prepared(
      "SELECT pc.idx AS charge_idx, pc.gateway_charge_id, pc.orders_id, pg.slug
         FROM pix_charges pc
         JOIN payment_gateways pg ON pg.idx = pc.payment_gateways_id
         JOIN orders o           ON o.idx  = pc.orders_id
        WHERE pc.active = 'yes' AND pc.status = 'pendente'
          AND o.active = 'yes' AND o.status = 'aguardando_pagamento'
          AND pg.slug IN ($inPlaceholders)
          AND o.created_at >= ?
        ORDER BY pc.idx ASC
        LIMIT " . self::BATCH_SIZE,
      [...self::ELIGIBLE_SLUGS, $windowStart]
  );
  ```

  (`OrderReconciler.php` é **read-only** neste plano — só contexto. Não editar.)

### Convenção de migration deste repo (seguir exatamente)

- Migrations em `migrations/`, numeradas `NNN_descricao.sql`, **uma transação por
  arquivo**, **idempotentes** (rastreadas em `migrations_log`, mas o próprio DDL também
  guarda contra re-execução via `information_schema`).
- O exemplar exato a copiar é **`migrations/037_add_composite_index_clientes_orders.sql`**
  — mesma forma (índice composto novo numa tabela existente, guard via
  `information_schema.STATISTICS`). Conteúdo dele hoje:

  ```sql
  SET @idx_exists := (
      SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'orders'
        AND INDEX_NAME = 'idx_orders_active_mail_idx'
  );
  SET @ddl := IF(
      @idx_exists = 0,
      'ALTER TABLE `orders` ADD KEY `idx_orders_active_mail_idx` (`active`, `customer_mail`, `idx`)',
      'DO 0'
  );
  PREPARE stmt FROM @ddl;
  EXECUTE stmt;
  DEALLOCATE PREPARE stmt;
  ```

  Comece o arquivo com um comentário `--` de 3-5 linhas explicando o porquê (mesmo
  padrão de 037/017/042): qual query se beneficia e por que o índice ajuda.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Rodar migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | imprime a 043 como executada na 1ª vez; 0 executadas na 2ª |
| Conferir índice | ver Step 2 (query `information_schema` via `docker exec ... mysql`) | 1 linha por coluna do índice |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |

> **Nota**: este repo **não** tem teste de PHPUnit para migrations — elas são validadas
> rodando `run_migrations.php` (idempotência = rodar 2x) e conferindo o índice em
> `information_schema`. Como o plano não toca PHP, PHPStan é só uma sanidade de que nada
> quebrou; o gate real é a migration aplicar e ser idempotente.

## Scope

**In scope** (o único arquivo a criar):
- `migrations/043_add_index_active_status_pix_charges.sql` (criar)

**Out of scope** (NÃO tocar, mesmo parecendo relacionado):
- `migrations/014_create_table_pix_charges.sql` — migration já aplicada em produção;
  índice novo vai numa migration nova, nunca editando a de criação.
- `site/app/inc/lib/OrderReconciler.php` e qualquer PHP — a query já está correta; o
  índice é transparente para ela, nenhuma linha de PHP muda.
- `app/inc/model/` / `app/inc/lib/` das duas cópias — a regra de shared-sync vale para
  código PHP do framework, **não** para `migrations/` (que é um diretório único na raiz,
  não duplicado por ambiente).

## Git workflow

- Branch: `advisor/035-index-pix-charges-status`
- 1 commit só; mensagem em PT-BR, Conventional Commits, ex.:
  `perf: indice composto (active,status) em pix_charges para o OrderReconciler`
- NÃO fazer push nem abrir PR a menos que o dono do repo peça.

## Steps

### Step 1: Criar a migration 043

Crie `migrations/043_add_index_active_status_pix_charges.sql` copiando a **forma** de
`migrations/037_add_composite_index_clientes_orders.sql`, trocando tabela/índice/colunas:

- Tabela: `pix_charges`
- Nome do índice: `idx_pix_charges_active_status`
- Colunas: `(active, status)` — nessa ordem (o filtro é `active = 'yes' AND status = 'pendente'`)
- Guard: `information_schema.STATISTICS` com `TABLE_NAME = 'pix_charges'` e
  `INDEX_NAME = 'idx_pix_charges_active_status'`
- Comentário `--` no topo explicando: o `OrderReconciler` (plano 034) filtra
  `pix_charges` por `active='yes' AND status='pendente'` a cada tick; sem este índice o
  filtro varre a tabela conforme ela cresce. Idempotência via `information_schema` (mesmo
  padrão de 037).

Resultado esperado (o `ALTER` que o guard vai executar):
`ALTER TABLE `pix_charges` ADD KEY `idx_pix_charges_active_status` (`active`, `status`)`

**Verify**: `test -f migrations/043_add_index_active_status_pix_charges.sql && echo OK`
→ `OK`. E `grep -c "idx_pix_charges_active_status" migrations/043_add_index_active_status_pix_charges.sql`
→ `2` (uma no guard, uma no `ALTER`).

### Step 2: Aplicar e conferir idempotência

Rode o runner de migrations **duas vezes** e confirme que o índice existe:

```bash
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php
```

- 1ª execução: a 043 aparece como executada (0 erros).
- 2ª execução: 0 migrations executadas (já registrada em `migrations_log`).

Confira o índice no schema (o nome do container MySQL/DB pode variar — use o mesmo que o
`docker-compose.yml` do projeto define; o app é `infinnityimportacao`, DB
`db_infinnityimportacao`):

```bash
docker exec infinnityimportacao php -r '$p=new PDO("mysql:host=db;dbname=db_infinnityimportacao","root",getenv("MYSQL_ROOT_PASSWORD")?:"root"); foreach($p->query("SHOW INDEX FROM pix_charges WHERE Key_name=\"idx_pix_charges_active_status\"") as $r){echo $r["Seq_in_index"].": ".$r["Column_name"]."\n";}'
```

**Verify**: a saída lista exatamente:
```
1: active
2: status
```
Se o `php -r` acima não conectar (credenciais/hosts diferentes do esperado), **não
improvise credenciais** — rode a mesma checagem via `run_migrations.php` output +
`SHOW INDEX` pelo cliente mysql que o projeto já usa, ou pare e reporte (STOP condition).

### Step 3: Sanidade PHPStan (não deve ter mudado nada)

```bash
cd site && php app/inc/lib/vendor/bin/phpstan analyse
```

**Verify**: `[OK] No errors`. (Nenhum PHP foi tocado, então isto é só confirmação de que
o working tree segue limpo.)

## Test plan

Não há teste automatizado a escrever — este repo valida migrations por execução
idempotente + inspeção de `information_schema` (mesmo padrão registrado nos `/ship` dos
planos 008/010, onde migrations foram testadas contra schema, não via PHPUnit). A
verificação do Step 2 (rodar 2x + `SHOW INDEX`) É o teste.

Se quiser evidência extra (opcional, não obrigatório para o done): rode um `EXPLAIN` da
query do reconciler e confirme que `pix_charges` passa a poder usar
`idx_pix_charges_active_status` — mas num banco de dev com poucas linhas o otimizador
pode legitimamente preferir outro plano, então **não** trate o `EXPLAIN` como gate.

## Done criteria

Machine-checkable. TODAS devem valer:

- [ ] `migrations/043_add_index_active_status_pix_charges.sql` existe e é o **único**
      arquivo novo/modificado (`git status --short` → só essa linha, fora `plans/`).
- [ ] `run_migrations.php` rodado 2x: 1ª aplica a 043, 2ª aplica 0 (idempotente).
- [ ] `SHOW INDEX FROM pix_charges` mostra `idx_pix_charges_active_status` com as
      colunas `active` (Seq 1) e `status` (Seq 2).
- [ ] `cd site && phpstan analyse` → `[OK] No errors`.
- [ ] Linha de status deste plano atualizada em `plans/README.md`.

## STOP conditions

Pare e reporte (não improvise) se:

- O drift check acusar que outra migration já criou algum índice começando por
  `pix_charges.status` (o filtro já pode estar coberto — não crie um índice redundante).
- Os índices atuais de `pix_charges` não baterem com o "Current state" (o schema
  divergiu desde este plano).
- A 043 falhar ao aplicar por qualquer erro de DDL (ex.: nome de índice já em uso).
- Você precisar tocar qualquer arquivo fora de `migrations/043...` para fazer o índice
  funcionar (não deveria — é DDL puro).

## Maintenance notes

Para quem revisar o PR / mantém isto depois:

- Índice barato e transparente: nenhum caminho de código muda, só o plano de execução da
  query do `OrderReconciler` (e de qualquer futura query que filtre `pix_charges` por
  `active`+`status`, ex.: um dashboard de cobranças pendentes).
- Custo de escrita: `pix_charges` recebe uma linha por cobrança e updates de `status`
  (pendente→pago/expirado/erro). Um índice a mais encarece marginalmente esses writes —
  desprezível no volume deste projeto (baixa taxa de checkout), mas é o trade-off padrão
  de qualquer índice.
- O que o revisor deve conferir: (1) a migration é idempotente (guard `information_schema`,
  não `IF NOT EXISTS` cru — MySQL 8 aceita `IF NOT EXISTS` em `CREATE INDEX` mas o repo
  padronizou o guard via `information_schema`, então siga 037); (2) ordem das colunas
  `(active, status)`, não `(status, active)` — `active` primeiro casa com o filtro de
  igualdade e com a convenção do repo (`idx_pix_charges_order` também começa por chave de
  igualdade).
- Follow-up correlato já registrado em `TODOS.md`: item #4 (orçamento de tempo por lote no
  `OrderReconciler`) é independente deste índice e não é resolvido por ele.
