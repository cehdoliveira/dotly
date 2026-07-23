# Plan 007: Categoria vira entidade própria (CRUD no manager + relação via junção `products_categories`)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4ad3e67..HEAD -- migrations manager/app/inc/controller/products_controller.php manager/app/inc/model/products_model.php site/app/inc/model/products_model.php manager/public_html/ui/page/products.php manager/app/inc/lib/DOLModel.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: tech-debt / migration
- **Planned at**: commit `4ad3e67`, 2026-07-16

## Why this matters

Hoje "categoria" é uma string livre gravada direto em `products.category`
(`VARCHAR(60)`), digitada à mão a cada cadastro de produto. Isso produz
duplicatas ("Peptídeo" vs "peptideo"), impossibilita renomear uma categoria sem
tocar N produtos, e não há tela para gerenciá-las. Este plano cria uma entidade
`categories` com CRUD próprio no manager e liga cada produto à sua categoria
**pelo mecanismo de junção do framework** (tabela `products_categories`), passando
o formulário de Produto a **selecionar** a categoria em vez de criá-la inline.
Para não quebrar o site público (que hoje filtra pela string `products.category`),
mantemos a coluna `category` como **rótulo denormalizado**, sincronizada
automaticamente com o `name` da categoria escolhida.

## Fatos arquiteturais do framework (LEGGO) — leia antes de codar

Este repositório NÃO é Laravel/Symfony. É um framework custom. Regras que este
plano precisa respeitar:

- **Dois ambientes, um codebase**: `manager/` (painel admin, com login) e
  `site/` (frontend público). Controllers, rotas e views são por-ambiente.
- **`app/inc/lib/` e `app/inc/model/` são cópias byte-idênticas** entre
  `manager/` e `site/`. Todo model novo tem que ser criado nas **duas** cópias,
  idênticas. O guard `bin/check-shared-sync.sh` (roda no pre-commit) bloqueia o
  commit se divergirem.
- **RELACIONAMENTO ENTRE TABELAS = SEMPRE TABELA DE JUNÇÃO.** Regra do dono do
  repo: quando duas tabelas se relacionam, DEVE existir uma tabela
  `{tabelaA}_{tabelaB}` com `{tabelaA}_id`, `{tabelaB}_id` + colunas de auditoria
  padrão + `active` + `UNIQUE(a_id, b_id)`. O framework opera a relação por
  `attach()` / `save_attach()` / `join()` do `DOLModel`. **Não use coluna FK
  inline** (`products.categories_id`) para modelar a relação. Exemplar existente:
  `migrations/004_create_table_users_profiles.sql` (junção `users_profiles`).
- **Uma transação global por request** via `localPDO`. `basic_redir($url)`
  commita; `basic_redir($url, rollback: true)` faz rollback. Controllers **nunca**
  chamam `commit()`/`rollback()` na mão.
- **ORM = arrays de SQL cru**. Models estendem `DOLModel`, definem `$field` e
  `$filter`. `set_filter([...cond], [...vals])` usa `?`. Todo input via bound param.
- **Soft-delete universal**: `active = 'yes'/'no'`. NUNCA `DELETE FROM`.
  `->remove()` faz soft-delete.
- **Dispatcher só trata GET e POST.** CRUD = POST + campo `action`.
- **CSRF**: `validate_csrf($post['_csrf_token'], $redirectUrl)` em todo POST.
- **Migrations** em `migrations/` (raiz do repo, cópia única), numeradas,
  idempotentes, uma transação por arquivo.
- **DEV**: a base pode ser DROPADA/TRUNCADA e as migrations rodam de novo. Backfill
  de dados legados é best-effort, não bloqueante.

### API de junção do `DOLModel` (verificada em `app/inc/lib/DOLModel.php`)

- **Nome da junção**: `sprintf("%s_%s", $this->table, $class)` — o model em que você
  chama o método vem primeiro. `$reverse_table` truthy inverte para
  `{class}_{this->table}`. Colunas: `{this->table}_id` e `{class}_id`.
- **`save_attach(['idx'=>$id, 'post'=>['{class}_id'=>$val]], ['{class}'])`**
  (`DOLModel.php:428`): **substitui** o conjunto de links do owner — faz soft-delete
  de todos os links ativos daquele owner e insere os novos. Lê
  `$info["post"]["{class}_id"]` (escalar ou array). Ideal para "produto tem UMA
  categoria" (reeditar troca a categoria). O nome do campo POST tem que ser
  exatamente `{class}_id`.
