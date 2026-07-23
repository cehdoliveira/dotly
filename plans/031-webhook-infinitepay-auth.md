# Plan 031: Fechar a auto-aprovação de pagamento no webhook InfinitePay (via `payment_check`)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0c3158b..HEAD -- site/app/inc/lib/InfinitePayGateway.php manager/app/inc/lib/InfinitePayGateway.php site/app/inc/controller/webhook_controller.php site/tests/InfinitePayGatewayTest.php site/tests/WebhookIdempotencyTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `0c3158b`, 2026-07-20 (reescrito 2026-07-20 — abordagem trocada de allowlist de IP para reconfirmação via `payment_check`)

## Histórico da mudança de abordagem (leia antes de tudo)

A versão anterior deste plano fechava o vetor com uma **allowlist de IP de
origem** (`INFINITEPAY_WEBHOOK_IPS` no `kernel.php`), porque se acreditava que a
InfinitePay **não tinha endpoint público de consulta de status**. Essa premissa
estava **errada**. A documentação pública do Checkout Integrado da InfinitePay
(central de ajuda + `infinitepay.io/checkout-documentacao`, confirmado em 3 fontes
independentes em 2026-07-20) documenta o endpoint:

```
POST https://api.checkout.infinitepay.io/payment_check
```

Corpo da requisição:
```json
{ "handle": "sua_infinite_tag", "order_nsu": "123456", "transaction_nsu": "UUID-que-recebeu", "slug": "codigo-da-fatura" }
```

Resposta:
```json
{ "success": true, "paid": true, "amount": 1500, "paid_amount": 1510, "installments": 1, "capture_method": "pix" }
```

Isso permite fechar o vetor do **mesmo jeito que MercadoPago e PagBank já fazem**:
não confiar no corpo do webhook, e sim **reconfirmar no PSP** antes de marcar como
pago. É mais forte que a allowlist de IP (não depende do trust boundary da rede
Docker compartilhada, nem de obter os ranges de IP reais da InfinitePay), então
**este plano abandona a allowlist de IP** e implementa a reconfirmação.

> **Nota de infra (já resolvida, fora do escopo deste plano):** a config de nginx
> (`docker/interface/default.conf`) foi ajustada em separado para reescrever
> `REMOTE_ADDR` a partir de `X-Forwarded-For` confiando na rede `dotskynet`
> (`set_real_ip_from 10.0.1.0/24; real_ip_header X-Forwarded-For;`). Isso corrige
> um bug independente de rate-limit por IP e **não** é pré-requisito deste plano
> nem deve ser tocado por ele.

## Why this matters

Hoje qualquer pessoa pode marcar o próprio pedido InfinitePay como **pago sem
pagar**. `InfinitePayGateway::verifyWebhook()` faz `return true` incondicional
(sem assinatura/origem), e para InfinitePay o webhook **pula** a reconfirmação no
PSP (o ramo `if ($slug !== 'infinitepay')` em `webhook_controller.php:82-87`). O
valor pago vem do corpo do POST (controlado pelo atacante). Resultado:
`POST /webhook/pix/infinitepay` com
`{"order_nsu":"<token do próprio pedido, visível na URL /pedido/{token}>","paid_amount":<total_cents>}`
marca `orders.status='pago'`, dispara o e-mail "Pagamento confirmado" e libera a
mercadoria. É fraude direta de pagamento, severidade crítica.

Este plano fecha o vetor reconfirmando cada webhook InfinitePay via
`payment_check`. O `transaction_nsu` é um UUID **gerado pela InfinitePay** que só
existe depois de um pagamento real — um atacante forjando o webhook do próprio
pedido não tem como produzir um `transaction_nsu`/`slug` que o `payment_check`
real confirme como pago. E o valor usado na checagem passa a vir da **resposta do
`payment_check`** (autoritativa), não do corpo do webhook — fechando também o
ataque "pago R$0,01 de verdade + `paid_amount` forjado no corpo".

