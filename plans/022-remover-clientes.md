# Plan 022: Remover a tela /clientes e as tabelas customers/orders_customers

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- site/app/inc/controller/checkout_controller.php manager/app/inc/controller/customers_controller.php manager/public_html/index.php site/app/inc/model/customers_model.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED/HIGH (edita `finalize()` — caminho de pagamento)
- **Depends on**: `plans/019-filtros-cpf-telefone-pedidos.md` **mergeado** (os filtros na lista de pedidos substituem esta tela)
- **Category**: direction (less is more)
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

O escopo pede "filtros por telefone e CPF" NA LISTA DE PEDIDOS (entregue pelo plano 019 sobre as colunas denormalizadas `orders.customer_*`). A tela `/clientes` e o par de tabelas normalizadas `customers`/`orders_customers` são um armazenamento paralelo que **nenhuma view in-scope lê** — a lista de pedidos, o detalhe e o export usam só as colunas do próprio `orders`. Manter o upsert de cliente no checkout é custo (2-3 queries + 1 ponto de falha dentro do caminho de pagamento) sem leitor. Sob "less is more", sai a tela, sai o upsert, saem as tabelas.

## Current state

- Rota: `manager/public_html/index.php:109` — `GET /clientes → customers_controller:index` (só leitura, não há POST).
- `manager/app/inc/controller/customers_controller.php` — busca por CPF/telefone em `customers` + histórico via junção `orders_customers` (`ordersOfCustomer()`, linhas 72-83). View: `manager/public_html/ui/page/customers.php`. URL: `$customers_url` em `manager/app/inc/urls.php`.
- Escrita (site, dentro do caminho de pagamento) — `site/app/inc/controller/checkout_controller.php`:
  - `:150-165` (aprox.) — bloco try/catch em `finalize()`:

```php
try {
    $this->linkCustomerToOrder($order, $orderId, $customer);
} catch (\Throwable $e) {
    Logger::getInstance()->error("checkout_controller::finalize linkCustomerToOrder falhou", [ ... ]);
    ...
    basic_redir($checkout_url, rollback: true);
}
```

  - `:250-257` — `linkCustomerToOrder()`: chama `upsertCustomer()` + `$order->save_attach(..., ['customers'])`.
  - `:266-320` — `upsertCustomer()`: valida CPF, busca/atualiza/reativa/cria em `customers`.
