# 002 — Plano de backend do site (público + PIX)

**Commit base:** `47e8535` · **Depende de:** 001 · **Bloqueia:** 004

## Por que isso importa

É o coração do produto: carrinho, checkout transacional e a integração PIX com 3 gateways.
Errar a transação aqui significa vender sem cobrar ou cobrar sem registrar o pedido.

---

## ⚠️ Leia primeiro: as 4 armadilhas do framework LEGGO

Você provavelmente nunca viu este framework. Ele **não é Laravel**. Estas 4 coisas
derrubam quem assume comportamento de framework popular:

### 1. A transação é global por request, e o default é ROLLBACK

`localPDO::getInstance()` abre uma transação e marca `ownsTransaction = true`
(`site/app/inc/lib/localPDO.php:34-42`). O destrutor faz:

```php
public function __destruct()
{
    if ($this->ownsTransaction && $this->inTransaction) {
        $this->rollback();
    }
}
```

Consequência que você **precisa** internalizar:

| Como a rota termina | O que acontece com as escritas |
|---|---|
| `basic_redir($url)` | **COMMIT** |
| `basic_redir($url, rollback: true)` | ROLLBACK explícito |
| `include` de view e fim normal do script (GET) | **ROLLBACK** (destrutor) |
| `json_response(...)` → faz `exit()` | **ROLLBACK** (destrutor) |

Ou seja: **uma rota que grava e responde JSON perde tudo silenciosamente.** Isso atinge
exatamente o webhook do PIX. A regra do projeto ("controllers não chamam commit()") vale
para rotas terminadas em redirect. O webhook é a **única exceção autorizada** deste plano,
e leva comentário explicando por quê.

### 2. `save()` decide INSERT vs UPDATE pelo filtro, não pelo id

`DOLModel::save()` (`lib/DOLModel.php:82`):

```php
$isUpdateFilter = !(count($this->filter) === 1 && ltrim(rtrim($this->filter[0])) === "active = 'yes'");
```

Se o filtro for **exatamente** o default `" active = 'yes' "` → INSERT (retorna
`lastInsertId()`). Qualquer outro filtro → UPDATE. Então:

```php
$order = new orders_model();          // filtro default
$order->populate([...]);
$id = $order->save();                 // INSERT, devolve o id

$order = new orders_model();
$order->set_filter(["idx = ?"], [$id]);  // filtro != default
$order->populate(["status" => "pago"]);
$order->save();                       // UPDATE
```

Exemplo vivo no repo: `manager/app/inc/controller/profiles_controller.php:89-96` (insert)
e `:154-163` (update).

### 3. `populate()` ignora string vazia e colunas fora do schema

`lib/DOLModel.php:157-174`: só grava chaves presentes em `$this->schema` **e** cujo valor
não seja `''`. Passar `''` não zera a coluna — simplesmente não escreve nada. Para gravar
vazio de verdade, use `null`.

### 4. Input do usuário SEMPRE vai por `?` + array de params

`set_filter([" idx = ? "], [$idx])`. Nunca interpole. Nunca.

---

## Contexto obrigatório

- **Rotas** vivem em `site/public_html/index.php` (não em `urls.php`). Assinatura:
  `$dispatcher->add_route("GET"|"POST", "<regex>", "classe:metodo", $guard, $params)`.
  O dispatcher só aceita GET e POST (`lib/Dispatcher.php:78`).
- **Capturas da regex chegam no `$info`.** `Dispatcher::exec()` faz
  `$matches = array_merge($entry["args"], $matches)` (`lib/Dispatcher.php:144`) — então
  o grupo 1 da regex é `$info[1]`.
- **URLs** são montadas em `site/app/inc/urls.php` como variáveis globais, e lidas nos
  controllers com `global $nome_url;` (ver `profiles_controller.php:63`).
- **Views**: o controller inclui `head → header → page → footer → foot`
  (`site/app/inc/controller/site_controller.php:12-16`). Views novas vão em
  `site/public_html/ui/page/`.
- **CSRF**: `validate_csrf($post['_csrf_token'] ?? null, $url_de_volta)` no início de todo
  POST **de formulário**. Tokens têm 10s de graça (`CommonFunctions.php:339`).
- **Mensagens ao usuário**: `$_SESSION["messages_app"]["danger"|"success"] = ["texto"]`.
- **Autoload**: models e controllers carregam por convenção de nome via `m_autoload()`
  (`CommonFunctions.php:7`). Arquivo `x_model.php` → classe `x_model`.
