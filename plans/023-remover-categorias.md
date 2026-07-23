# Plan 023: Remover o CRUD /categorias e a taxonomia (products volta a categoria texto-livre)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- manager/app/inc/controller/products_controller.php manager/app/inc/controller/categories_controller.php manager/public_html/ui/page/products.php manager/public_html/assets/js/alpine/productsController.js manager/public_html/index.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (o CRUD de produtos está acoplado à taxonomia — reverter para texto livre toca controller, view e JS)
- **Depends on**: none
- **Category**: direction (less is more)
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

A vitrine pública filtra produtos pela **string** `products.category` — nunca pela taxonomia normalizada. As tabelas `categories`/`products_categories` e o CRUD `/categorias` só existem para alimentar um `<select>` no form de produto, que no fim grava... a mesma string denormalizada. É um subsistema inteiro (rota, controller, view, JS, 2 tabelas, 2 models, testes) sustentando um dropdown. Sob "less is more": o form de produto volta a ter um input de texto para `category` e a taxonomia sai.

## Current state

- Vitrine (NÃO muda): `site/app/inc/controller/site_controller.php:23-26` filtra `category = ?`; `:51-53` lista `SELECT DISTINCT category FROM products WHERE active='yes' ORDER BY category`. Ou seja: o filtro público continua funcionando com qualquer string em `products.category`.
- Acoplamento no CRUD de produtos (`manager/app/inc/controller/products_controller.php`):
  - `:32` — `$model->attach(['categories'], null, null, [' idx ', ' name '])` na listagem (alimenta `categories_attach` na view). Custa 2 queries POR LINHA (comportamento do `DOLModel::attach`) — a remoção também elimina esse N+1.
  - `:40-48` — carrega `categories_model` para o `<select>`.
  - `:81-92` e `:124-136` — create/update: exigem `categories_id > 0` e fazem `save_attach(..., ['categories'])`.
  - `:204-215` — `validate()`: rejeita `categories_id <= 0` ("Selecione uma categoria.") e resolve `$categoryName` a partir da taxonomia; `:253` grava `'category' => $categoryName` (string denormalizada).
- View `manager/public_html/ui/page/products.php`: `:113` lê `categories_attach[0]['idx']` para o JS; `:131` mostra `categories_attach[0]['name']` na tabela; `:218-220+` `<select name="categories_id" required>` populado por `$categories`.
- JS `manager/public_html/assets/js/alpine/productsController.js`: `:4` estado `categoriesId`, `:19-21` `openEdit(..., categoriesId, ...)`.
- Módulo a remover: rota `manager/public_html/index.php:99-100` (GET/POST `/categorias`); `categories_controller.php`; `ui/page/categories.php`; `assets/js/alpine/categoriesController.js`; `$categories_url` em `urls.php`; `categories_model.php` em **2 cópias** (`site/app/inc/model/` e `manager/app/inc/model/`).
- Tabelas: `migrations/016_create_table_categories.sql`, `017_create_table_products_categories.sql`. Drop = migration NOVA (próximo nº livre — recalcule com `ls migrations/ | sort | tail -1`; outros planos também criam migrations).
- Testes acoplados: `grep -rln "categories" site/tests manager/tests` → leia a lista na execução; conhecidos: `CategoriesValidationTest`, `CategoriesJunctionTest` (deletar), e possíveis asserts de categoria em `ProductsValidationTest` (adaptar, não deletar).
- Sidebar "Categorias" em 4 views: `categories.php` (morre junto), `dashboard.php`, `stock.php`, `sales_dashboard.php`.
- Convenção: validação de produto centralizada em `products_controller::validate()`; mensagens de erro via `$_SESSION["messages_app"]["danger"]`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan (2 envs) | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` (idem site) | `[OK] No errors` |
| PHPUnit manager | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/manager/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/manager/phpunit.xml` | verde |
| PHPUnit site | idem com `site` | verde (1 skip esperado) |
| Migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | aplica 1x, skip na 2ª |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `manager/public_html/index.php`, `manager/app/inc/urls.php`
- `manager/app/inc/controller/categories_controller.php` (deletar), `products_controller.php` (desacoplar)
- `manager/public_html/ui/page/categories.php` (deletar), `products.php`, `dashboard.php`, `stock.php`, `sales_dashboard.php` (link de sidebar)
- `manager/public_html/assets/js/alpine/categoriesController.js` (deletar), `productsController.js` (remover categoriesId)
- `site/app/inc/model/categories_model.php` E `manager/app/inc/model/categories_model.php` (deletar os dois)
- Testes de categorias (deletar) + ajustes em testes de produtos
- `migrations/0XX_drop_categories_tables.sql` (nova)

**Out of scope** (NÃO tocar):
- `site/app/inc/controller/site_controller.php` — o filtro público por string fica como está.
- Coluna `products.category` — FICA (é a fonte do filtro do site).
- `DOLModel.php` (attach/save_attach são framework).
- Migrations 016/017 existentes.

## Git workflow

- Branch: `advisor/023-remover-categorias`
- Commits em PT-BR, Conventional Commits. Sugerido: 1 commit desacopla produtos, 1 commit remove módulo, 1 commit migration+testes.
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Desacoplar o CRUD de produtos (antes de remover a taxonomia)