## Contexto do produto (do dono do repo, decisões já tomadas)

- Vitrine PIX, comprador **sem cadastro/login**. Pedido identificado por token
  opaco de 32 chars (`bin2hex(random_bytes(16))`, 128 bits — não adivinhável por
  terceiros; o vetor é o **próprio dono do pedido**, que vê o token na URL).
- Pagamento roteado entre 3 gateways (MercadoPago, PagBank, InfinitePay).
  **MercadoPago e PagBank NÃO são vulneráveis** a este vetor: o webhook já chama
  `fetchStatus()` deles e só marca pago se o PSP confirmar. **Não altere o fluxo
  MP/PagBank.**
- A InfinitePay não documenta assinatura/HMAC de webhook publicamente (contato:
  `parcerias@cloudwalk.io`). A defesa é a reconfirmação `payment_check`, não uma
  assinatura.

## Current state

Arquivos e trechos exatos (verifique contra o repo — drift check acima):

- `site/app/inc/lib/InfinitePayGateway.php` — adapter InfinitePay. **É byte-idêntico**
  a `manager/app/inc/lib/InfinitePayGateway.php` (regra de framework compartilhado —
  ver "Convenções"). Fatos relevantes:
  - `API_BASE = 'https://api.checkout.infinitepay.io'` (`:16`).
  - `handle()` (`:20-23`) lê `INFINITEPAY_HANDLE` de `kernel.php` via `defined()/constant()`.
  - `createCharge()` (`:25-58`) mostra o padrão de chamada HTTP: monta corpo via
    `buildChargeBody()` (público **de propósito, para ser testável sem rede**,
    `:60-97`) e chama o `request()` privado (`:179-214`). O `request()` retorna
    `[$httpCode, ?array $decoded]`.
  - `verifyWebhook()` (`:124-132`) faz `return true` incondicional.
  - `extractChargeId()` (`:134-143`) lê `order_nsu` do corpo.
  - `extractPaidAmountCents()` (`:145-158`) lê `paid_amount ?? amount` do corpo — **é
    exatamente esse dado controlado pelo atacante que este plano deixa de usar para
    InfinitePay**.
  - `fetchStatus()` (`:160-169`) sempre devolve `'pendente'` e loga um warning.
    **Este método NÃO muda** — ele continua sendo o stub que o job de reconciliação
    (plano 034) usa; `payment_check` precisa de `transaction_nsu`+`slug` que só
    existem no corpo do webhook, não de um `gatewayChargeId` isolado.

- `site/app/inc/controller/webhook_controller.php` — `processEvent()` (`:19-197`),
  controller **só do site**. O bypass da reconfirmação está em `:77-87`:

  ```php
  // Nunca confia so no corpo do webhook — confirma no PSP quando o
  // gateway suporta. InfinitePay nao tem endpoint de consulta
  // (fetchStatus() sempre devolve 'pendente' por design — ver Passo 7),
  // entao para ele a defesa e so o token opaco + a checagem de valor
  // abaixo, nao uma reconfirmacao aqui.
  if ($slug !== 'infinitepay') {
      $pspStatus = $gateway->fetchStatus($chargeId);
      if ($pspStatus !== 'pago') {
          return ['code' => 200, 'body' => ['ok' => true]];
      }
  }
  ```

  E a checagem de valor está em `:99-117`:
  ```php
  $paidAmountCents = $gateway->extractPaidAmountCents($rawBody);
  if ($paidAmountCents === null) {
      $paidAmountCents = (int)$charge['amount_cents'];
  }
  if ($paidAmountCents < (int)$order['total_cents']) { /* ...não marca pago... */ }
  ```
  Ordem do fluxo (importante para os testes): extractChargeId → acha gateway row →
  acha charge row (não achou → 200 ignored, `:68-70`) → **idempotência** (charge já
  'pago' → 200 ok, `:73-75`) → **só então** o bloco de reconfirmação (`:82`) → carrega
  order → checagem de valor → marca pago + `commit()` (`:157`).