- **Sync obrigatório**: tudo que você criar em `app/inc/lib/` ou `app/inc/model/` tem que
  existir **byte-a-byte idêntico** em `manager/` e `site/`. `bin/check-shared-sync.sh`
  bloqueia o commit se divergirem. Controllers/views/rotas **não** são espelhados.

### Exemplar a seguir

`manager/app/inc/controller/profiles_controller.php` é o melhor exemplo vivo do padrão
POST do repo: valida CSRF, valida campos, `try/catch RuntimeException`, seta `$rollback`,
loga com `Logger::getInstance()->error(...)`, termina em
`basic_redir($url, rollback: $rollback)`. **Copie essa forma.**

---

## Escopo

**Em escopo:**
- `site/app/inc/model/` — 6 models novos (+ cópia idêntica em `manager/app/inc/model/`)
- `site/app/inc/lib/` — 5 arquivos novos (+ cópia idêntica em `manager/app/inc/lib/`)
- `site/app/inc/controller/` — 3 controllers novos
- `site/app/inc/urls.php`, `site/public_html/index.php`
- `site/tests/`

**Fora de escopo — não toque:** `docker/`, `bin/`, `.githooks/`, `kernel.php.example`,
`.github/workflows/`, `auth_controller`, fluxo de login existente, qualquer migration
(plano 001).

---

## Passos

### Passo 1 — Models (6 arquivos × 2 ambientes)

Siga literalmente `site/app/inc/model/users_model.php` — os models deste framework são
declarações magras, sem lógica:

```php
<?php
class users_model extends DOLModel
{
    protected array $field = [" idx ", " name ", " mail ", " login "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("users");
    }
}
```

Crie, com o mesmo formato (`$field` = colunas default do SELECT, `$filter` = sempre
`[" active = 'yes' "]`, construtor passando o nome da tabela):

| Arquivo | Classe | Tabela |
|---|---|---|
| `products_model.php` | `products_model` | `products` |
| `product_images_model.php` | `product_images_model` | `product_images` |
| `payment_gateways_model.php` | `payment_gateways_model` | `payment_gateways` |
| `orders_model.php` | `orders_model` | `orders` |
| `order_items_model.php` | `order_items_model` | `order_items` |
| `pix_charges_model.php` | `pix_charges_model` | `pix_charges` |

**Não** coloque lógica de negócio nos models — não é o padrão do repo. A lógica fica nos
controllers e nas classes de `lib/`.