- Model compartilhado: `customers_model.php` em `site/app/inc/model/` **e** `manager/app/inc/model/` (byte-idênticos — deletar dos DOIS lados, senão `bin/check-shared-sync.sh` bloqueia).
- Tabelas: `migrations/021_create_table_customers.sql`, `migrations/022_create_table_orders_customers.sql`. Convenção de migrations: numeradas, idempotentes, uma transação por arquivo, tracked em `migrations_log`. Maior número atual: `029` — **recalcule o próximo livre na hora** (`ls migrations/ | sort | tail -1`), outros planos também criam migrations.
- Testes acoplados: `site/tests/CustomerUpsertTest.php` (upsert via ReflectionMethod), `manager/tests/CustomerSearchTest.php`, `manager/tests/CustomersViewTest.php`.
- Sidebar "Clientes" duplicada em 10 views do manager: `categories.php, gateways.php, orders.php, emails.php, sales_dashboard.php, dashboard.php, order_detail.php, profiles.php, products.php, customers.php, stock.php` (a lista real pode ter mudado se os planos 020/023/024 já rodaram — regra: remova o `<li>` de "Clientes" de TODA view que tiver `customers_url`).
- Convenção de transação: `finalize()` roda tudo numa transação global; `basic_redir()` commita. A remoção do bloco não muda isso.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan (2 envs) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (idem manager) | `[OK] No errors` |
| PHPUnit site | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/site/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/site/phpunit.xml` | verde (1 skip esperado) |
| PHPUnit manager | idem com `manager` | verde |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | migration nova aplicada; 2ª rodada = skipped |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `manager/public_html/index.php` (1 rota), `manager/app/inc/urls.php` (`$customers_url`)
- `manager/app/inc/controller/customers_controller.php`, `manager/public_html/ui/page/customers.php` (deletar)
- Views do manager com o `<li>` "Clientes" na sidebar (remoção do link apenas)
- `site/app/inc/controller/checkout_controller.php` (remover try/catch + 2 métodos)
- `site/app/inc/model/customers_model.php` E `manager/app/inc/model/customers_model.php` (deletar os dois)
- `site/tests/CustomerUpsertTest.php`, `manager/tests/CustomerSearchTest.php`, `manager/tests/CustomersViewTest.php` (deletar)
- `migrations/0XX_drop_customers_tables.sql` (nova)

**Out of scope** (NÃO tocar):
- QUALQUER outra linha de `finalize()` — em particular o bloco de taxas, o loop de `order_items`, o ledger de estoque e o item sintético do InfinitePay que vêm logo antes/depois do bloco removido.
- Colunas `orders.customer_name/mail/phone/cpf` e o índice da migration 029 — são a fonte in-scope.
- `track_order_controller.php` (usa `orders.customer_mail/phone`, não `customers`).
- `DOLModel.php` (`save_attach`/`attach` são framework).
- Migrations 021/022 existentes (append-only — o drop é migration NOVA).

## Git workflow

- Branch: `advisor/022-remover-clientes`
- Commits em PT-BR, Conventional Commits.
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Confirmar que nada in-scope lê as tabelas

```bash
grep -rn "customers_model\|orders_customers\|customers_url" site/ manager/ --include="*.php" --include="*.js" | grep -v vendor
```

Esperado: só os arquivos listados no Scope (controller/view/model/testes/checkout/urls/sidebars) + as migrations 021/022. Qualquer outro consumidor → STOP.

### Step 2: Remover a escrita no checkout

Em `site/app/inc/controller/checkout_controller.php`:
1. Delete o bloco try/catch de `linkCustomerToOrder` (Current state, ~:150-165) — o código passa direto do passo anterior para o próximo (ledger de estoque).
2. Delete os métodos `linkCustomerToOrder()` e `upsertCustomer()` inteiros.

**Verify**: PHPStan site → `[OK]`. `grep -n "upsertCustomer\|linkCustomerToOrder\|customers_model" site/app/inc/controller/checkout_controller.php` → 0. Releia o diff de `finalize()` inteiro e confirme que NENHUMA outra linha mudou.

### Step 3: Remover a tela do manager

1. `manager/public_html/index.php`: delete a rota `/clientes` (linha 109).
2. Delete `customers_controller.php` e `ui/page/customers.php`.
3. `urls.php`: delete `$customers_url`.
4. Sidebars: `grep -ln "customers_url" manager/public_html/ui/page/*.php` e remova o `<li>` "Clientes" de cada um.

**Verify**: `grep -rn "customers_url\|customers_controller" manager/ --include="*.php" | grep -v vendor` → 0. PHPStan manager `[OK]`. `curl -s -o /dev/null -w "%{http_code}" -H "Host: manager.infinnityimportacao.local" http://localhost/clientes` → 404.

### Step 4: Deletar model (2 cópias) e testes

Delete `site/app/inc/model/customers_model.php`, `manager/app/inc/model/customers_model.php`, e os 3 arquivos de teste listados no Scope.

**Verify**: `bin/check-shared-sync.sh` → exit 0 (os dois lados sem o arquivo = ainda sincronizados). PHPStan 2 envs `[OK]`.

### Step 5: Migration de drop

Crie `migrations/0XX_drop_customers_tables.sql` (próximo número livre) — idempotente, junção primeiro:

```sql
DROP TABLE IF EXISTS orders_customers;
DROP TABLE IF EXISTS customers;
```

**Verify**: rode `run_migrations.php` → aplica; rode de novo → skipped (idempotente). `docker exec mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" db_infinnityimportacao -e "SHOW TABLES LIKE 'customers%';"` → vazio.

### Step 6: Suítes completas + fumaça no checkout

**Verify**: PHPUnit site e manager completos → verdes. Contra o stack vivo: fluxo home → carrinho → checkout com CPF válido → chega na tela de PIX (o pedido é criado sem erro; `SELECT customer_cpf FROM orders ORDER BY idx DESC LIMIT 1` mostra o CPF gravado — as colunas denormalizadas continuam funcionando).

## Test plan

Sem testes novos (plano de remoção). Regressão: `CheckoutStockTest`, `CheckoutPaymentChargeTest`, `OrderFeeBreakdownPersistenceTest`, `TrackOrderTest` e o smoke manual do Step 6 provam que `finalize()` continua íntegro.

## Done criteria

- [ ] PHPStan `[OK]` e PHPUnit verde nos 2 ambientes
- [ ] `grep -rn "customers_model\|orders_customers\|customers_url\|upsertCustomer" site/ manager/ --include="*.php" | grep -v vendor | grep -v migrations/` → 0
- [ ] `/clientes` → 404; checkout manual completo até a tela de PIX funciona
- [ ] Migration de drop aplicada e idempotente (2ª rodada skipped)
- [ ] `bin/check-shared-sync.sh` exit 0; `git status` limpo fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- Plano 019 ainda não mergeado (`grep -c "customer_cpf = ?" manager/app/inc/controller/orders_controller.php` → tem que ser 1 ANTES de começar) — sem os filtros, remover `/clientes` destrói a única busca por CPF/telefone.
- Step 1 revela leitor de `customers`/`orders_customers` fora da lista mapeada.
- Qualquer teste de checkout falha após o Step 2.
- O diff de `finalize()` tocar qualquer linha além do bloco try/catch removido.

## Maintenance notes

- `orders.customer_*` denormalizado passa a ser a ÚNICA fonte de dados de comprador — qualquer feature futura de "cliente" recomeça do zero (decisão consciente, não restaurar as tabelas sem novo desenho).
- Revisor: o diff de `checkout_controller.php` deve ser puramente subtrativo e restrito ao bloco + 2 métodos.
- A migration de drop destrói dados de `customers` acumulados em dev/prod. Em dev é descartável por regra do dono. Se houver PROD com dados reais, o operador decide se arquiva antes (`CREATE TABLE customers_backup AS SELECT ...` manual, fora deste plano).
