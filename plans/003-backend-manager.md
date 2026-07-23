# 003 — Plano de backend do manager (CRUD de produtos + pedidos)

**Commit base:** `47e8535` · **Depende de:** 001 · **Bloqueia:** nada

## Por que isso importa

Sem isto o dono da loja não cadastra produto nem vê pedido — a vitrine fica vazia e o
faturamento fica invisível. É também onde ele configura os limites mensais que o
`GatewayRouter` (plano 002) consome.

## Contexto obrigatório

### O exemplar a copiar

`manager/app/inc/controller/profiles_controller.php` **é o molde deste plano inteiro**.
Leia o arquivo inteiro antes de codar. Ele já resolve tudo que você precisa:

- `index()`: gera CSRF se faltar (`:12-14`), pagina com `$perPage = 25` +
  `set_paginate([$offset, $perPage])` (`:16-32`), conta o total com
  `execute_raw_prepared` (`:26-27`), `try/catch RuntimeException` com fallback vazio
  (`:44-48`), declara `$alpineControllers` (`:52`), inclui as 5 views (`:54-58`).
- `action()`: `global $x_url` (`:63`), `validate_csrf` primeiro (`:69`), despacha por
  `$post['action']` (`criar`/`editar`/`remover`), valida, `try/catch` setando `$rollback`,
  `Logger::getInstance()->error(...)`, e fecha em `basic_redir($url, rollback: $rollback)`.

**Não invente estrutura nova.** Se você está escrevendo algo que não tem paralelo nesse
arquivo, provavelmente está errado.

### Guard de autenticação

Toda rota nova é `$authGuard` — já definido em `manager/public_html/index.php`:
```php
$authGuard = fn() => auth_controller::check_login();
```
É o 4º argumento do `add_route`. Rota sem guard = painel aberto pra internet.
`Dispatcher::exec()` redireciona pro `login_url` quando o guard falha
(`lib/Dispatcher.php:108-113`).

### Armadilhas do framework

Elas são as mesmas do plano 002 e valem aqui inteiras — **leia
`plans/002-backend-site.md`, seção "as 4 armadilhas"**, principalmente:
- `save()` decide INSERT vs UPDATE **pelo filtro** (default `" active = 'yes' "` = INSERT);
- `populate()` ignora `''`;
- `basic_redir` commita, fim normal de GET faz rollback;
- input do usuário sempre por `?` + params.

### Upload

`handle_upload(array $file, string $subDir, array $options = []): string|false`
(`CommonFunctions.php:524`). Já valida `is_uploaded_file`, `finfo` do MIME real,
travessia em `$subDir`, tamanho, e converte para WebP/AVIF. Devolve o caminho ou `false`.
**Não escreva validação de upload à mão** — está toda ali.

## Escopo

**Em escopo:** `manager/app/inc/controller/`, `manager/app/inc/urls.php`,
`manager/public_html/index.php`, `manager/public_html/ui/page/`,
`manager/public_html/assets/js/alpine/`, `manager/tests/`.

**Fora de escopo — não toque:** `manager/app/inc/model/` e `manager/app/inc/lib/` (são do
plano 002 e chegam aqui **prontos e idênticos** ao `site/` — copiar, nunca editar de um
lado só), `auth_controller`, `profiles_controller`, `docker/`, `bin/`, `.githooks/`.

## Passos

### Passo 1 — URLs e rotas

`manager/app/inc/urls.php`:
```php
$products_url = sprintf("%s%s", constant("cFrontend"), "produtos");
$orders_url   = sprintf("%s%s", constant("cFrontend"), "pedidos");
$order_url    = sprintf("%s%s/%s", constant("cFrontend"), "pedidos", "%d");
$gateways_url = sprintf("%s%s", constant("cFrontend"), "gateways");
```

`manager/public_html/index.php`, junto das rotas existentes (siga o bloco de `/perfis`):
```php
// Produtos
$dispatcher->add_route("GET",  "/produtos", "products_controller:index",  $authGuard, $params);
$dispatcher->add_route("POST", "/produtos", "products_controller:action", $authGuard, $params);

// Pedidos
$dispatcher->add_route("GET",  "/pedidos",         "orders_controller:index", $authGuard, $params);
$dispatcher->add_route("GET",  "/pedidos/([0-9]+)", "orders_controller:show",  $authGuard, $params);

// Gateways de pagamento
$dispatcher->add_route("GET",  "/gateways", "gateways_controller:index",  $authGuard, $params);
$dispatcher->add_route("POST", "/gateways", "gateways_controller:action", $authGuard, $params);
```
Os 6 `$authGuard` não são opcionais.

### Passo 2 — `products_controller`