**Verificação:** `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → 0 erros. E:
```bash
diff -r site/app/inc/model manager/app/inc/model && echo "SYNC OK"
```

### Passo 2 — Carrinho em sessão (`lib/Cart.php`, × 2 ambientes)

O carrinho vive em `$_SESSION[constant("cAppKey")]["cart"]`. `cAppKey` difere por
ambiente (`kernel.php`), o que já evita colisão site↔manager.

Formato:
```php
$_SESSION[constant("cAppKey")]["cart"] = [
    "12:unit" => ["products_id" => 12, "variant" => "unit", "qty" => 2],
    "12:box"  => ["products_id" => 12, "variant" => "box",  "qty" => 1],
];
```
A chave é `"<products_id>:<variant>"` — a mesma dosagem em unidade e em caixa são linhas
distintas do carrinho, exatamente como no card da referência.

🔒 **Regra de segurança inegociável: a sessão NUNCA guarda preço.** Só id, variante e
quantidade. Preço e nome são relidos de `products` a cada render e de novo no checkout.
Guardar preço em sessão é como o comprador acaba escolhendo quanto vai pagar.

API mínima (nada além disto):

```php
class Cart
{
    public static function all(): array;                                  // linhas cruas da sessão
    public static function add(int $productId, string $variant, int $qty): void;
    public static function setQty(int $productId, string $variant, int $qty): void; // qty<=0 remove
    public static function remove(int $productId, string $variant): void;
    public static function clear(): void;
    public static function count(): int;                                  // soma das qty (badge "Pedido N")
    /** Relê products no banco; devolve [linhas com preço/nome, total_cents] */
    public static function hydrate(): array;
}
```

`hydrate()`:
1. Junta os `products_id` do carrinho, faz **um** SELECT com `IN (?,?,…)` e
   `active = 'yes'` (placeholders gerados com `array_fill`, como
   `lib/DOLModel.php:293`). Nada de query por linha.
2. Produto sumido do banco (inativo/removido) → descarta a linha da sessão, silenciosamente.
3. `unit_price_cents` = `price_unit_cents` ou `price_box_cents` conforme `variant`.
   Variante `box` com `price_box_cents IS NULL` → descarta a linha.
4. `line_total_cents = unit_price_cents * qty`; `total_cents` = soma.

`$variant` só aceita `'unit'` ou `'box'` — valide com `in_array($v, ['unit','box'], true)`
na entrada. `$qty` é `(int)` e limitado a `1..99`.

### Passo 3 — Rotas do carrinho

Em `site/app/inc/urls.php` (siga o formato das linhas 2–14):
```php
$cart_url     = sprintf("%s%s", constant("cFrontend"), "carrinho");
$checkout_url = sprintf("%s%s", constant("cFrontend"), "checkout");
$payment_url  = sprintf("%s%s/%s", constant("cFrontend"), "pagamento", "%s");
$done_url     = sprintf("%s%s/%s", constant("cFrontend"), "pedido", "%s");
$product_url  = sprintf("%s%s/%s", constant("cFrontend"), "produto", "%s");
```

Em `site/public_html/index.php`, junto das rotas existentes (antes do bloco final
`if (!$dispatcher->exec())`):

```php
// Vitrine
$dispatcher->add_route("GET",  "/produto/([a-z0-9\-_]+)", "shop_controller:product", null, $params);
// Carrinho
$dispatcher->add_route("GET",  "/carrinho", "cart_controller:index",  null, $params);
$dispatcher->add_route("POST", "/carrinho", "cart_controller:action", null, $params);
```

A home **não ganha rota nova**: a rota `GET /?` já aponta pra `site_controller:home`
(`index.php:92`). Você vai estender esse método no Passo 10.

`cart_controller::action($info)` — despacha por `$post['action']` (`adicionar`,
`atualizar`, `remover`), exatamente como `profiles_controller::action`:
1. `validate_csrf($post['_csrf_token'] ?? null, $cart_url);`
2. Aplica no `Cart`.
3. `basic_redir($cart_url)` — ou de volta pra home quando `action === 'adicionar'`, pra o
   leigo não ser teleportado pra outra tela ao clicar "+ Adicionar ao Pedido".

O carrinho é sessão pura, sem escrita no banco — o commit do `basic_redir` é inofensivo.

### Passo 4 — Interface de gateway (`lib/PixGateway.php`, × 2 ambientes)

```php
interface PixGateway
{
    /**
     * Cria a cobrança PIX no PSP.
     * @return array{
     *   gateway_charge_id: string,
     *   qr_payload: ?string,
     *   qr_image_base64: ?string,
     *   redirect_url: ?string,
     *   expires_at: string   // 'Y-m-d H:i:s'
     * }
     * @throws RuntimeException em qualquer falha de rede/HTTP/payload
     */
    public function createCharge(array $order, array $items): array;

    /** Valida a assinatura do webhook. Body é o RAW de php://input. */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /** Extrai o id da cobrança do payload do webhook, ou null se irreconhecível. */
    public function extractChargeId(string $rawBody, array $query): ?string;

    /** Consulta o PSP: 'pago' | 'pendente' | 'expirado' | 'erro'. Usado pelo job de reconciliação. */
    public function fetchStatus(string $gatewayChargeId): string;
}
```

**Sem SDK, sem dependência Composer.** Use cURL nativo com timeout explícito
(`CURLOPT_TIMEOUT => 10`, `CURLOPT_CONNECTTIMEOUT => 5`) e
`CURLOPT_RETURNTRANSFER => true`. Nunca `file_get_contents` em URL.

Constantes novas de `kernel.php` (documente no PR; **não** edite `kernel.php.example`, está
fora de escopo):
```
MP_ACCESS_TOKEN, MP_WEBHOOK_SECRET
PAGBANK_TOKEN, PAGBANK_API_BASE
INFINITEPAY_HANDLE
```
Toda leitura via `defined('X') ? constant('X') : ''`, e **fail-closed**: credencial vazia
→ `RuntimeException`, jamais cobrança sem credencial.

🔒 Nunca logue token/secret. `Logger::getInstance()->error("...", [...])` só com
`gateway_charge_id`, `orders_id`, código HTTP e mensagem — nunca o header `Authorization`
nem o corpo cru da resposta.

### Passo 5 — `lib/MercadoPagoGateway.php` (× 2 ambientes)

Modo `qr`. Verificado contra a doc oficial em julho/2026 — **confirme antes de codar**, a
API muda:

- `POST https://api.mercadopago.com/v1/payments`
- Headers: `Authorization: Bearer <MP_ACCESS_TOKEN>`, `Content-Type: application/json`,
  `X-Idempotency-Key: <orders.token>` ← reusar o token do pedido como chave de
  idempotência impede cobrança dupla em retry de rede.