- **`attach(['{class}'], $reverse, $options, $class_field)`** (`DOLModel.php:268`):
  carrega `row["{class}_attach"]` a partir da junção + `SELECT FROM {class}`.
  `{class}` é o **nome da tabela** relacionada.

## Current state

- `products.category` é string livre. `migrations/009_create_table_products.sql`:
  ```sql
  `category` VARCHAR(60) NOT NULL DEFAULT '',
  ```
- Não existe tabela `categories` nem junção `products_categories`. Confirme:
  `grep -rn "categories" migrations/` → vazio.
- **Model compartilhado** `manager/app/inc/model/products_model.php` (idêntico em
  `site/`):
  ```php
  class products_model extends DOLModel {
      protected array $field = [" idx ", " name ", " slug ", " category ", " description ", " dosage ", " purity_label ", " price_unit_cents ", " price_box_cents ", " box_qty ", " stock "];
      protected array $filter = [" active = 'yes' "];
      function __construct() { parent::__construct("products"); }
  }
  ```
  (Note: `category` continua aqui como rótulo; **não** haverá `categories_id`.)
- **Controller** `manager/app/inc/controller/products_controller.php`:
  - `validate()` (linhas 158-214) lê `$category = trim($post['category'] ?? '')` e
    exige não-vazio (162, 177-180); retorna `'category' => $category` (208).
  - `action()` (54-132): create (`populate`+`save`), edit
    (`set_filter(["idx = ?"],[$idx])`+`populate`+`save`), remove (`->remove()`).
  - `index()` (7-52) já usa `join()` para carregar imagens:
    `$model->join('images', 'product_images', ['products_id' => 'idx'], null, [...])`
    — exemplo de como a view recebe dados relacionados.
- **View** `manager/public_html/ui/page/products.php`:
  - Form criar (linha 183): `<input type="text" name="category" ... required>` na **206**.
  - Form editar (243, modal Alpine): `<input ... name="category" x-model="editData.category" required>` na **267**; `editData` montado a partir de `$jsCategory` (108: `json_encode($p['category'])`).
  - Tabela lista categoria na **124**: `htmlspecialchars($p['category'] ?? '—', ...)`.
- **Junção exemplar**: `migrations/004_create_table_users_profiles.sql` — copie a
  estrutura de colunas dela.
- **Rotas manager** em `manager/public_html/index.php`; **URLs** em
  `manager/app/inc/urls.php` (`$products_url = ... "produtos"`); **nav** em
  `manager/public_html/ui/page/dashboard.php` (linhas ~16-42).
- **Site filtra pela string**: `site/public_html/ui/page/home.php` usa `$cat`
  (linha 133) montado pelo `shop_controller` a partir de `?cat=`, comparando com
  `products.category`. Por isso **NÃO removemos a coluna `category`**.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Guard shared-sync | `bin/check-shared-sync.sh` | sem DRIFT, exit 0 |
| PHPUnit manager | `docker exec -w /var/www/infinnityimportacao/manager -e HTTP_HOST=localhost infinnityimportacao php app/inc/lib/vendor/bin/phpunit -c phpunit.xml` | all pass |
| Rodar migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | aplicada, idempotente no 2º run |
| Próximo nº migration | `ls migrations/ \| sort \| tail -1` | maior número atual (015) |

## Scope

**In scope**:
- `migrations/NNN_create_table_categories.sql` (criar — próximo nº livre)
- `migrations/NNN_create_table_products_categories.sql` (criar — próximo nº depois)
- `manager/app/inc/model/categories_model.php` + `site/app/inc/model/categories_model.php` (criar — **byte-idênticos**)
- `manager/app/inc/controller/categories_controller.php` (criar)
- `manager/app/inc/controller/products_controller.php` (usar `save_attach` + `attach`; sincronizar o rótulo `category`)
- `manager/app/inc/urls.php` (`$categories_url`)
- `manager/public_html/index.php` (rotas GET/POST `/categorias`)
- `manager/public_html/ui/page/categories.php` (criar)
- `manager/public_html/ui/page/products.php` (trocar inputs de texto por `<select name="categories_id">`)
- `manager/public_html/ui/page/dashboard.php` (link de nav "Categorias")
- `manager/tests/CategoriesValidationTest.php` (criar)

