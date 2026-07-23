# Plan 017: "Acompanhar meu pedido" — página pública no site (e-mail + 4 dígitos)

> **Executor instructions**: Follow this plan step by step. Run every verification
> command and confirm the expected result before moving on. If anything in "STOP
> conditions" occurs, stop and report — do not improvise. When done, update this
> plan's status row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat fdb4216..HEAD -- site/public_html/index.php site/app/inc/urls.php site/app/inc/controller/auth_controller.php site/app/inc/lib/CommonFunctions.php migrations/`
> On any change, compare "Current state" excerpts to live code before proceeding.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (superfície pública + PII; enumeração)
- **Depends on**: **016** (colunas `tracking_code`/`shipped_at` em `orders`)
- **Category**: direction (feature — Fase 2 item #4)
- **Planned at**: commit `fdb4216`, 2026-07-16

## Why this matters

Cliente compra sem cadastro/login (pedido identificado por token opaco). Precisa de uma
página pública onde informa **e-mail + os 4 últimos dígitos do telefone** e vê seus pedidos:
status, se foi enviado, e o código de rastreio quando houver. Os dois campos são validados
**juntos** contra o cadastro (não vaza dado se só um bater), com rate-limit (Redis fail-open,
já existe) e sem expor CPF completo nem endereço.

## Current state

- **`orders` guarda os dados denormalizados** (migration 012 + 015): `customer_mail`,
  `customer_phone` (digits-only), `token`, `status`, `total_cents`, `created_at`, `paid_at`.
  Após o **plano 016** também terá `tracking_code` e `shipped_at`. Enum status:
  `aguardando_pagamento, pago, cancelado, expirado`. "Enviado" = `shipped_at IS NOT NULL`.

- **Roteamento público do site** — `site/public_html/index.php:63-119` via
  `$dispatcher->add_route($method, $regex, "controller:method", $check, $params)`. Rotas
  públicas (sem guard) passam `null` no `$check`. Exemplo (o exemplar mais próximo — form
  público GET+POST com rate-limit + CSRF):
  ```php
  $dispatcher->add_route("GET",  "/esqueci-minha-senha", "auth_controller:display_forgot_password", null, $params);
  $dispatcher->add_route("POST", "/esqueci-minha-senha", "auth_controller:forgot_password",         null, $params);
  ```

- **Padrão GET (renderiza form) + POST (processa)** — `site/app/inc/controller/auth_controller.php`:
  ```php
  public function display_forgot_password(array $info): void
  {
      if (empty($_SESSION['_csrf_token'])) { $_SESSION['_csrf_token'] = random_token(); }
      include(constant("cRootServer") . "ui/common/head.php");
      include(constant("cRootServer") . "ui/common/header.php");
      include(constant("cRootServer") . "ui/page/forgot_password.php");
      include(constant("cRootServer") . "ui/common/footer.php");
      include(constant("cRootServer") . "ui/common/foot.php");
  }

  public function forgot_password(array $info): never
  {
      validate_csrf($info["post"]["_csrf_token"] ?? null, $GLOBALS["forgot_password_url"]);
      $mail = trim($info["post"]["mail"] ?? '');
      // ...
      $redis   = $GLOBALS['redis'] ?? null;
      $rateKey = "forgot_pwd:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
      if (check_and_increment_rate_limit($redis, $rateKey, 3, 300)) {
          $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde alguns minutos."];
          basic_redir($GLOBALS["forgot_password_url"]);
      }
      // ...consulta com set_filter(["... = ?"], [...]) ...
  }
  ```

- **Rate-limit** — `check_and_increment_rate_limit(?object $redis, string $key, int $max, int $window): bool`
  (`site/app/inc/lib/CommonFunctions.php` ~:434). Retorna **`true` quando deve BLOQUEAR**
  (contagem > max). Atômico (incr-first). **Fail-open**: se Redis e fallback de arquivo
  indisponíveis → retorna `false` (não bloqueia) e loga warning. `$redis = $GLOBALS['redis'] ?? null;`

- **Normalizador digits-only** — `sanitize_string($v, true)` (~:296) → `preg_replace('/\D+/','',$v)`.

- **CSRF** — `validate_csrf($token, $redirectUrl)` (grace de 10s). Form embute hidden
  `_csrf_token` = `$_SESSION['_csrf_token']`.

- **URL constants** — `site/app/inc/urls.php`, padrão
  `$x_url = sprintf("%s%s", constant("cFrontend"), "rota");`

- **`orders_model` (site)** — `site/app/inc/model/orders_model.php`, `$field` já com
  status/total/etc. (após 016, com tracking).

## Commands you will need

| Purpose      | Command                                                     | Expected         |
|--------------|-------------------------------------------------------------|------------------|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse`      | `[OK] No errors` |
| PHPStan mgr  | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse`   | `[OK] No errors` |
| PHPUnit site | `cd site && php app/inc/lib/vendor/bin/phpunit`              | all pass         |
| Single test  | `cd site && php app/inc/lib/vendor/bin/phpunit --filter TrackOrderTest` | pass  |
| Shared-sync  | `bin/check-shared-sync.sh`                                  | exit 0           |

## Scope

**In scope**:
- `site/app/inc/urls.php` — add `$track_order_url`
- `site/public_html/index.php` — add GET + POST `/acompanhar-pedido`
- `site/app/inc/controller/track_order_controller.php` — **create**
- `site/public_html/ui/page/track_order.php` — **create** (form + resultados)
- `site/tests/TrackOrderTest.php` — **create**
- (opcional) link "Acompanhar meu pedido" no footer/menu público — só se trivial e sem
  tocar partial compartilhado de forma arriscada; senão deixe a rota acessível por URL e
  registre como follow-up.

**Out of scope**:
- `orders_model.php` / `lib/` — não edite (shared-sync); `tracking_code`/`shipped_at` já
  vêm do plano 016.
- Qualquer coisa no manager além do PHPStan de sanidade.
- CPF e endereço na tela — **proibido** exibir.
- Autenticação/login — a página é 100% pública, sem sessão de cliente.

## Git workflow

- Branch: `advisor/017-acompanhar-pedido`
- Conventional Commits PT-BR, ex.: `feat: adiciona pagina publica de acompanhamento de pedido`
- Sem push/PR salvo instrução do operador.

## Steps

### Step 1: URL constant + rotas

- `site/app/inc/urls.php`:
  ```php
  $track_order_url = sprintf("%s%s", constant("cFrontend"), "acompanhar-pedido");
  ```
- `site/public_html/index.php` (rotas públicas, `$check = null`):
  ```php
  $dispatcher->add_route("GET",  "/acompanhar-pedido", "track_order_controller:index",  null, $params);
  $dispatcher->add_route("POST", "/acompanhar-pedido", "track_order_controller:search", null, $params);
  ```

**Verify**: `grep -n "track_order_controller" site/public_html/index.php` → 2 linhas.

### Step 2: Controller — `track_order_controller.php`

`site/app/inc/controller/track_order_controller.php`. Duas actions:

**`index(array $info): void`** — renderiza o form (padrão de `display_forgot_password`):
gera CSRF token se vazio, inclui head/header/`ui/page/track_order.php`/footer/foot. Passa
`$orders = []; $searched = false;`.

**`search(array $info): void`** — processa:
1. `validate_csrf($info['post']['_csrf_token'] ?? null, $GLOBALS['track_order_url']);`
2. `$mail = trim($info['post']['mail'] ?? '');` e
   `$phone4 = sanitize_string($info['post']['phone4'] ?? '', true);` (só dígitos).
3. **Rate-limit** (fail-open, mesmo padrão do forgot_password):
   ```php
   $redis   = $GLOBALS['redis'] ?? null;
   $rateKey = "track_order:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
   if (check_and_increment_rate_limit($redis, $rateKey, 5, 300)) { // 5 tentativas / 5 min
       $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde alguns minutos."];
       basic_redir($GLOBALS['track_order_url']);
   }
   ```
4. **Exigir os DOIS campos**: se `$mail === ''` **ou** `strlen($phone4) !== 4` →
   mensagem genérica "Informe e-mail e os 4 últimos dígitos do telefone." + redirect.
   (Não diga qual faltou.)
5. **Consulta validando os dois juntos** (placeholders `?`):
   ```php
   $model = new orders_model();
   $model->set_field([" idx ", " token ", " status ", " total_cents ", " created_at ",
       " paid_at ", " tracking_code ", " shipped_at "]);
   $model->set_filter([" active = 'yes' ", " customer_mail = ? ", " RIGHT(customer_phone, 4) = ? "],
       [$mail, $phone4]);
   $model->set_order([" created_at DESC "]);
   $model->load_data(false);
   $orders = $model->data;
   ```
   `customer_phone` é digits-only (gravado normalizado no checkout), então `RIGHT(...,4)`
   é confiável.
6. **Sem vazar por 1 campo**: como o `WHERE` exige `customer_mail = ?` **E**
   `RIGHT(customer_phone,4) = ?`, só retorna linha se OS DOIS baterem. Se `$orders` vazio →
   renderiza a view com `$searched = true; $orders = []` e mensagem neutra "Nenhum pedido
   encontrado com esses dados." (mesma mensagem tanto p/ e-mail errado quanto telefone errado).
7. Renderiza a view (head/header/page/footer/foot) com `$orders` e `$searched = true`.
   Envolva a consulta em try/catch(RuntimeException) degradando p/ lista vazia.

> **Nunca** selecione/nem exiba `customer_cpf`, `ship_*` (endereço). O `set_field` acima
> já não os inclui — mantenha assim.

**Verify**: `php -l site/app/inc/controller/track_order_controller.php` → No syntax errors.

### Step 3: View — `track_order.php`

`site/public_html/ui/page/track_order.php`. Estrutura pública (não a sidebar do manager;
use o layout público — veja `ui/page/forgot_password.php` como referência de container/CSS
do site). Inclua:
- Form `method="POST"` action `$GLOBALS['track_order_url']`, hidden `_csrf_token`
  (`$_SESSION['_csrf_token']`), input `type="email" name="mail"` e input
  `name="phone4" inputmode="numeric" maxlength="4"` (placeholder "4 últimos dígitos do
  telefone"), botão "Acompanhar". Inputs com `font-size >= 16px` (evita zoom no iOS —
  convenção já aplicada no site, ver plano 004).
- Se `$searched && empty($orders)` → "Nenhum pedido encontrado com esses dados."
- Para cada pedido: token (8 chars), status (rótulo amigável), **enviado?**
  (`$o['shipped_at'] ? 'Enviado em ' . data : 'Ainda não enviado'`), **código de rastreio**
  (`$o['tracking_code'] ?: '—'`), total `R$ number_format(cents/100,2,',','.')`, data.
  **Escape tudo** com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Não** exiba CPF, endereço, e-mail completo nem telefone.

**Verify**: teste manual — e-mail+4 dígitos corretos mostram os pedidos com status/rastreio;
e-mail certo + 4 dígitos errados (e vice-versa) mostram "Nenhum pedido encontrado".

### Step 4: Test — `TrackOrderTest.php`

Estende `DBTestCase`. Como as actions fazem `include`/`basic_redir`, extraia a lógica de
busca para um método testável **ou** teste a consulta que reproduz o `set_filter`. Cubra:
- **Match dos dois campos**: cria pedido com `customer_mail='a@b.com'`,
  `customer_phone='11988887777'`; busca com `mail='a@b.com'`, `phone4='7777'` → retorna o pedido.
- **Só e-mail bate** → vazio (telefone errado). **Só telefone bate** → vazio (e-mail errado).
  (Prova que não vaza com 1 campo.)
- **Normalização do phone4**: input `"7777"` e telefone gravado digits-only casam via
  `RIGHT(customer_phone,4)`.
- **tracking/shipped**: pedido com `shipped_at` + `tracking_code` retorna esses campos;
  pedido sem envio retorna `shipped_at = NULL`.
- (Se testável) rate-limit não derruba quando `$redis` é `null` (fail-open não bloqueia).

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpunit --filter TrackOrderTest` → passa.

### Step 5: Verificação final

Rode toda a tabela "Commands you will need".

## Test plan

- Novo: `site/tests/TrackOrderTest.php` (`DBTestCase`), casos do Step 4 — com ênfase nos
  dois casos de "só um campo bate → vazio" (é o requisito de não-vazamento).
- Padrão: `site/tests/` — procure um teste que já cria pedidos (ex. `CheckoutPaymentChargeTest`)
  para reaproveitar o helper de fixture de pedido.

## Done criteria

- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `cd site && php app/inc/lib/vendor/bin/phpunit` → verde, incl. `TrackOrderTest`
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `GET /acompanhar-pedido` mostra o form; e-mail+4 dígitos corretos listam os pedidos com status + envio + rastreio
- [ ] E-mail certo + 4 dígitos errados (e vice-versa) → "Nenhum pedido encontrado" (sem vazar)
- [ ] Rate-limit ativo (per-IP) e a página **não** mostra CPF nem endereço
- [ ] `git status` sem arquivos fora do In scope
- [ ] Status row atualizado em `plans/README.md`

## STOP conditions

Pare e reporte se:
- As colunas `tracking_code`/`shipped_at` **não existem** em `orders` (o plano 016 não foi
  executado/mergeado) — este plano depende delas. Rode 016 antes.
- O `set_field` do model não aceitar `tracking_code`/`shipped_at` (drift do plano 016).
- Você precisar exibir CPF/endereço p/ satisfazer algum requisito — pare (proibido).
- Uma verificação falha 2× após uma tentativa razoável de conserto.

## Maintenance notes

- **Enumeração**: e-mail + 4 dígitos é baixa entropia e o rate-limiter é **fail-open** (se
  Redis e o fallback de arquivo caírem, não bloqueia). Aceito por ora (mesma postura do
  resto do site). Se abuso aparecer, endurecer: CAPTCHA, janela menor, ou exigir o token do
  pedido. Revisor deve confirmar que a mensagem de "não encontrado" é **idêntica** para
  e-mail errado e telefone errado (senão vira oráculo de enumeração de e-mail).
- A busca usa `customer_mail`/`customer_phone` denormalizados (não a tabela `customers`) —
  é a fonte completa e 1:1 com o pedido; correto p/ esta tela.
- `RIGHT(customer_phone,4)` não usa índice, mas o filtro combina com `customer_mail = ?`;
  se a base crescer muito e ficar lento, adicionar índice em `customer_mail` (migration
  futura, fora de escopo).
- Interage com o plano 016 (fonte das colunas de rastreio) e, indiretamente, com o e-mail
  `order_shipped` (que anuncia o mesmo código que aparece aqui).