- Body: `transaction_amount` (decimal — `round($total_cents / 100, 2)`),
  `description`, `payment_method_id: "pix"`, `payer: {email, first_name}`,
  `notification_url`, `date_of_expiration` (ISO8601 com offset).
- Resposta: `id` → `gateway_charge_id`;
  `point_of_interaction.transaction_data.qr_code` → `qr_payload`;
  `point_of_interaction.transaction_data.qr_code_base64` → `qr_image_base64`.
- `verifyWebhook`: header `x-signature` no formato `ts=<ts>,v1=<hash>`. Manifest =
  `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` → HMAC-SHA256 com `MP_WEBHOOK_SECRET`.
  Compare com **`hash_equals`**, nunca `==`.
- `fetchStatus`: `GET /v1/payments/{id}` → `status === 'approved'` ⇒ `'pago'`.

### Passo 6 — `lib/PagBankGateway.php` (× 2 ambientes)

Modo `qr`.

- `POST {PAGBANK_API_BASE}/orders` (`https://sandbox.api.pagseguro.com` em teste).
- `Authorization: Bearer <PAGBANK_TOKEN>`.
- Body: `reference_id` (= `orders.token`), `customer`, `items[]`,
  `qr_codes: [{amount: {value: <centavos>}, expiration_date: <ISO8601>}]`,
  `notification_urls: [<url>]`. ⚠️ PagBank recebe **centavos inteiros** aqui, ao contrário
  do MP que quer decimal. Não unifique.
- Resposta: `qr_codes[0].id` → `gateway_charge_id`; `qr_codes[0].text` → `qr_payload`;
  o link com `rel = "QRCODE.PNG"` em `qr_codes[0].links[]` → baixe e faça `base64_encode`
  para `qr_image_base64`.
- `verifyWebhook`: header `x-authenticity-token` = `hash('sha256', $token . '-' . $rawBody)`.
  **O body tem que ser o RAW, sem reformatar** — um espaço a mais e o hash diverge.
  Compare com `hash_equals`.

✅ **Decisão do dono (2026-07-15): CPF é campo obrigatório do checkout, padrão para todo
comprador** — não só quem cai no PagBank. Isto desbloqueia o Passo 6. Envie `customer.tax_id`
neste adapter usando o valor validado no Passo 9.

Trabalho adicional que essa decisão exige, fora deste passo:

- **Migration nova** `migrations/015_add_customer_cpf_to_orders.sql` — `ALTER TABLE orders
  ADD COLUMN customer_cpf CHAR(11) NOT NULL AFTER customer_phone;` (padrão do repo: só
  dígitos, sem máscara — a view formata na exibição, igual `total_cents`). Idempotente:
  envolva em `migrations_log` como as demais (ver `migrations/014_create_table_pix_charges.sql`
  pro padrão exato do arquivo).
- **`site/app/inc/model/orders_model.php`** (e a cópia idêntica em `manager/`) — adicionar
  `" customer_cpf "` ao array `$field`. **Nota lateral encontrada nesta revisão:** o `$field`
  atual (linha 4) já está incompleto — falta `customer_phone` e todos os `ship_*` — não é
  escopo desta decisão, mas quem retomar o Passo 6 vai precisar desses campos também pro
  `finalize()` do Passo 9 gravar o pedido; considere corrigir a lista completa no mesmo commit
  da migration, já que os dois tocam o mesmo model.
- **Passo 9, validação de campos** — adicionar CPF: 11 dígitos após
  `preg_replace('/\D/', '', ...)`. Validação de dígito verificador é bônus, não bloqueante
  (o gateway rejeita CPF inválido na chamada; não precisa duplicar a regra aqui).
- **`lib/MercadoPagoGateway.php` (Passo 5)** — o payload de exemplo do MP inclui
  `payer.identification: {type: "CPF", number: <cpf>}`. Envie também lá, mesmo a doc do MP
  não marcando como obrigatório — mantém os dois adapters QR consistentes.