Em `products_controller.php`:
1. `validate()` (~:204-215): substitua o bloco de `categories_id` por leitura direta de `$post['category']` (string): `trim()`, obrigatória não-vazia, limite razoável (`mb_strlen <= 80` — o schema da coluna é VARCHAR; confirme o tamanho real com `SHOW COLUMNS FROM products LIKE 'category'` e use o menor entre 80 e o schema). Mensagem de erro no mesmo padrão: `"Informe a categoria."`. O retorno do validate deve continuar entregando `'category' => $categoria` para o populate (`:253` já usa `'category' => ...`).
2. Create/update (~:81-92, :124-136): remova a validação de `categories_id` e as 2 chamadas `save_attach(..., ['categories'])`.
3. `index()`: remova o `attach(['categories'], ...)` (:32) e o carregamento de `$categories` via `categories_model` (:40-48).

Em `products.php` (view): troque o `<select name="categories_id" required>` por `<input type="text" name="category" required>` pré-preenchido no edit; a coluna da tabela (:131) passa a mostrar `htmlspecialchars($p['category'] ?? '—')` em vez de `categories_attach`; ajuste `:113` (o JS não precisa mais de `categoriesId`).

Em `productsController.js`: remova `categoriesId` do estado e da assinatura de `openEdit()`, adicionando `category` (string) no lugar — espelhe como os outros campos texto (ex. `name`) já trafegam.

**Verify**: PHPStan manager `[OK]`. Contra o stack vivo: criar produto novo com categoria digitada livre → aparece na vitrine do site sob o filtro daquela categoria; editar produto existente preserva a categoria atual no input.

### Step 2: Remover o módulo de categorias

1. `index.php`: delete as rotas `/categorias` (linhas 99-100).
2. Delete `categories_controller.php`, `ui/page/categories.php`, `categoriesController.js`.
3. `urls.php`: delete `$categories_url`.
4. Sidebars (`dashboard.php`, `stock.php`, `sales_dashboard.php`): remova o `<li>` "Categorias". (Se planos 020/022/024 já rodaram, a lista de views com o link pode diferir — regra: `grep -ln "categories_url" manager/public_html/ui/page/*.php` e limpe todas.)
5. Delete `categories_model.php` das 2 cópias (`site/app/inc/model/` e `manager/app/inc/model/`).

**Verify**: `grep -rn "categories_model\|categories_url\|categories_id\|categories_attach\|products_categories" site/ manager/ --include="*.php" --include="*.js" | grep -v vendor | grep -v migrations/ | grep -v tests/` → 0. PHPStan 2 envs `[OK]`. `bin/check-shared-sync.sh` exit 0. `/categorias` → 404.

### Step 3: Testes

1. Delete os testes do módulo: os que o grep apontar como exclusivos de categorias (conhecidos: `CategoriesValidationTest`, `CategoriesJunctionTest` — confirme o nome exato com `ls manager/tests/ site/tests/ | grep -i categor`).
2. `ProductsValidationTest` (e qualquer teste de produto que use `categories_id`): adapte para o contrato novo — categoria string obrigatória; caso vazio → rejeita; caso válido → `products.category` gravado.

**Verify**: PHPUnit manager e site completos → verdes.

### Step 4: Migration de drop

`migrations/0XX_drop_categories_tables.sql` (próximo nº livre), junção primeiro:

```sql
DROP TABLE IF EXISTS products_categories;
DROP TABLE IF EXISTS categories;
```

**Verify**: `run_migrations.php` aplica; 2ª rodada skipped. `SHOW TABLES LIKE '%categories%'` → vazio.

## Test plan

- Adaptações de `ProductsValidationTest` (Step 3) cobrem o contrato novo do validate.
- Smoke manual do Step 1 (criar/editar produto com categoria livre + filtro da vitrine) é obrigatório — é o comportamento que o cliente final vê.

## Done criteria

- [ ] PHPStan `[OK]` e PHPUnit verde nos 2 ambientes
- [ ] `/categorias` → 404; criar/editar produto com categoria texto-livre funciona no stack vivo; filtro por categoria na vitrine continua funcionando
- [ ] grep do Step 2 → 0 referências remanescentes
- [ ] Migration de drop aplicada e idempotente
- [ ] `products.category` intacta (`SHOW COLUMNS FROM products LIKE 'category'` → existe)
- [ ] `bin/check-shared-sync.sh` exit 0; `git status` limpo fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- O grep do Step 2 revela consumidor da taxonomia fora do mapeado (ex. algum relatório do dashboard usando `products_categories`).
- A vitrine parar de mostrar o filtro de categoria após o Step 1 (indicaria dependência não mapeada).
- Excertos do Current state não batem (drift).

## Maintenance notes

- Categoria vira texto livre: dois produtos com "Peptídeo" e "peptideo" viram 2 filtros distintos na vitrine. É o comportamento pré-plano-007, aceito pelo "less is more". Se virar problema real de operação, a resposta é normalizar a string no validate (lowercase/trim), não ressuscitar a taxonomia.
- Revisor: conferir que `site_controller.php` (site) não foi tocado e que o form de produto pré-preenche a categoria no edit (perda silenciosa de categoria em edição é o bug mais provável aqui).