- `site/app/inc/lib/PixGateway.php` — a **interface compartilhada** dos 3 adapters.
  **NÃO adicione o método novo a esta interface** — `payment_check` é específico da
  InfinitePay e a interface é usada por MP/PagBank. O método novo fica só na classe
  `InfinitePayGateway`, e o controller o chama sob um `instanceof InfinitePayGateway`.

- **`INFINITEPAY_HANDLE` já existe no `kernel.php`** — este plano **não precisa de
  nenhuma constante nova** (diferente da versão antiga, que pedia
  `INFINITEPAY_WEBHOOK_IPS`). Nada a documentar no `kernel.php.example`.

## Convenções do repositório (obrigatórias)

- **Framework compartilhado em duas cópias byte-idênticas.** Todo arquivo em
  `app/inc/lib/` e `app/inc/model/` DEVE ser idêntico entre `site/` e `manager/`.
  `InfinitePayGateway.php` e `PixGateway.php` existem nas duas. **Qualquer edição no
  `InfinitePayGateway.php` vai nas DUAS cópias.** O guard `bin/check-shared-sync.sh`
  bloqueia o commit se divergirem (ele exclui `vendor/` e `tests/`).
  `webhook_controller.php` é **só do site** (controller, per-ambiente).
- **`tests/` NÃO está sob o guard de sync** (o guard exclui `tests/`; os bootstraps
  diferem por HTTP_HOST). Testes novos vão só em `site/tests/`. Não existe cópia de
  `InfinitePayGatewayTest.php` no `manager/` — não crie uma.
- Chamadas HTTP: cURL nativo com `CURLOPT_TIMEOUT`/`CURLOPT_CONNECTTIMEOUT`, via o
  `request()` privado que já existe. Nunca logar token/valor sensível — só
  `gateway_charge_id`, `orders_id`, código HTTP e mensagem. `payment_check` **não
  usa header de autenticação** (o `handle` no corpo identifica o recebedor — mesmo
  padrão do `POST /links` em `createCharge()`).
- Config sensível vem de `kernel.php` via `defined()/constant()`, fail-closed quando
  ausente.
- PHPStan level 4. Este plano não introduz constante nova, então provavelmente não
  mexe no `phpstan.neon`. Só edite `phpstan.neon` se o PHPStan realmente reclamar
  (ver Step 4).