- **`lib/InfinitePayGateway.php` (Passo 7)** — não envia CPF: o payload documentado
  (`handle`, `redirect_url`, `webhook_url`, `order_nsu`, `items`) não tem campo de cliente.
  Nada a mudar aqui; o CPF ainda é salvo em `orders.customer_cpf`, só não trafega pro
  checkout hospedado.

### Passo 7 — `lib/InfinitePayGateway.php` (× 2 ambientes)

Modo `redirect`. **InfinitePay não tem API de PIX inline** — só checkout hospedado.

- `POST https://api.checkout.infinitepay.io/links`
- Body: `handle` (= `INFINITEPAY_HANDLE`), `redirect_url` (= `$done_url` do pedido),
  `webhook_url`, `order_nsu` (= `orders.token`), `items: [{quantity, price, description}]`.
- Resposta: `{"url": "..."}` → `redirect_url`. `gateway_charge_id` = `orders.token`
  (é o único identificador que atravessa ida e volta). `qr_payload` e `qr_image_base64`
  ficam **NULL**.
- `verifyWebhook`: **a assinatura não é documentada publicamente.** Portanto:
  ```php
  public function verifyWebhook(string $rawBody, array $headers): bool
  {
      // InfinitePay não documenta assinatura de webhook (contato: parcerias@cloudwalk.io).
      // Autenticação real acontece na camada de negócio: o order_nsu é o token opaco de
      // 32 chars do pedido (não adivinhável) e o valor pago é conferido contra
      // orders.total_cents antes de marcar como pago. Ver 002 Passo 8.
      return true;
  }
  ```
  E aí o Passo 8 **obriga** a conferência de valor. Sem assinatura, o token não-adivinhável
  + a checagem de valor são a defesa. Documente esse tradeoff no PR.
- `fetchStatus`: sem endpoint público de consulta → devolva `'pendente'` e logue um
  `warning`. Consequência honesta: **pedido InfinitePay não tem fallback de
  reconciliação**; se o webhook não chegar, o pedido expira. Registre isso no PR.

### Passo 8 — Roteamento entre gateways (`lib/GatewayRouter.php`, × 2 ambientes)

```php
final class GatewayRouter
{
    /** @return array{idx:int, slug:string, mode:string} */
    public static function pick(): array;
}
```

Algoritmo (exatamente isto — nada de "melhorar"):

1. Carrega `payment_gateways` com `active='yes' AND enabled='yes'`. Vazio →
   `RuntimeException("nenhum gateway habilitado")`.
2. Faturamento do mês corrente por gateway — pedidos **pagos**, uma query só:
   ```sql
   SELECT c.payment_gateways_id AS g, COALESCE(SUM(o.total_cents), 0) AS mtd
     FROM pix_charges c
     JOIN orders o ON o.idx = c.orders_id
    WHERE c.active = 'yes'
      AND o.status = 'pago'
      AND o.paid_at >= ?     -- primeiro dia do mês 00:00:00
    GROUP BY c.payment_gateways_id
   ```
   Use `execute_raw_prepared` (`lib/DOLModel.php:263`) — o JOIN não cabe no `set_filter`.
3. `headroom = max(0, monthly_limit_cents - mtd)`.
4. **Algum headroom > 0** → sorteio ponderado pelo headroom (quem tem mais folga recebe
   mais): sorteia `random_int(1, sum(headroom))` e caminha a soma acumulada.
   `random_int`, **não** `rand`/`mt_rand`.
5. **Todos com headroom = 0** → escolhe o de **menor** `mtd / max(1, monthly_limit_cents)`
   e loga:
   ```php
   Logger::getInstance()->warning("Todos os gateways estouraram o limite mensal", [
       "escolhido" => $slug,
   ]);
   ```
   **Nunca bloqueia a venda** — o limite é meta de equilíbrio, não trava. (Decisão do dono.)
6. `monthly_limit_cents = 0` conta como headroom 0 → só é escolhido no fallback do passo 5.

### Passo 9 — Checkout transacional (`checkout_controller`)

Rotas em `site/public_html/index.php`:
```php
$dispatcher->add_route("GET",  "/checkout",              "checkout_controller:index",    null, $params);
$dispatcher->add_route("POST", "/checkout",              "checkout_controller:finalize", null, $params);
$dispatcher->add_route("GET",  "/pagamento/([a-f0-9]{32})", "checkout_controller:payment", null, $params);
$dispatcher->add_route("GET",  "/pedido/([a-f0-9]{32})",    "checkout_controller:done",    null, $params);
```
A regex `[a-f0-9]{32}` casa exatamente o formato de `random_token(16)` — filtra lixo antes
mesmo de tocar o banco. O token chega como `$info[1]`.

