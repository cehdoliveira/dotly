# Plan 029: índice composto em `orders` para o GROUP BY de `/clientes`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 6cd0d58..HEAD -- migrations manager/app/inc/controller/customers_controller.php`
> Se qualquer arquivo em escopo mudou, compare os trechos de "Current state" com
> o código vivo antes de prosseguir; se divergir, trate como STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW (adição de índice puro, sem mudança de código)
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `6cd0d58`, 2026-07-18

## Why this matters

`customers_controller::index()` roda um `GROUP BY customer_mail` (via subquery
`MAX(idx)`) sobre a tabela `orders` inteira, **duas vezes por requisição** (COUNT +
página), sem índice composto que suporte esse agrupamento. `orders` é tabela viva
que só cresce. Hoje `orders` tem `KEY idx_orders_customer_mail (customer_mail)`
(migration 029) — bom para o lookup de e-mail único (endpoint público de rastreio),
mas não cobre o `WHERE active='yes' ... GROUP BY customer_mail` do agregado de
`/clientes`, que faz varredura completa a cada carregamento/filtro/ordenação da
listagem. Um índice composto `(active, customer_mail, idx)` transforma o
`WHERE active='yes' GROUP BY customer_mail` num scan só do índice, com `MAX(idx)`
lido direto do próprio índice.

## Current state

- `manager/app/inc/controller/customers_controller.php:195-231` — as duas consultas
  do agregado. A subquery de agrupamento (idêntica no COUNT e na página) é:
  ```sql
  SELECT customer_mail, MAX(idx) AS max_idx, COUNT(*) AS orders_count
    FROM orders
   WHERE active = 'yes'
   GROUP BY customer_mail
  ```
  Um índice em `(active, customer_mail, idx)` é *covering* para essa subquery:
  filtra `active='yes'`, agrupa por `customer_mail` sem sort extra, e obtém
  `MAX(idx)` do fim de cada grupo no índice.
- `migrations/029_add_index_customer_mail_to_orders.sql` — criou
  `idx_orders_customer_mail (customer_mail)`. **Manter** — serve outro caminho (o
  rastreio público). O índice novo é adicional, não substituto.

### Convenção de migration a seguir

Numeração `NNN_desc.sql`, idempotente, uma transação por arquivo. Padrão de
`ADD KEY` idempotente: checar `information_schema.STATISTICS` e montar DDL
condicional com `PREPARE`/`EXECUTE`. Exemplar exato a copiar:
`migrations/029_add_index_customer_mail_to_orders.sql` (leia o arquivo inteiro).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Rodar migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | exit 0 |
| Ver índice | `docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e "SHOW INDEX FROM orders WHERE Key_name='idx_orders_active_mail_idx';"` | 3 linhas |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | todos passam |

(Se credenciais/DB name diferirem, pegue de `docker/docker-compose.yml` / `kernel.php`.)

## Scope

**In scope**:
- `migrations/037_add_composite_index_clientes_orders.sql` (criar)

**Out of scope** (NÃO tocar):
- `manager/app/inc/controller/customers_controller.php` — as queries não mudam; o
  índice as acelera sem reescrita.
- `migrations/029_add_index_customer_mail_to_orders.sql` — mantido, serve outro path.
- Qualquer outra migration passada.

## Git workflow

- Branch: `advisor/029-index-clientes`
- Commit único; PT-BR Conventional Commits, ex.:
  `perf: índice composto (active,customer_mail,idx) acelera GROUP BY de /clientes`
- Sem push/PR salvo instrução do operador.

## Steps

### Step 1: Escrever a migration 037

Crie `migrations/037_add_composite_index_clientes_orders.sql`. DDL alvo:

```sql
ALTER TABLE `orders` ADD KEY `idx_orders_active_mail_idx` (`active`, `customer_mail`, `idx`)
```

Estrutura obrigatória (comentário + checagem idempotente pelo nome do índice):

```sql
-- TODOS.md #2 / Plano 029: customers_controller::index() agrupa orders por
-- customer_mail (WHERE active='yes' GROUP BY customer_mail, com MAX(idx)) duas
-- vezes por request (COUNT + página). O índice existente idx_orders_customer_mail
-- (migration 029) cobre o lookup de e-mail único do rastreio público, mas não o
-- GROUP BY do agregado de /clientes — que faz table scan a cada carregamento.
-- Este índice composto (active, customer_mail, idx) é covering para a subquery de
-- agrupamento: filtra active, agrupa por customer_mail sem sort, MAX(idx) do índice.
--
-- Idempotência: checagem em information_schema (mesmo padrão de 029).

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

**Verify**: `git diff --stat` mostra só o arquivo novo.

### Step 2: Aplicar e confirmar

```bash
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php
docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e "SHOW INDEX FROM orders WHERE Key_name='idx_orders_active_mail_idx';"
```
**Verify**: o `SHOW INDEX` lista **3 linhas** (Seq_in_index 1/2/3 = active/customer_mail/idx).

### Step 3: (Opcional, recomendado) Confirmar que o planner usa o índice

```bash
docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e \
"EXPLAIN SELECT customer_mail, MAX(idx), COUNT(*) FROM orders WHERE active='yes' GROUP BY customer_mail;"
```
**Verify**: a coluna `key` mostra `idx_orders_active_mail_idx` e `Extra` menciona
`Using index` (covering). Se o otimizador escolher outro índice num banco quase
vazio (poucas linhas), não é bug — anote e siga; com volume real ele passa a usar
o composto.

### Step 4: Idempotência

Rode o runner de novo → exit 0, sem erro (`DO 0` na 2ª vez).

## Test plan

Sem teste PHP novo — é otimização transparente, não muda resultado de query. Rode a
suíte para garantir que nada quebrou:

- `cd manager && php app/inc/lib/vendor/bin/phpunit` → todos passam.
- Os testes de agregado relevantes (`CustomersAggregateTest.php`,
  `CustomersFilterSortTest.php`) devem continuar verdes — mesmos resultados, só
  mais rápidos.

## Done criteria

- [ ] `migrations/037_add_composite_index_clientes_orders.sql` existe, idempotente.
- [ ] `SHOW INDEX FROM orders WHERE Key_name='idx_orders_active_mail_idx'` → 3 linhas.
- [ ] Runner roda 2x sem erro.
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpunit` → todos passam.
- [ ] `git status` sem arquivos modificados fora do escopo.
- [ ] Status atualizado em `plans/README.md`.

## STOP conditions

- `run_migrations.php` falhar por qualquer motivo além de "índice já existe".
- Qualquer teste de `/clientes` passar a falhar (não deveria — o índice não muda
  resultados; se falhar, algo mais está errado).
- Parecer necessário editar `customers_controller.php` — não é; reporte.

## Maintenance notes

- Para quem revisa: confirmar a ordem das colunas `(active, customer_mail, idx)` —
  a ordem importa para o covering do GROUP BY. Confirmar que `idx_orders_customer_mail`
  (029) **não** foi removido.
- Interação futura: se a listagem de `/clientes` passar a agrupar/filtrar por outra
  coluna (ex.: CPF), reavaliar se este índice ainda cobre ou se precisa de outro.
