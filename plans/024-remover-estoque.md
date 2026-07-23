# Plan 024: Remover a tela /estoque e o ledger de movimentações (mantendo baixa de estoque na venda)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- site/app/inc/controller/checkout_controller.php manager/app/inc/controller/stock_controller.php manager/app/inc/controller/site_controller.php manager/public_html/index.php site/app/inc/model/stock_movements_model.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (edita `finalize()` — caminho de pagamento — e o dashboard)
- **Depends on**: none (coordenar com o plano 022, que edita o MESMO `finalize()` — execute um por vez e releia o método entre eles)
- **Category**: direction (less is more)
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

O escopo não pede gestão de inventário. O que ele precisa — não vender sem estoque — já é garantido pelo checkout, que valida e decrementa `products.stock` com `SELECT ... FOR UPDATE`. O resto é um subsistema de auditoria completo (`/estoque`, ledger `stock_movements`, 2 tabelas de junção, `stock_min`, alerta no dashboard) que o CRUD de produtos torna redundante: o admin já define `stock` direto no form de produto. Sob "less is more": sai o ledger e a tela; fica a baixa simples na venda e o campo `stock` no produto.

## Current state

- **Fica intacto** — enforcement no checkout: `site/app/inc/controller/checkout_controller.php` valida estoque em `lockAndValidateCart()` (guard ~:443-446) e decrementa com `UPDATE products SET stock = stock - ?` (~:96). NÃO TOCAR nesses pontos.
- **Sai** — escrita do ledger em `finalize()` (~:166-180):

```php
// Movimento de saida no ledger por linha, ligado ao produto e ao pedido
// (juncoes, regra do framework — stock_movements nao guarda FK inline).
$saleUserId = 0; // venda publica, sem admin logado
foreach ($finalLines as $line) {
    $mov = new stock_movements_model();
    $mov->populate(['kind' => 'saida', 'qty' => $line['units_needed'], 'note' => 'venda']);
    $movId = (int)$mov->save();

    $mov->linkToProduct($line['products_id'], $movId, $saleUserId);
    $mov->linkToOrder($orderId, $movId, $saleUserId);
}
```