**`finalize($info)` é a única rota que grava o pedido. Toda ela roda dentro da transação
global e é commitada pelo `basic_redir` final.** Ordem obrigatória:

1. `validate_csrf($post['_csrf_token'] ?? null, $checkout_url);`
2. `Cart::hydrate()`. Carrinho vazio → mensagem + `basic_redir($cart_url)`.
3. Valida os campos (nome, e-mail via `filter_var(..., FILTER_VALIDATE_EMAIL)`, telefone
   com 10–11 dígitos após `preg_replace('/\D/', '', ...)`, **CPF com 11 dígitos após
   `preg_replace('/\D/', '', ...)`** — campo obrigatório, decisão do dono 2026-07-15 —,
   CEP com 8 dígitos, rua, número, bairro, cidade, UF ∈
   `array_keys($GLOBALS['ufbr_lists'])`). Erro → mensagem +
   `basic_redir($checkout_url)`. A view repopula com `old()` (`CommonFunctions.php:752`).
4. **Trava o estoque** — as linhas do carrinho, uma query, `FOR UPDATE`:
   ```php
   $stmt = $model->execute_raw_prepared(
       "SELECT idx, stock, price_unit_cents, price_box_cents, box_qty
          FROM products WHERE active = 'yes' AND idx IN ($placeholders) FOR UPDATE",
       $ids
   );
   ```
   Sem `FOR UPDATE`, dois compradores simultâneos vendem o mesmo último frasco.