**Out of scope**:
- Qualquer arquivo em `site/` além do `categories_model.php` (cópia do model). O
  site continua filtrando pela string `products.category` — não toque em
  `shop_controller`/`home.php` nem no funil.
- NÃO remova a coluna `products.category`. NÃO crie coluna `products.categories_id`
  (a relação é pela junção).
- `webhook_controller.php`, `checkout_controller.php`, gateways.
- `DOLModel.php` (você só usa a API dele, não a altera).

## Git workflow

- Branch: `advisor/007-categorias`
- Commits PT-BR, Conventional Commits. Ex.: `feat: CRUD de categorias + junção products_categories`.
- Sem push/PR sem ordem do operador.

## Steps

### Step 1: Migration — tabela `categories`

Descubra o próximo número (`ls migrations/ | sort | tail -1`; se o maior for `015`,
use `016`). Crie `migrations/016_create_table_categories.sql`:

```sql
CREATE TABLE IF NOT EXISTS `categories` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `name` VARCHAR(60) NOT NULL,
    `slug` VARCHAR(80) NOT NULL UNIQUE,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`idx`),
    KEY `idx_categories_active_sort` (`active`, `sort_order`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Backfill (best-effort; DEV pode dropar a base): 1 categoria por string distinta.
INSERT IGNORE INTO `categories` (`created_at`, `created_by`, `active`, `name`, `slug`, `sort_order`)
SELECT NOW(), 0, 'yes', p.category,
       LOWER(REPLACE(REPLACE(TRIM(p.category), ' ', '-'), '/', '-')), 0
FROM (SELECT DISTINCT `category` FROM `products` WHERE `category` <> '') AS p;
```

**Verify**: rode migrations → sem erro; 2º run idempotente. `SELECT COUNT(*) FROM categories`.

### Step 2: Migration — junção `products_categories`

Crie `migrations/017_create_table_products_categories.sql`, modelada em
`migrations/004_create_table_users_profiles.sql`. Colunas `products_id`,
`categories_id`, auditoria, `active`, `UNIQUE(products_id, categories_id)`:

```sql
CREATE TABLE IF NOT EXISTS `products_categories` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `products_id` INT NOT NULL,
    `categories_id` INT NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_products_id` (`products_id`),
    KEY `idx_categories_id` (`categories_id`),
    KEY `idx_active` (`active`),
    UNIQUE KEY `uq_products_categories` (`products_id`, `categories_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Relacao produto <-> categoria';

-- Backfill dos links a partir do rotulo atual (best-effort).
INSERT IGNORE INTO `products_categories` (`created_at`, `created_by`, `active`, `products_id`, `categories_id`)
SELECT NOW(), 0, 'yes', p.`idx`, c.`idx`
FROM `products` p
JOIN `categories` c ON c.`name` = p.`category` AND c.`active` = 'yes'
WHERE p.`category` <> '';
```

> Nome da junção `products_categories` (owner = `products` primeiro) porque você vai
> chamar `save_attach`/`attach` a partir do `products_model` sem `reverse_table`.

**Verify**: rode migrations. `SELECT COUNT(*) FROM products_categories` == nº de
produtos com `category` não-vazia. 2º run idempotente (UNIQUE + INSERT IGNORE).

### Step 3: `categories_model` nas DUAS cópias

Crie `manager/app/inc/model/categories_model.php`:
```php
<?php
class categories_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " slug ", " sort_order "];
    protected array $filter = [" active = 'yes' "];
    function __construct() { parent::__construct("categories"); }
}
```
Copie **byte-idêntico** para `site/app/inc/model/categories_model.php`.

> Não é necessário um model para a junção — `attach`/`save_attach` operam a
> `products_categories` por SQL cru, usando só `products_model` e `categories_model`.

**Verify**: `bin/check-shared-sync.sh` → exit 0.

### Step 4: URL + rotas do CRUD de categorias

`manager/app/inc/urls.php`:
```php
$categories_url = sprintf("%s%s", constant("cFrontend"), "categorias");
```
`manager/public_html/index.php` (perto das rotas de `/produtos`):
```php
$dispatcher->add_route("GET",  "/categorias", "categories_controller:index",  $authGuard, $params);
$dispatcher->add_route("POST", "/categorias", "categories_controller:action", $authGuard, $params);
```

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`.

### Step 5: `categories_controller` (molde = `products_controller`)

Crie `manager/app/inc/controller/categories_controller.php` com `index()` (lista +
paginação + CSRF) e `action()` (`criar`/`editar`/`remover`), copiando a estrutura
de `products_controller`. Valide `name` obrigatório; `slug` via `generate_slug($name)`
se vazio, checado com `valid_slug()` (helpers usadas no `products_controller:170-172`).
Redirects via `basic_redir($categories_url, rollback: $rollback)`. Remoção via
`->remove()`.

**Integridade em `remover`**: antes do `->remove()`, bloqueie se houver produto
ativo ligado à categoria (consulta a junção):
```php
$stmt = (new categories_model())->execute_raw_prepared(
    "SELECT COUNT(*) AS n FROM products_categories pc
       JOIN products p ON p.idx = pc.products_id AND p.active='yes'
     WHERE pc.active='yes' AND pc.categories_id = ?",
    [$idx]
);
if ((int)($stmt->fetch(PDO::FETCH_ASSOC)['n'] ?? 0) > 0) {
    $_SESSION["messages_app"]["danger"] = ["Não é possível remover: há produtos nesta categoria."];
    basic_redir($categories_url);
}
```

**Verify**: `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`.

### Step 6: View `categories.php`

Crie `manager/public_html/ui/page/categories.php` moldada em `products.php`
(tabela + modal criar/editar Alpine + form remover). Campos `name`, `slug`
(opcional), `sort_order`. Escape todo output com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

**Verify**: abrir `/categorias` logado → criar/editar/remover funciona.

### Step 7: Form de Produto — `<select name="categories_id">` + carregar a categoria atual

Em `products_controller::index()`, carregue as categorias para o dropdown e
carregue a categoria atual de cada produto via `attach`:
```php
$catModel = new categories_model();
$catModel->set_order([" sort_order ASC ", " name ASC "]);
$catModel->load_data(false);
$categories = $catModel->data;
```
E, sobre a lista de produtos já carregada (`$model`), traga a categoria ligada:
```php
$model->attach(['categories'], null, null, [' idx ', ' name ']);
// cada produto ganha $product['categories_attach'] = [ ['idx'=>.., 'name'=>..], ... ]
```
Em `manager/public_html/ui/page/products.php`:
- **Linha 206** (criar): troque o `<input name="category">` por
  `<select name="categories_id" required>` populado com `$categories`
  (value=`idx`, label=`name`).
- **Linha 267** (editar): `<select name="categories_id" x-model="editData.categoriesId" required>`.
- Ajuste o JS que monta `editData` (linha ~108) para incluir
  `categoriesId: <?php echo (int)($p['categories_attach'][0]['idx'] ?? 0); ?>`.
- A coluna da tabela (linha 124) pode passar a exibir
  `$p['categories_attach'][0]['name'] ?? '—'` (ou continuar com o rótulo `$p['category']`).

**Verify**: abrir `/produtos` → criar produto escolhendo categoria; editar → dropdown pré-selecionado com a categoria atual.

### Step 8: `action()`/`validate()` — gravar a relação via `save_attach` + sincronizar o rótulo

Em `products_controller::validate()` (158-214), troque a leitura de `category`
(string) por `categories_id` e resolva o nome para o rótulo denormalizado:
```php
$categoriesId = (int)($post['categories_id'] ?? 0);
if ($categoriesId <= 0) {
    $_SESSION["messages_app"]["danger"] = ["Selecione uma categoria."];
    return [false, []];
}
$catModel = new categories_model();
$catModel->set_field([" name "]);
$catModel->set_filter([" active = 'yes' ", " idx = ? "], [$categoriesId]);
$catModel->set_paginate([1]);
$catModel->load_data(false);
$categoryName = $catModel->data[0]['name'] ?? null;
if ($categoryName === null) {
    $_SESSION["messages_app"]["danger"] = ["Categoria inválida."];
    return [false, []];
}
```
No array retornado (linha ~205), NÃO inclua `categories_id` (não é coluna de
`products`); inclua só o rótulo: `'category' => $categoryName`.

Em `action()`, **depois** de obter o `$productId` no `criar`
(`$productId = (int)$product->save();`, linha 74) e depois do `save()` no `editar`
(linha 108), grave a relação com `save_attach`:
```php
$product->save_attach(
    ['idx' => $productId, 'post' => ['categories_id' => $categoriesId]],
    ['categories']
);
```
(`save_attach` faz soft-delete do link antigo e insere o novo — troca de categoria
funciona no editar.) Passe `$categoriesId` do `validate()` para o `action()` (ele
já está no `$data` retornado? não — remova de `$data` e leia direto de
`$post['categories_id']` no `action`, revalidando `> 0`).

> Tudo roda na transação global; o `basic_redir()` final do `action()` commita o
> produto + o link da junção juntos.

**Verify**: criar produto → `SELECT p.category, pc.categories_id FROM products p JOIN products_categories pc ON pc.products_id=p.idx AND pc.active='yes' ORDER BY p.idx DESC LIMIT 1` → rótulo e link coerentes (mesma categoria). Editar trocando a categoria → só 1 link ativo, apontando pra nova.

### Step 9: Link de nav "Categorias"

Em `manager/public_html/ui/page/dashboard.php`, adicione um `<a class="nav-link">`
para `$GLOBALS['categories_url']` (ícone `bi bi-tags`), ao lado de "Produtos".

**Verify**: dashboard mostra o link e ele abre `/categorias`.

## Test plan

- `manager/tests/CategoriesValidationTest.php` moldado em
  `manager/tests/ProductsValidationTest.php` (confirme com `ls manager/tests/`).
  Casos: `name` vazio → inválido; `slug` inválido → inválido; válido → dados ok;
  (se testável) remoção bloqueada quando há produto ligado.
- Se puder testar `save_attach` com DB (estenda `DBTestCase`): salvar produto com
  categoria A cria 1 link ativo; reeditar para categoria B deixa só 1 link ativo (B)
  e o de A `active='no'`.
- Verificação: `cd manager && php app/inc/lib/vendor/bin/phpunit --filter Categories` → verde.

## Done criteria

- [ ] PHPStan manager e site → `[OK] No errors`
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `diff -q manager/app/inc/model/categories_model.php site/app/inc/model/categories_model.php` → sem saída
- [ ] Migrations idempotentes; `categories` e junção `products_categories` existem
- [ ] `grep -n 'name="category"' manager/public_html/ui/page/products.php` → **zero** `<input>` (só `<select name="categories_id">`)
- [ ] `grep -rn "categories_id" migrations/` → aparece SÓ na junção `products_categories` (nenhum `ALTER TABLE products ADD ... categories_id`)
- [ ] Reeditar a categoria de um produto deixa exatamente 1 link ativo na junção
- [ ] PHPUnit manager verde incl. `CategoriesValidationTest`
- [ ] Nenhum arquivo fora do In-scope modificado (`git status`)
- [ ] `plans/README.md` status row atualizado

## STOP conditions

- Os trechos citados em "Current state" (inclusive a API de `DOLModel`) não baterem
  com o código vivo (drift).
- `save_attach`/`attach` não existirem com a assinatura descrita em `DOLModel.php`
  (linhas 268/428) — releia o método e reporte a assinatura real antes de usar.
- Strings `category` com grafias divergentes que o backfill não consolidaria bem —
  reporte a lista de strings distintas.
- Remover `products.category` ou criar `products.categories_id` parecer necessário —
  NÃO faça, reporte.

## Maintenance notes

- **Modelo**: a relação produto↔categoria vive na junção `products_categories`
  (fonte da verdade). `products.category` (string) é rótulo denormalizado mantido
  só para o filtro do site público. `validate()` sempre reescreve o rótulo a partir
  do `name` da categoria escolhida — revisor deve conferir isso (senão o rótulo
  desatualiza e o filtro do site mente).
- Follow-up (fora deste plano): migrar `shop_controller`/`home.php` do site para
  `attach(['categories'])` e então dropar a coluna `products.category`.
- Interage com **Plano 011 (dashboard)**: "vendas por categoria" deve juntar via
  `products_categories`, não pela string.