`index($info)` — clone estrutural de `profiles_controller::index`: CSRF, paginação de 25,
`set_order([" sort_order ASC ", " name ASC "])`, capa via `join()` em `product_images`
(`fw_key: ['products_id' => 'idx']`), `try/catch` com fallback vazio, 5 includes.

`action($info)` — `validate_csrf($post['_csrf_token'] ?? null, $products_url)` **primeiro**,
depois despacha `criar` / `editar` / `remover`.

Validações (mensagem em PT-BR clara, `basic_redir($products_url)` em cada falha):

| Campo | Regra |
|---|---|
| `name` | obrigatório, `trim` |
| `slug` | obrigatório + `valid_slug($slug)` (`CommonFunctions.php:852`). Vazio → derive com `generate_slug($name)` (`:92`) |
| `category` | obrigatório |
| `price_unit_cents` | inteiro > 0 — converta de "R$ 70,00" com `preg_replace('/\D/', '', $v)`, **nunca** `floatval` |
| `price_box_cents` | opcional; vazio → grave `null` (lembre: `populate()` ignora `''`) |
| `box_qty` | inteiro ≥ 1, default 10 |
| `stock` | inteiro ≥ 0 |

`remover` → `$model->set_filter(["idx = ?"], [$idx]); $model->remove();` (soft-delete).
**Nunca `DELETE FROM`.** Remover produto **não** apaga `order_items` — o snapshot é o
histórico do pedido (ver plano 001, Passo 5).

Upload das fotos, dentro do mesmo `try`:
```php
foreach ($_FILES['photos']['name'] as $i => $_) {
    $file = ['name' => ..., 'type' => ..., 'tmp_name' => ..., 'error' => ..., 'size' => ...];
    $path = handle_upload($file, 'products', ['convert' => 'webp', 'max_width' => 1200, 'quality' => 80]);
    if ($path === false) { continue; }   // handle_upload já logou o motivo
    // INSERT em product_images: products_id, path, is_cover, sort_order
}
```
Só a primeira imagem do produto nasce `is_cover = 'yes'`. `$_FILES` com `multiple` vem em
arrays paralelos — remonte item a item como acima; passar `$_FILES['photos']` direto **não
funciona**.

⚠️ `handle_upload` grava em `UPLOAD_DIR` (`kernel.php`) = `.../public_html/assets/upload/`.
`product_images.path` guarda o **caminho relativo** que a view prefixa com `cAssets`.
Nunca grave caminho absoluto no banco — quebra ao trocar de ambiente.

### Passo 3 — `orders_controller` (somente leitura)

`index($info)`:
- Paginação de 25, `set_order([" created_at DESC "])`.
- Filtro opcional por status: `?status=pago` → `set_filter([" active = 'yes' ", " status = ? "], [$status])`.
  **Valide contra a lista fixa** `['aguardando_pagamento','pago','cancelado','expirado']`
  antes de bindar; valor fora da lista → ignore o filtro.
- Campos: `idx`, `token`, `customer_name`, `status`, `total_cents`, `created_at`,
  `paid_at`.
- Exiba `substr($token, 0, 8)` na listagem, não o token inteiro — ele é a credencial de
  acesso do comprador ao pedido.

`show($info)` — `$info[1]` é o `idx` (rota `([0-9]+)`; aqui é área autenticada, o token
opaco não faz falta):
- pedido + `order_items` (`join`, `fw_key: ['orders_id' => 'idx']`) + `pix_charges`.
- Mostra: dados do comprador, endereço, itens com preço snapshot, total, status, gateway
  usado (`payment_gateways.name`), `gateway_charge_id`, `expires_at`, `paid_at`.
- **Não** renderize `qr_payload` nem `qr_image_base64` — é o meio de pagamento do
  comprador, não tem uso no admin.

**Sem ações de escrita nesta tela.** Nada de "marcar como pago" na mão: quem transiciona
status é o webhook e o job de reconciliação (plano 002). Um botão manual aqui é a porta
mais fácil pra marcar pedido pago sem dinheiro ter entrado. Se o dono pedir, é escopo novo
e decisão dele — **pare e pergunte**.

### Passo 4 — `gateways_controller`

`index($info)` — lista os 3 gateways com: `name`, `slug`, `mode`, `enabled`,
`monthly_limit_cents`, e o **faturamento do mês corrente** por gateway (reuse a query do
`GatewayRouter`, plano 002 Passo 8) + a % de utilização.

`action($info)` — só `editar`. Campos gravaveis: **apenas `enabled` e
`monthly_limit_cents`**.