5. Confere estoque por linha: unidades necessárias = `qty` (variant `unit`) ou
   `qty * box_qty` (variant `box`). Faltou → mensagem clara e humana ("Ipamorelin: só
   restam 3 unidades.") + `basic_redir($cart_url)`.
6. **Reconfere o preço contra o banco** (o `hydrate` do passo 2 e este passo veem a mesma
   transação — isto é a defesa contra adulteração de preço).
7. Baixa o estoque: `UPDATE products SET stock = stock - ? WHERE idx = ?` por linha.
8. INSERT em `orders`: `token = random_token(16)`, `status = 'aguardando_pagamento'`,
   `customer_cpf` (validado no passo 3, ver migration 015 no Passo 6),
   `total_cents` recalculado do banco, `expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'))`.
9. INSERT em `order_items` (snapshots de `product_name` e `unit_price_cents`).
10. `GatewayRouter::pick()` → `$gateway->createCharge($order, $items)` →
    INSERT em `pix_charges`.
11. Enfileira o e-mail com o link `$done_url` — copie o padrão de
    `auth_controller.php:159-176`: `EmailProducer::getInstance()->send(...)` dentro de
    `try/catch` (Kafka é fail-open, e-mail que falha **não** derruba o pedido), seguido de
    `messages_model` com `redact_email_body($body)`.
12. `basic_redir(sprintf($payment_url, $token));` ← **o COMMIT de tudo acima.**

**Se `createCharge` lançar** (PSP fora do ar): capture, logue, mensagem
`"Não conseguimos gerar seu PIX agora. Tente de novo em instantes."` e
`basic_redir($checkout_url, rollback: true)` — o rollback devolve o estoque e o pedido
some. Não deixe pedido órfão sem cobrança.

> **Por que a chamada HTTP ao PSP fica dentro da transação?** Porque a alternativa
> (commitar o pedido e criar a cobrança num GET depois) exigiria `commit()` manual num GET,
> violando a convenção do framework, e abriria janela de pedido sem cobrança. O custo é
> segurar a transação por ~1–2s no volume desta loja. Tradeoff consciente — não "conserte".

`payment($info)` e `done($info)` são **somente leitura**: carregam o pedido por
`set_filter([" token = ? "], [$info[1]])`, e incluem as views. Token inválido → mensagem +
`basic_redir($home_url)`. Nunca exponha `orders.idx` na URL.

### Passo 10 — Home, produto e polling de status

- `site_controller::home()` (já existe, `site_controller.php:4-17`) passa a carregar:
  produtos `active='yes'` ordenados por `sort_order, name`; a capa de cada um via
  `join()` em `product_images` (`fw_key: ['products_id' => 'idx']` — batch, sem N+1);
  a lista de categorias (`SELECT DISTINCT category`); e `Cart::count()` pro badge.
  Busca (`?q=`) e filtro (`?cat=`) entram como `set_filter` com `?`. **Não** apague o
  código de `auth_controller::check_login()` que já está lá.
- `shop_controller::product($info)` — `$info[1]` é o slug; valida com `valid_slug()`
  (`CommonFunctions.php:852`). Não achou → `basic_redir($home_url)`.
- **Polling**: `GET /pagamento/<token>/status` → `checkout_controller:status`.
  ```php
  $dispatcher->add_route("GET", "/pagamento/([a-f0-9]{32})/status", "checkout_controller:status", null, $params);
  ```
  **Somente leitura** do nosso banco: devolve `json_response(["status" => $order['status']])`.
  Não chame o PSP daqui — o comprador faz F5, um bot faz 1000; quem fala com o PSP é o
  webhook (tempo real) e o job de reconciliação (fallback). Como não grava nada, o
  rollback do destrutor após o `exit()` do `json_response` é inofensivo.

### Passo 11 — Webhook (a exceção do commit)

```php
$dispatcher->add_route("POST", "/webhook/pix/(mercadopago|pagbank|infinitepay)", "webhook_controller:receive", null, $params);
```

**`webhook_controller::receive($info)` — NÃO chame `validate_csrf` aqui.** Não há sessão do
comprador, e o PSP não tem como mandar token. A autenticidade vem da assinatura.

1. `$rawBody = file_get_contents('php://input');` — **obrigatório**. `$_POST` fica vazio
   com `Content-Type: application/json`, e o PagBank exige o body cru byte-a-byte.
2. `$gateway` = adapter conforme `$info[1]`.
3. `if (!$gateway->verifyWebhook($rawBody, getallheaders())) { json_response(['error' => 'invalid signature'], 401); }`
4. `extractChargeId()` → localiza `pix_charges` por
   `(payment_gateways_id, gateway_charge_id)`. Não achou → responda **200** com
   `['ignored' => true]`. 404 faz o PSP entrar em retry infinito por um evento que nunca
   será nosso.
5. Já `status = 'pago'` → `json_response(['ok' => true])`. **Idempotência**: reentrega é
   normal, não é erro.
6. Confirma o pagamento no PSP (`fetchStatus`) quando o gateway suporta — nunca confie só
   no corpo do webhook.
7. 🔒 **Confere o valor**: valor pago `>= orders.total_cents`. Menor → **não marca pago**,
   loga `warning` e responde 200. Esta é a única defesa real no InfinitePay, que não tem
   assinatura.
8. Grava: `pix_charges.status='pago'`/`paid_at`, `orders.status='pago'`/`paid_at`.
9. **COMMIT EXPLÍCITO — a exceção:**
   ```php
   // localPDO abre a transação no início do request e o __destruct() faz rollback se
   // ninguém commitou. Rotas normais commitam via basic_redir(); um webhook responde
   // JSON e sai por exit(), então SEM este commit a confirmação do pagamento seria
   // silenciosamente descartada. Única rota do site autorizada a commitar na mão.
   $order->commit();
   json_response(["ok" => true]);
   ```
   Ordem importa: **commit ANTES do `json_response`** (ele faz `exit()`).
10. Falhou no meio → `Logger::getInstance()->error(...)` e `json_response(['error' => '...'], 500)`
    **sem commit** (o destrutor reverte) → o PSP reentrega. Isso é o comportamento certo.

### Passo 12 — Expiração e reconciliação (⚠️ escopo)

Dois jobs CLI, espelhando `site/cgi-bin/run_migrations.php`:

- `site/cgi-bin/pix_expire.php` — `orders` com `status='aguardando_pagamento' AND
  expires_at < NOW()` → `status='expirado'`, `pix_charges.status='expirado'`, e
  **devolve o estoque** (`UPDATE products SET stock = stock + ?`). Uma transação por
  pedido, com `commit()` explícito (é CLI, não há `basic_redir`). Idempotente: só toca
  quem ainda está `aguardando_pagamento`.
- `site/cgi-bin/pix_reconcile.php` — fallback de polling: para cada `pix_charges` pendente
  e não vencida, `fetchStatus()`; `'pago'` → mesma transição do webhook (reaproveite o
  código, não duplique a regra de conferência de valor).

🛑 **PARE ANTES DE CRIAR ESTES DOIS ARQUIVOS.** `site/cgi-bin/` **não está** na lista de
diretórios autorizados do projeto, e o agendamento (cron) vive em `docker/`, explicitamente
proibido. Peça aprovação de escopo. Se for negada: reporte que a expiração com devolução de
estoque **não tem onde morar** — e não tente contornar com expiração preguiçosa no request
do comprador (pedido abandonado nunca mais é lido, e o estoque fica preso pra sempre).

---

## Critérios de aceite (binários)

```bash
cd site    && php app/inc/lib/vendor/bin/phpstan analyse   # 0 erros
cd manager && php app/inc/lib/vendor/bin/phpstan analyse   # 0 erros
diff -r site/app/inc/lib   manager/app/inc/lib   && echo "LIB SYNC OK"
diff -r site/app/inc/model manager/app/inc/model && echo "MODEL SYNC OK"
bash bin/check-shared-sync.sh                              # exit 0
bin/test.sh                                                # tudo verde
```

1. Os 6 comandos acima passam.
2. `grep -rn "validate_csrf" site/app/inc/controller/webhook_controller.php` → **vazio**.
3. `grep -n "commit()" site/app/inc/controller/webhook_controller.php` → **exatamente 1**
   ocorrência, e ela vem **antes** do `json_response` final.
4. `grep -rn "price" site/app/inc/lib/Cart.php` → nenhuma escrita de preço em
   `$_SESSION`.
5. `grep -rn "mt_rand\|rand(" site/app/inc/lib/GatewayRouter.php` → **vazio**
   (só `random_int`).
6. Fluxo manual: home → adicionar → carrinho → checkout → tela de PIX com QR →
   `curl` no webhook com assinatura válida → `GET /pagamento/<token>/status` devolve
   `{"status":"pago"}` **numa requisição nova** (prova que commitou de verdade).

## Teste

Novos testes em `site/tests/`. DB → estende `DBTestCase` (`site/tests/DBTestCase.php`:
transação no `setUp`, rollback no `tearDown`); sem DB → `TestCase` puro. Siga
`site/tests/UsersModelTest.php` (model) e `site/tests/CommonFunctionsTest.php` (helper).

| Arquivo | Cobre |
|---|---|
| `CartTest.php` | add/setQty/remove/count; `qty <= 0` remove; variante inválida rejeitada; **preço nunca entra na sessão** |
| `CartHydrateTest.php` (DB) | preço vem do banco; produto inativo some da linha; `box` com `price_box_cents` NULL some; total bate |
| `GatewayRouterTest.php` (DB) | só sorteia `enabled='yes'`; headroom 0 em todos → menor utilização + warning; distribuição respeita o peso (rode `pick()` 1000× e confira as faixas) |
| `CheckoutStockTest.php` (DB) | estoque insuficiente não cria pedido; `box` baixa `qty * box_qty`; preço adulterado no POST é ignorado |
| `WebhookIdempotencyTest.php` (DB) | mesmo evento 2× → 1 transição; assinatura inválida → 401; valor menor que o total → não marca pago |

Assinaturas de webhook: teste `verifyWebhook` com HMAC calculado no próprio teste. **Não
faça teste que bate na rede** — a suíte roda no CI.

## Manutenção

- 4º gateway = 1 classe implementando `PixGateway` + 1 `INSERT IGNORE` numa migration nova.
  Se você precisar tocar em `GatewayRouter` para adicionar um gateway, a interface está
  errada.
- Ao revisar: `validate_csrf` em todo POST **exceto** webhook; `commit()` manual só no
  webhook e nos CLI; nenhum preço vindo de `$_POST` ou de `$_SESSION`; todo input em `?`.
- Mexer em `Cart::hydrate()` ou em `finalize()` é mexer em dinheiro. Peça revisão humana.

## Escape hatches — pare e reporte, não improvise

- ~~PagBank exigir CPF (Passo 6) → **pare**.~~ Resolvido 2026-07-15: CPF é campo obrigatório
  padrão para todo comprador. Ver nota no Passo 6.
- `site/cgi-bin/` fora de escopo (Passo 12) → **pare**.
- Se a API de qualquer PSP não bater com o descrito aqui (foi verificado em julho/2026),
  **pare e reporte a divergência** — não adivinhe campo de resposta.
- Se você sentir vontade de instalar um pacote Composer (QR code, SDK de PSP, cliente
  HTTP), **pare**: o desenho inteiro existe pra evitar isso. MP e PagBank já devolvem o
  PNG em base64.
- Se `bin/check-shared-sync.sh` acusar divergência e a "correção" óbvia for editar o guard
  ou o hook, **pare** — `bin/` e `.githooks/` estão proibidos.