(o bloco fica entre o try/catch de `linkCustomerToOrder` — removido pelo plano 022 — e o comentário do item sintético de taxa do InfinitePay).
- **Sai** — módulo do manager: rotas `manager/public_html/index.php:116-117` (GET/POST `/estoque`); `stock_controller.php` (métodos `index/action/registerEntrada/recordEntrada/recentMovementsWithProductName`); view `ui/page/stock.php`; `$stock_url` em `urls.php`.
- **Sai** — model compartilhado `stock_movements_model.php` (2 cópias: `site/app/inc/model/` e `manager/app/inc/model/`; contém `linkToProduct()`/`linkToOrder()`).
- **Sai** — tile "estoque baixo" do dashboard: `manager/app/inc/controller/site_controller.php:245-256` (`lowStockCount()`, query sobre `stock_min`) + o card correspondente em `ui/page/sales_dashboard.php` (localize por `lowStock`).
- **Decisão sobre `stock_min`**: a coluna (`migrations/026`) existe só para o alerta. Sai junto: remova do form de produto (`products.php`, input `stock_min`; `productsController.js` estado/`openEdit`; `products_controller.php` validate/populate/set_field — `grep -n "stock_min" manager/` mapeia os pontos) e da migration de drop.
- Tabelas: `migrations/023_create_table_stock_movements.sql`, `024_create_table_products_stock_movements.sql`, `025_create_table_orders_stock_movements.sql`, `026_add_stock_min_to_products.sql`. Drop = migration NOVA (próximo nº livre — `ls migrations/ | sort | tail -1`; outros planos também criam migrations).
- Testes acoplados: `manager/tests/StockEntryTest.php`, `site/tests/StockMovementOnSaleTest.php` (deletar); `SalesDashboardTest`/`SalesDashboardFailureTest` têm casos de `lowStockCount()` (adaptar, não deletar); `ProductsValidationTest` pode ter casos de `stock_min` (adaptar).
- Sidebar "Estoque" em 3 views: `dashboard.php`, `stock.php` (morre junto), `sales_dashboard.php` (regra geral: `grep -ln "stock_url" manager/public_html/ui/page/*.php`).
- Convenção de transação: o bloco removido de `finalize()` roda na transação global do request — remoção não muda semântica de commit/rollback.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan (2 envs) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (idem manager) | `[OK] No errors` |
| PHPUnit site | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/site/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/site/phpunit.xml` | verde (1 skip esperado) |
| PHPUnit manager | idem com `manager` | verde |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | aplica 1x, skip na 2ª |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `site/app/inc/controller/checkout_controller.php` (APENAS o bloco do ledger)
- `manager/public_html/index.php`, `manager/app/inc/urls.php`
- `manager/app/inc/controller/stock_controller.php` (deletar), `site_controller.php` (`lowStockCount` + chamada), `products_controller.php` (stock_min)
- `manager/public_html/ui/page/stock.php` (deletar), `sales_dashboard.php`, `dashboard.php`, `products.php`
- `manager/public_html/assets/js/alpine/productsController.js` (stock_min)
- `site/app/inc/model/stock_movements_model.php` E `manager/app/inc/model/stock_movements_model.php` (deletar os dois)
- Testes listados no Current state
- `migrations/0XX_drop_stock_ledger.sql` (nova)

**Out of scope** (NÃO tocar):
- `lockAndValidateCart()` e o `UPDATE products SET stock = stock - ?` — o enforcement de venda FICA.
- Coluna `products.stock` e o input `stock` do form de produto — FICAM.
- Qualquer outra linha de `finalize()` (taxas, order_items, item sintético InfinitePay, cobrança PIX).
- Migrations 023-026 existentes (append-only).

## Git workflow

- Branch: `advisor/024-remover-estoque`
- Commits em PT-BR, Conventional Commits.
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Inventário

```bash
grep -rn "stock_movements\|stock_url\|stock_min\|lowStock\|recordEntrada" site/ manager/ --include="*.php" --include="*.js" | grep -v vendor | grep -v migrations/
```

Esperado: só os arquivos do Scope. Consumidor extra → STOP.

### Step 2: Remover o bloco do ledger no checkout

Delete o `foreach` do Current state (com o comentário acima dele e a linha `$saleUserId`). O decremento de `stock` (que acontece ANTES, em outro ponto) fica intocado.

**Verify**: PHPStan site `[OK]`. `grep -n "stock_movements" site/app/inc/controller/checkout_controller.php` → 0. Releia o diff de `finalize()`: só o bloco saiu. Confirme que `grep -n "stock = stock - " site/app/inc/controller/checkout_controller.php` → ainda 1 (a baixa fica).

### Step 3: Remover módulo do manager + tile do dashboard

1. Rotas `/estoque` (index.php:116-117), `stock_controller.php`, `stock.php`, `$stock_url`.
2. `site_controller.php`: delete `lowStockCount()` e a linha que o chama em `salesDashboard()` (localize por `lowStock`); em `sales_dashboard.php`, delete o card correspondente.
3. `products_controller.php` / `products.php` / `productsController.js`: remova `stock_min` (validate, populate, set_field do index, input do form, estado do JS) — o campo `stock` normal fica.
4. Sidebars: remova o `<li>` "Estoque" de toda view com `stock_url`.

**Verify**: grep do Step 1 → 0 (fora migrations/ e tests/ ainda não tratados). PHPStan manager `[OK]`. `/estoque` → 404. Dashboard `/` carrega sem erro (tile sumiu).

### Step 4: Model (2 cópias) e testes

1. Delete `stock_movements_model.php` das 2 cópias.
2. Delete `StockEntryTest.php` e `StockMovementOnSaleTest.php`.
3. Adapte `SalesDashboardTest`/`SalesDashboardFailureTest` (casos de `lowStockCount` saem) e `ProductsValidationTest` (casos de `stock_min` saem; casos de `stock` ficam).

**Verify**: `bin/check-shared-sync.sh` exit 0. PHPUnit site e manager completos verdes.

### Step 5: Migration de drop

`migrations/0XX_drop_stock_ledger.sql` (próximo nº livre) — junções primeiro; o drop de coluna precisa do guard idempotente padrão do repo (copie o mecanismo de checagem em `information_schema` da migration `026_add_stock_min_to_products.sql`, invertendo a condição para DROP):

```sql
DROP TABLE IF EXISTS products_stock_movements;
DROP TABLE IF EXISTS orders_stock_movements;
DROP TABLE IF EXISTS stock_movements;
-- + DROP COLUMN products.stock_min com guard information_schema (idempotente)
```

**Verify**: `run_migrations.php` aplica; 2ª rodada skipped. `SHOW TABLES LIKE '%stock%'` → vazio; `SHOW COLUMNS FROM products LIKE 'stock%'` → só `stock`.

### Step 6: Fumaça no funil

**Verify**: contra o stack vivo, compra completa até a tela de PIX; depois `SELECT stock FROM products WHERE idx = <produto comprado>` → estoque decrementado. Produto com `stock=0` → checkout recusa (mensagem de estoque insuficiente).

## Test plan

Regressão existente: `CheckoutStockTest` (estoque insuficiente/preço adulterado) continua passando e é o guard do que fica. Adaptações do Step 4. Smoke do Step 6 obrigatório.

## Done criteria

- [ ] PHPStan `[OK]` e PHPUnit verde nos 2 ambientes (incl. `CheckoutStockTest` intacto)
- [ ] `/estoque` → 404; dashboard sem tile de estoque, sem erro
- [ ] `grep -rn "stock_movements\|stock_min" site/ manager/ --include="*.php" --include="*.js" | grep -v vendor | grep -v migrations/` → 0
- [ ] Baixa de estoque na venda comprovada no stack vivo (Step 6)
- [ ] Migration de drop aplicada e idempotente
- [ ] `bin/check-shared-sync.sh` exit 0; `git status` limpo fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- O plano 022 estiver em execução simultânea (mesmo `finalize()`) — sequencie e releia o método antes de editar.
- Step 1 revela consumidor do ledger fora do mapeado.
- `CheckoutStockTest` falhar após o Step 2 (o enforcement foi tocado por engano).
- Excertos do Current state não batem (drift).

## Maintenance notes

- Sem ledger, não há histórico de movimentação — ajuste de estoque vira edição direta do campo no produto, sem trilha. Decisão consciente do "less is more"; se auditoria voltar a ser requisito, é redesenho novo.
- Revisor: o diff de `checkout_controller.php` deve remover EXATAMENTE o foreach do ledger e nada mais; conferir `CheckoutStockTest` verde é o critério de que o enforcement sobreviveu.