🔒 `slug` e `mode` são **somente leitura**. `slug` é o que amarra a linha ao adapter PHP
(`GatewayRouter` → `MercadoPagoGateway`); `mode` é o que a tela de pagamento usa pra
escolher entre QR e redirect. Editáveis, viram bug de roteamento silencioso. Trate-os como
`profiles_controller` trata `adm` — exibe, nunca lê do `$_POST`, e diz isso num comentário
no topo da classe (padrão em `profiles_controller.php:4-9`).

`monthly_limit_cents` chega como "R$ 50.000,00" → `preg_replace('/\D/', '', $v)`, valide
`>= 0`. Zero é válido: significa "só me use se todos os outros estourarem".

Nunca exiba nem colete token/secret aqui — credencial mora em `kernel.php`.

### Passo 5 — Views e Alpine

Views em `manager/public_html/ui/page/`: `products.php`, `orders.php`, `order_detail.php`,
`gateways.php`. Copie a estrutura de `manager/public_html/ui/page/profiles.php`.

Alpine em `manager/public_html/assets/js/alpine/` (siga os controllers existentes), e
declare no controller PHP: `$alpineControllers = ['products'];` (padrão em
`profiles_controller.php:52`).

🔒 **CSP:** o site define `script-src 'self' 'nonce-...'` (`index.php`, header CSP). Todo
`<script>` inline precisa de `nonce="<?= $GLOBALS['cspNonce'] ?>"`, senão o browser bloqueia
silenciosamente. Prefira arquivo `.js` externo.

🔒 **Escape:** todo dado de pedido (nome, endereço, e-mail) é **input do comprador** e vai
pra tela do admin. `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` em **tudo**. Um
`customer_name` com `<script>` é XSS armazenado direto no painel. Este é o risco mais alto
deste plano.

### Passo 6 — Menu

Adicione "Produtos", "Pedidos" e "Gateways" à navegação em
`manager/public_html/ui/common/header.php`, seguindo os itens já existentes.

## Critérios de aceite (binários)

```bash
cd manager && php app/inc/lib/vendor/bin/phpstan analyse   # 0 erros
bash bin/check-shared-sync.sh                              # exit 0
bin/test.sh                                                # verde
```

1. Os 3 comandos passam.
2. Toda rota nova tem guard:
   ```bash
   grep -n "add_route" manager/public_html/index.php | grep -E "produtos|pedidos|gateways"
   ```
   → **todas** as linhas contêm `$authGuard`.
3. `curl -s -o /dev/null -w "%{http_code} %{redirect_url}" http://manager.infinnityimportacao.local/produtos`
   deslogado → redireciona pro login. Nunca 200.
4. `grep -rn "DELETE FROM" manager/app/inc/controller/` → **vazio**.
5. `grep -n "slug\|mode" manager/app/inc/controller/gateways_controller.php` → nenhuma
   leitura de `$post['slug']` nem `$post['mode']`.
6. Manual: criar produto com 2 fotos → aparece na home do site com a capa certa; editar
   preço → o pedido antigo continua mostrando o preço antigo (prova do snapshot);
   remover produto → some da home, `active='no'` no banco (a linha continua lá).

## Teste

`manager/tests/`, seguindo `manager/tests/MessagesFilterTest.php` (filtro) e
`manager/tests/UsersModelTest.php` (model, `DBTestCase`).

| Arquivo | Cobre |
|---|---|
| `ProductsValidationTest.php` | "R$ 70,00" → `7000`; `price_box_cents` vazio → `null`; slug inválido rejeitado; slug derivado do nome |
| `OrdersFilterTest.php` (DB) | filtro de status válido filtra; status inválido é ignorado (não quebra, não injeta) |
| `GatewaysActionTest.php` (DB) | `enabled`/`monthly_limit_cents` gravam; `slug`/`mode` **não** gravam mesmo se forjados no POST |

## Manutenção

- Coluna nova em `products` = migration nova + `$field` do model (nos **dois** ambientes) +
  form + validação. `bin/check-shared-sync.sh` pega o esquecimento do model.
- Ao revisar: guard em toda rota; `htmlspecialchars` em todo dado do comprador; nenhuma
  transição manual de status de pedido; nenhum `DELETE FROM`.

## Escape hatches — pare e reporte, não improvise

- Pedido de "marcar pago manualmente" (Passo 3) → **pare**.
- Se `bin/check-shared-sync.sh` reclamar, a correção é **copiar** o arquivo do outro
  ambiente — nunca editar o guard nem o hook (`bin/` e `.githooks/` proibidos).
- Se você precisar editar `app/inc/model/` ou `app/inc/lib/` aqui, **pare**: eles são
  do plano 002 e a edição tem que acontecer nos dois lados juntos.