- Não há `commit()`/`rollback()` manual em controllers, exceto o webhook (única rota
  autorizada). **Não mexa na parte de commit/e-mail** (`:137-187`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Lint adapter | `cd site && php -l app/inc/lib/InfinitePayGateway.php` | `No syntax errors` |
| Lint controller | `cd site && php -l app/inc/controller/webhook_controller.php` | `No syntax errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Shared-sync guard | `bin/check-shared-sync.sh` | exit 0 |
| Diff das 2 cópias | `diff -q site/app/inc/lib/InfinitePayGateway.php manager/app/inc/lib/InfinitePayGateway.php` | (sem saída = idênticos) |
| Testes gateway | `cd site && php app/inc/lib/vendor/bin/phpunit --filter InfinitePay` | all pass |
| Testes webhook | `cd site && php app/inc/lib/vendor/bin/phpunit --filter Webhook` | all pass |

PHPUnit precisa de `kernel.php` + banco vivo (ver CLAUDE.md). Se o container não
estiver acessível no seu ambiente, rode ao menos PHPStan + `php -l` + o
shared-sync, e registre no PR que o PHPUnit não rodou aqui (STOP condition abaixo
cobre o caso de você não conseguir rodar teste algum).

## Scope

**In scope** (edite só estes):
- `site/app/inc/lib/InfinitePayGateway.php` — adiciona os métodos de reconfirmação.
- `manager/app/inc/lib/InfinitePayGateway.php` — cópia byte-idêntica da anterior.
- `site/app/inc/controller/webhook_controller.php` — **apenas** o ramo InfinitePay
  da reconfirmação e a origem do valor pago para InfinitePay (detalhes na Step 2).
- `site/tests/InfinitePayGatewayTest.php` — adiciona casos para os métodos novos.

**Out of scope** (NÃO toque):
- `site/app/inc/lib/PixGateway.php` — a interface **não** ganha o método novo.
- O fluxo **MercadoPago/PagBank** em `webhook_controller.php` — o ramo
  `if ($slug !== 'infinitepay')` (`:82-87`) e a chamada `fetchStatus()` deles ficam
  **intactos**. Sua edição no controller não pode alterar o comportamento deles.
- `InfinitePayGateway::fetchStatus()` — continua o stub `'pendente'` (usado pelo
  plano 034). Não mude.
- O bloco de `commit()` + enfileiramento de e-mail (`:137-187`).
- `docker/interface/default.conf` (nginx real_ip) — já resolvido em separado.
- `kernel.php` / `kernel.php.example` — este plano não precisa de constante nova.
- Qualquer coisa de estoque, expiração ou rate limit (outros planos).

## Steps

### Step 1: Adicionar a reconfirmação `payment_check` ao `InfinitePayGateway`

Em `site/app/inc/lib/InfinitePayGateway.php`, adicione **três** métodos. Siga o
mesmo estilo/idioma do arquivo (comentários explicando o porquê, PHPDoc nos
arrays, cURL via o `request()` privado existente).

**1a. `buildPaymentCheckBody()` — puro, público, testável sem rede** (mesmo padrão
de `buildChargeBody`). Recebe o payload já decodificado do webhook e monta o corpo
do `payment_check`, ou `null` se faltar `transaction_nsu` ou `slug` (sem eles não
há o que reconfirmar):

```php
/**
 * Monta o corpo do POST /payment_check a partir do payload do webhook, ou null
 * se o payload nao trouxer transaction_nsu + slug (sem eles nao ha o que
 * reconfirmar no PSP). Publico de proposito, para ser testavel sem rede.
 *
 * @param array<string, mixed> $payload payload decodificado do webhook
 * @return array<string, string>|null
 */
public function buildPaymentCheckBody(array $payload): ?array
{
    $orderNsu       = trim((string)($payload['order_nsu'] ?? ''));
    $transactionNsu = trim((string)($payload['transaction_nsu'] ?? ''));
    $slug           = trim((string)($payload['invoice_slug'] ?? $payload['slug'] ?? ''));

    if ($transactionNsu === '' || $slug === '' || $orderNsu === '') {
        return null;
    }

    return [
        'handle'          => $this->handle(),
        'order_nsu'       => $orderNsu,
        'transaction_nsu' => $transactionNsu,
        'slug'            => $slug,
    ];
}
```

> Nota: o payload do webhook documentado inclui `invoice_slug` (não `slug`); o
> corpo do `payment_check` documentado pede a chave `slug`. Por isso lemos
> `invoice_slug ?? slug` do payload e escrevemos `slug` no corpo. Aceitar as duas
> chaves de leitura é defensivo caso a InfinitePay use uma ou outra.

**1b. `parsePaymentCheckResponse()` — puro, público, testável sem rede**. Mapeia a
resposta da API para uma decisão simples:

```php
/**
 * Interpreta a resposta do POST /payment_check. Retorna se o pagamento esta
 * confirmado como pago e o valor autoritativo pago (em centavos), que o
 * webhook_controller usa na checagem de valor NO LUGAR do paid_amount do corpo
 * do webhook (que e controlado por quem posta). Publico para ser testavel sem rede.
 *
 * @param array<string, mixed>|null $response corpo decodificado da resposta
 * @return array{paid: bool, paid_amount_cents: ?int}
 */
public function parsePaymentCheckResponse(?array $response): array
{
    if (!is_array($response)) {
        return ['paid' => false, 'paid_amount_cents' => null];
    }

    $paid = ($response['success'] ?? null) === true && ($response['paid'] ?? null) === true;

    $amount = $response['paid_amount'] ?? $response['amount'] ?? null;
    $paidAmountCents = is_numeric($amount) ? (int)$amount : null;

    return ['paid' => $paid, 'paid_amount_cents' => $paidAmountCents];
}
```

**1c. `confirmPayment()` — orquestra (faz a rede)**. Decodifica o corpo, monta o
body (fail-closed se não der), chama `payment_check`, e devolve a decisão. Fail-closed
em qualquer erro (handle vazio, body irreconhecível, falha de rede, HTTP não-2xx):

```php
/**
 * Reconfirma no PSP se o webhook InfinitePay corresponde a um pagamento real.
 * InfinitePay nao assina webhooks, entao esta e a defesa contra um comprador
 * forjar o POST do proprio pedido: o transaction_nsu e um UUID gerado pela
 * InfinitePay que so existe apos um pagamento real, e o valor confirmado vem
 * da resposta da API (autoritativo), nao do corpo do webhook. Ver plano 031.
 *
 * Fail-closed: qualquer impossibilidade de reconfirmar (handle ausente, payload
 * sem transaction_nsu/slug, falha de rede, HTTP != 2xx) retorna paid=false — o
 * webhook_controller entao NAO marca o pedido como pago.
 *
 * @return array{paid: bool, paid_amount_cents: ?int}
 */
public function confirmPayment(string $rawBody): array
{
    $notPaid = ['paid' => false, 'paid_amount_cents' => null];

    if ($this->handle() === '') {
        Logger::getInstance()->warning('InfinitePay payment_check: INFINITEPAY_HANDLE nao configurado (fail-closed)');
        return $notPaid;
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return $notPaid;
    }

    $body = $this->buildPaymentCheckBody($payload);
    if ($body === null) {
        Logger::getInstance()->warning('InfinitePay payment_check: webhook sem transaction_nsu/slug — nao reconfirmavel (fail-closed)', [
            'order_nsu' => (string)($payload['order_nsu'] ?? ''),
        ]);
        return $notPaid;
    }

    try {
        [$httpCode, $response] = $this->request('POST', self::API_BASE . '/payment_check', $body, [
            'Content-Type: application/json',
        ]);
    } catch (\Throwable $e) {
        Logger::getInstance()->error('InfinitePay payment_check: falha de rede', [
            'order_nsu' => $body['order_nsu'],
            'error'     => $e->getMessage(),
        ]);
        return $notPaid;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        Logger::getInstance()->warning('InfinitePay payment_check: HTTP nao-2xx', [
            'order_nsu' => $body['order_nsu'],
            'http_code' => $httpCode,
        ]);
        return $notPaid;
    }

    return $this->parsePaymentCheckResponse($response);
}
```

**1d. Atualize o comentário de `verifyWebhook()`** (mantém `return true`) para
apontar a nova defesa. `verifyWebhook()` **continua retornando `true`** (não é mais
a camada de autenticação — a reconfirmação `confirmPayment()` é). Troque o
comentário do corpo por algo como:

```php
public function verifyWebhook(string $rawBody, array $headers, array $query = []): bool
{
    // InfinitePay nao publica assinatura de webhook. verifyWebhook nao e a camada
    // de autenticacao: a defesa real e a reconfirmacao confirmPayment() (POST
    // /payment_check) que o webhook_controller chama para InfinitePay antes de
    // marcar como pago. Ver plano 031.
    return true;
}
```

**Verify**: `cd site && php -l app/inc/lib/InfinitePayGateway.php` → `No syntax errors`.

### Step 2: Ligar a reconfirmação no `webhook_controller.php` (só o ramo InfinitePay)

Em `site/app/inc/controller/webhook_controller.php`, **mantendo o ramo
`if ($slug !== 'infinitepay')` intacto**, acrescente logo depois dele um ramo
InfinitePay que reconfirma via `confirmPayment()` e captura o valor autoritativo.
Substitua o bloco `:82-87` + a origem do valor pago (`:104-107`) assim:

Bloco de reconfirmação (imediatamente após o `if ($slug !== 'infinitepay') { ... }`
existente — **não** remova nem altere o bloco MP/PagBank):

```php
// InfinitePay nao tem fetchStatus por charge id, mas TEM POST /payment_check
// (order_nsu + transaction_nsu + slug do corpo). Reconfirma aqui: o
// transaction_nsu e um UUID gerado pelo PSP que so existe apos pagamento real,
// entao um comprador forjando o webhook do proprio pedido nao passa. Ver plano 031.
$infinitepayConfirmedAmountCents = null;
if ($slug === 'infinitepay') {
    if (!$gateway instanceof InfinitePayGateway) {
        // Nunca deve acontecer (slug 'infinitepay' -> InfinitePayGateway acima).
        return ['code' => 500, 'body' => ['error' => 'internal error']];
    }
    $confirmation = $gateway->confirmPayment($rawBody);
    if (!$confirmation['paid']) {
        return ['code' => 200, 'body' => ['ok' => true]];
    }
    $infinitepayConfirmedAmountCents = $confirmation['paid_amount_cents'];
}
```

Origem do valor pago — troque **apenas** a linha
`$paidAmountCents = $gateway->extractPaidAmountCents($rawBody);` por:

```php
// Para InfinitePay usamos o valor autoritativo do payment_check (acima), nunca o
// paid_amount do corpo do webhook (controlado por quem posta). Para MP/PagBank
// $infinitepayConfirmedAmountCents e null, entao o comportamento nao muda.
$paidAmountCents = $infinitepayConfirmedAmountCents ?? $gateway->extractPaidAmountCents($rawBody);
```

Deixe o resto da checagem de valor (`if ($paidAmountCents === null) { ... }` e a
comparação `< total_cents`) **inalterado**.

**Verify**: `cd site && php -l app/inc/controller/webhook_controller.php` → `No syntax errors`.

### Step 3: Replicar a edição do adapter na cópia do manager

Copie o `InfinitePayGateway.php` alterado (Step 1) para
`manager/app/inc/lib/InfinitePayGateway.php`, byte-idêntico ao de `site/`. **O
`webhook_controller.php` é só do site — não há cópia no manager.**

**Verify**:
- `diff -q site/app/inc/lib/InfinitePayGateway.php manager/app/inc/lib/InfinitePayGateway.php` → sem saída.
- `bin/check-shared-sync.sh` → exit 0.

### Step 4: PHPStan nos dois ambientes

**Verify**:
- `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`

Este plano não adiciona constante nova, então provavelmente não há nada a mexer no
`phpstan.neon`. Se (e só se) o PHPStan reclamar de algo introduzido pela sua
mudança, corrija de forma mínima e registre no PR o que e por quê. Se ele apontar
um erro que você não sabe corrigir sem sair do escopo, **pare e reporte**.

## Test plan

Adicione casos a `site/tests/InfinitePayGatewayTest.php` (estende `TestCase` puro,
sem DB, sem rede). Teste os **dois métodos puros** diretamente — nunca
`confirmPayment()` (esse faz rede; segue a convenção do arquivo de não testar o
caminho de `request()`). Use asserts reais (`assertSame`/`assertNull`/`assertTrue`/
`assertFalse`).

Para `buildPaymentCheckBody()`:
1. Payload completo (`order_nsu`, `transaction_nsu`, `slug`) → body com as 4 chaves
   corretas (`handle` vem de `INFINITEPAY_HANDLE`; se vazio no ambiente de teste,
   asserte só que a chave existe, não o valor). Aceita também `invoice_slug` no
   lugar de `slug`.
2. Falta `transaction_nsu` → `null`.
3. Falta `slug`/`invoice_slug` → `null`.
4. Falta `order_nsu` → `null`.

Para `parsePaymentCheckResponse()`:
5. `{"success":true,"paid":true,"paid_amount":1510}` → `['paid'=>true,'paid_amount_cents'=>1510]`.
6. `{"success":true,"paid":false}` → `paid=false`.
7. `{"success":false,"paid":true}` → `paid=false` (success falso derruba).
8. `paid_amount` ausente mas `amount` presente → usa `amount` como fallback.
9. `null` (resposta não-array) → `['paid'=>false,'paid_amount_cents'=>null]`.

**Não** altere `testVerifyWebhookAlwaysReturnsTrueRegardlessOfInput` — `verifyWebhook`
continua retornando `true`, então esse teste segue verde e continua documentando
que `verifyWebhook` não é a camada de auth.

**Interação com `WebhookIdempotencyTest.php` (leia, não vai precisar editar):** os
testes de webhook InfinitePay ali (`testChargeNotFoundIsIgnoredWith200`,
`testAmountLessThanOrderTotalDoesNotMarkAsPaid`,
`testAlreadyPaidChargeIsIdempotentOnRedelivery`) mandam corpos **sem**
`transaction_nsu`/`slug`. Consequência do seu código: `confirmPayment()` monta
`buildPaymentCheckBody() === null` → retorna `paid=false` **sem fazer chamada de
rede**. Então:
- `testChargeNotFoundIsIgnoredWith200`: retorna 200 ignored **antes** do bloco de
  reconfirmação (charge não existe) — inalterado.
- `testAlreadyPaidChargeIsIdempotentOnRedelivery`: retorna no guard de idempotência
  (`:73`) **antes** do bloco de reconfirmação — inalterado.
- `testAmountLessThanOrderTotalDoesNotMarkAsPaid`: agora atinge o bloco de
  reconfirmação, `confirmPayment` devolve `paid=false` (sem `transaction_nsu`/`slug`),
  e o controller retorna 200 ok **sem marcar pago** — as asserções (`200`, `ok`,
  status `pendente`, `paid_at` null) **continuam válidas** (a razão de não marcar
  mudou de "valor menor" para "não reconfirmado", mas o resultado observável é o
  mesmo). Rode `--filter Webhook` e confirme que segue verde. **Se algum desses
  quebrar, pare e reporte** — não "conserte" reescrevendo os testes de webhook
  (estão fora do escopo; um vermelho ali sinaliza que a ordem do seu fluxo no
  controller ficou errada).

**Verify**:
- `cd site && php app/inc/lib/vendor/bin/phpunit --filter InfinitePay` → todos passam (incluindo os novos).
- `cd site && php app/inc/lib/vendor/bin/phpunit --filter Webhook` → todos passam (nenhuma rede disparada).

## Done criteria

Todas devem valer:

- [ ] `InfinitePayGateway` tem `confirmPayment()`, `buildPaymentCheckBody()`,
      `parsePaymentCheckResponse()`; `confirmPayment` é fail-closed (retorna
      `paid=false`) quando falta `transaction_nsu`/`slug`, `handle` está vazio, ou o
      HTTP falha — provado por teste (métodos puros) e por inspeção.
- [ ] `verifyWebhook()` continua `return true` e `testVerifyWebhookAlwaysReturnsTrueRegardlessOfInput` passa.
- [ ] `InfinitePayGateway::fetchStatus()` **não** foi alterado (continua stub `'pendente'`).
- [ ] `PixGateway.php` **não** foi alterado.
- [ ] No `webhook_controller.php`, o ramo `if ($slug !== 'infinitepay')` (MP/PagBank)
      está inalterado; o novo ramo InfinitePay reconfirma via `confirmPayment()` e,
      quando não pago, retorna 200 sem marcar; a checagem de valor usa o valor do
      `payment_check` para InfinitePay.
- [ ] `diff -q site/app/inc/lib/InfinitePayGateway.php manager/app/inc/lib/InfinitePayGateway.php` sem saída.
- [ ] `bin/check-shared-sync.sh` exit 0.
- [ ] PHPStan `[OK] No errors` em `site/` e `manager/`.
- [ ] `cd site && php app/inc/lib/vendor/bin/phpunit --filter InfinitePay` passa (casos novos inclusos).
- [ ] `cd site && php app/inc/lib/vendor/bin/phpunit --filter Webhook` passa.
- [ ] `git status` não mostra arquivo fora do escopo modificado.
- [ ] Linha de status deste plano atualizada em `plans/README.md`.

## STOP conditions

Pare e reporte (não improvise) se:

- O código em "Current state" não bater com o que está no repo (drift).
- A ordem do fluxo no `webhook_controller.php` não permitir inserir a reconfirmação
  **depois** do guard de idempotência e **antes** da checagem de valor sem alterar o
  comportamento de MP/PagBank.
- Qualquer teste de `WebhookIdempotencyTest.php` ficar vermelho após sua mudança
  (sinaliza fluxo errado — não reescreva esses testes).
- O PHPUnit tentar fazer uma chamada de rede real ao `payment_check` durante a
  suíte (nenhum teste deve disparar `confirmPayment` com corpo que tenha
  `transaction_nsu`+`slug`; se acontecer, seu desenho vazou rede para os testes —
  pare e reporte).
- Você não conseguir rodar teste algum (sem `kernel.php`/banco). Nesse caso rode
  PHPStan + `php -l` + shared-sync, registre no PR que o PHPUnit não rodou, e siga —
  **mas** sinalize que a validação de banco ficou pendente.
- Qualquer verificação falhar duas vezes após uma tentativa razoável de correção.

## Maintenance notes

Para quem for revisar/manter:

- **Revisor deve escrutinar**: (1) que o valor usado na checagem para InfinitePay é
  o do `payment_check`, não o `extractPaidAmountCents($rawBody)` do corpo; (2) que a
  reconfirmação roda **antes** de qualquer `save()`/`commit()`; (3) que MP/PagBank
  seguem idênticos; (4) que `confirmPayment` é fail-closed em todos os ramos de erro.
- **Não testável contra a API real aqui**: não há sandbox/credencial da InfinitePay
  no ambiente de dev/CI. Validar em produção com um **PIX de teste de valor baixo**
  via InfinitePay após o deploy: confirmar que o pedido só vira `pago` após o
  `payment_check` responder `paid:true`, e que o log não registra rejeição indevida.
  Antes dessa validação, considere manter o gateway InfinitePay `enabled='no'` no
  banco (decisão de negócio) se quiser evitar qualquer janela.
- **Formato real do payload**: o corpo do webhook e a resposta do `payment_check`
  vieram da doc pública (central de ajuda InfinitePay, 2026-07-20). Se em produção o
  payload real divergir (ex.: nome de campo diferente para o UUID da transação),
  ajuste as chaves lidas em `buildPaymentCheckBody()` e
  `parsePaymentCheckResponse()` — o log de "webhook sem transaction_nsu/slug" é o
  sinal de divergência a observar.
- **Interação com o plano 034 (reconciliação)**: `fetchStatus()` continua stub e o
  job de reconciliação do plano 034 segue **não** cobrindo InfinitePay — o
  `payment_check` precisa de `transaction_nsu`/`slug`, que só chegam no corpo do
  webhook e **não são persistidos** na criação da cobrança. Se no futuro quiser
  reconciliação para InfinitePay, seria preciso persistir `transaction_nsu`/`slug`
  quando o (primeiro) webhook chega — fora do escopo deste plano.
- **Follow-up (assinatura/HMAC)**: se a InfinitePay vier a publicar assinatura de
  webhook (`parcerias@cloudwalk.io`), validá-la em `verifyWebhook()` vira uma
  camada extra barata (rejeita antes mesmo de gastar uma chamada `payment_check`).
  A reconfirmação continua sendo a defesa autoritativa.
