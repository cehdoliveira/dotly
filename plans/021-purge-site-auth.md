# Plan 021: Remover o sistema de contas do site público (auth completo + 3º e-mail + arquivos mortos)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- site/public_html/index.php site/app/inc/controller/ site/app/inc/urls.php site/public_html/ui/ site/public_html/assets/js/alpine/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (edita `home()` e `finalize()` — telas centrais do funil)
- **Depends on**: `plans/020-consolidar-admin-usuarios.md` (o reset de senha do manager hoje aponta para `/redefinir-senha` do SITE; o 020 corrige isso ANTES desta remoção)
- **Category**: direction (less is more)
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

O escopo do produto é explícito: o cliente compra via Pix **sem criar conta nem senha**. Mesmo assim o site carrega um sistema de contas completo — 8 rotas (login, cadastro, verificação de e-mail, definir/esquecer/redefinir senha, sair, área logada), um controller de 566 linhas, 5 views, 3 controllers Alpine, 2 templates de e-mail e um "modo área do usuário" dentro da home. Uma decisão anterior (2026-07-16) só escondeu os links; sob a diretriz "less is more" o dono decidiu remover a superfície inteira. Aproveita-se para remover o 3º e-mail transacional não autorizado ("Recebemos seu pedido", disparado ANTES do pagamento — o orçamento é de exatamente 2 e-mails: pago e enviado) e lixo de árvore (diretório com `\n` no nome, arquivos 0-byte).

**O que NÃO sai**: `users_model`, `profiles_model`, tabelas `users`/`profiles`/`users_profiles`, helpers de senha/rate-limit em `CommonFunctions.php` — o login do MANAGER depende de tudo isso, e `lib/`/`model/` são cópias byte-idênticas entre os ambientes.

## Current state

- Rotas a remover (`site/public_html/index.php`):
  - `:70-71` `/login` GET/POST · `:74-75` `/cadastro` GET/POST · `:78` `/verificar-email/{t}` · `:79-80` `/definir-senha/{t}` GET/POST · `:83-84` `/esqueci-minha-senha` GET/POST · `:85-86` `/redefinir-senha/{t}` GET/POST · `:89` POST `/sair` · `:97` GET `/area`
  - Rotas que FICAM: `:67` (redirect index), `:92` home, `:93-94` termos/privacidade, `:100+` carrinho/produto/checkout/pagamento/webhook/acompanhar-pedido.
- `site/app/inc/controller/auth_controller.php` — 566 linhas; métodos: `logout, login, display_register, register, verify_email, display_set_password, set_password, display, display_forgot_password, forgot_password, display_reset_password, reset_password` + estático `check_login()`. Envia e-mails em `:154-161` (verify) e `:416-431` (verify/reset) e loga em `messages` (`:168`, `:437`).
- `site/app/inc/controller/site_controller.php:4-10` — `home()` começa com:

```php
$isLoggedIn = auth_controller::check_login();
if ($isLoggedIn) {
    $userId = (int)($_SESSION[constant("cAppKey")]["credential"]["idx"] ?? 0);
}
```

- `site/public_html/ui/page/home.php:1-100` — view bimodal: `<?php if ($isLoggedIn): ?>` renderiza o "MODE 2 — ÁREA DO USUÁRIO" (banner, cards de conta, painel placeholder, link de logout) até `<?php else: ?>`; o MODE 1 (vitrine) segue até o `<?php endif; ?>` no fim do arquivo.
- Views a deletar: `site/public_html/ui/page/{login,register,forgot_password,reset_password,set_password,dashboard}.php` (`dashboard.php` é página de conta órfã — sem rota; confirme com grep antes).
- JS a deletar: `site/public_html/assets/js/alpine/{loginController,registerController,setPasswordController}.js`.
- Mail templates a deletar: `site/public_html/ui/mail/{verify_email,reset_password}.php`.
- URLs a remover de `site/app/inc/urls.php`: `$login_url, $logout_url, $register_url, $area_url, $password_url, $tkpwd_url, $verify_email_url, $set_password_url, $forgot_password_url, $reset_password_url`. FICAM: `$home_url, $terms_url, $privacy_url, $track_order_url, $cart_url, $checkout_url, $payment_url, $done_url, $product_url`.
- 3º e-mail: `site/app/inc/controller/checkout_controller.php:238`:

```php
$this->sendConfirmationEmail($customer['mail'], $customer['name'], sprintf($done_url, $token));
```

e o método privado `sendConfirmationEmail()` (`:579-607`, envia "Recebemos seu pedido" via `EmailProducer` + loga em `messages`); template `site/public_html/ui/mail/order_confirmation.php`. O e-mail in-scope de "pagamento confirmado" (`order_paid`) é OUTRO caminho — enfileirado pelo `webhook_controller.php` na `email_queue` — e não é tocado.
- Lixo de árvore: diretório untracked `site/app/inc/controller/cart_controller.php\nsite/` (nome contém `\n` literal — artefato de shell, aninha cópias recursivas); `manager/public_html/ui/page/home.php` (0 bytes, sem include — a rota `/` do manager renderiza `sales_dashboard.php`); `manager/public_html/ui/mail/verify_email.php` (nenhum `include` o referencia no manager).
- CSRF: `cart_controller.php:27,58`, `checkout_controller.php:27` e `track_order_controller.php:10` inicializam `_csrf_token` por conta própria — o funil de compra NÃO depende do auth para CSRF.
- Sessão: header/footer do site já não têm links de auth (removidos no plano 006).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPUnit site | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/site/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/site/phpunit.xml` | todos passam (1 skip `PAGBANK_TOKEN` esperado) |
| PHPStan/PHPUnit manager | mesmos comandos com `manager` | verdes (sanidade — manager não deve mudar) |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `site/public_html/index.php`, `site/app/inc/urls.php`
- `site/app/inc/controller/auth_controller.php` (deletar), `site_controller.php` (excisar bloco login), `checkout_controller.php` (remover 3º e-mail)
- `site/public_html/ui/page/{login,register,forgot_password,reset_password,set_password,dashboard,home}.php`
- `site/public_html/ui/mail/{verify_email,reset_password,order_confirmation}.php` (deletar)
- `site/public_html/assets/js/alpine/{loginController,registerController,setPasswordController}.js` (deletar)
- `site/tests/` — apenas testes que referenciam `auth_controller` do site ou o e-mail de confirmação (verificar por grep; ver STOP)
- Lixo: `site/app/inc/controller/cart_controller.php\nsite/` (rm), `manager/public_html/ui/page/home.php`, `manager/public_html/ui/mail/verify_email.php`

**Out of scope** (NÃO tocar):
- `site/app/inc/lib/` e `site/app/inc/model/` INTEIROS (cópias compartilhadas — `users_model`, `CommonFunctions`, `EmailProducer` ficam).
- `manager/` além dos 2 arquivos mortos listados (o auth do MANAGER fica 100% intacto).
- `webhook_controller.php`, `track_order_controller.php`, `cart_controller.php`, `shop_controller.php`.
- Tabelas/migrations — NENHUM drop de `users` (o manager usa).
- `ui/common/{head,header,footer,foot}.php` do site — só toque se o grep do Step 5 provar referência a URL removida.

## Git workflow

- Branch: `advisor/021-purge-site-auth`
- Commits em PT-BR, Conventional Commits. Sugerido: 1 commit rotas+controller+views+js+urls, 1 commit home bimodal, 1 commit 3º e-mail, 1 commit lixo/arquivos mortos.
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Inventário de dependências (antes de deletar qualquer coisa)

```bash
grep -rn "auth_controller" site/ --include="*.php" | grep -v vendor | grep -v tests
grep -rn "login_url\|logout_url\|register_url\|area_url\|forgot_password_url\|reset_password_url\|set_password_url\|verify_email_url\|password_url\|tkpwd_url" site/ --include="*.php" | grep -v vendor
grep -rln "auth_controller\|sendConfirmationEmail\|order_confirmation" site/tests/
```

Anote cada consumidor. Esperado: `index.php` (rotas), `site_controller.php` (`check_login`), as próprias views de auth. Qualquer consumidor FORA disso (ex. `header.php`, `checkout_controller` usando `check_login`) → avalie: se for só exibição condicional, remova o condicional junto; se for lógica de negócio, STOP.

**Verify**: lista de consumidores registrada no relatório de execução.

### Step 2: Remover rotas + deletar arquivos de auth

1. `site/public_html/index.php`: delete as linhas de rota listadas no Current state (blocos `:70-89` e `:97`). NÃO toque nas rotas da vitrine/funil.
2. Delete: `auth_controller.php`, as 6 views, os 3 JS, os 2 mail templates, e as 10 constantes de `urls.php`.

**Verify**: PHPStan site → vai ACUSAR o uso órfão em `site_controller.php` (esperado — próximo step). `php -l site/public_html/index.php` → sem erro de sintaxe.

### Step 3: Excisar o modo logado da home

1. `site_controller.php::home()`: remova as linhas do `$isLoggedIn`/`$userId` (Current state). A variável `$isLoggedIn` não deve mais existir no método.
2. `home.php`: remova o bloco MODE 2 inteiro — do `<?php if ($isLoggedIn): ?>` até o `<?php else: ?>` (inclusive ambos), e o `<?php endif; ?>` correspondente no FIM do arquivo. O conteúdo do MODE 1 (vitrine) vira o corpo incondicional. Cuidado: o `endif` final é o par do `if` bimodal — confirme o pareamento lendo o fim do arquivo antes de editar.

**Verify**: PHPStan site → `[OK]`. `grep -n "isLoggedIn\|MODE 2\|ÁREA DO USUÁRIO" site/app/inc/controller/site_controller.php site/public_html/ui/page/home.php` → 0.

### Step 4: Remover o 3º e-mail transacional

`checkout_controller.php`: delete a linha `:238` (chamada) e o método `sendConfirmationEmail()` (`:579-607`) inteiro; delete `site/public_html/ui/mail/order_confirmation.php`.

**Verify**: `grep -rn "sendConfirmationEmail\|order_confirmation\|Recebemos seu pedido" site/ --include="*.php" | grep -v vendor` → 0. PHPStan `[OK]`.

### Step 5: Varredura final de referências + lixo

1. Rode de novo os greps do Step 1 → 0 resultados (fora `vendor/`).
2. Lixo: `rm -rf 'site/app/inc/controller/cart_controller.php'$'\n''site'` (o nome tem newline literal — se o glob falhar, use `find site/app/inc/controller -maxdepth 1 -name 'cart_controller.php?site' -exec rm -rf {} +`). Delete `manager/public_html/ui/page/home.php` e `manager/public_html/ui/mail/verify_email.php` — ANTES de deletar, confirme: `grep -rn "page/home\|mail/verify_email" manager/app manager/public_html --include="*.php" | grep -v vendor` → 0.
3. Testes: os que o grep do Step 1 apontou como acoplados ao auth do SITE — leia cada um. `AuthFunctionsTest.php`/`UsersModelTest.php` cobrem helpers COMPARTILHADOS (`verify_password_with_migration`, rate-limit, model) — devem FICAR (o manager usa); se algum caso referenciar rota/controller do site deletado, remova só o caso, não o arquivo.

**Verify**: `ls site/app/inc/controller/` → exatamente `cart_controller.php checkout_controller.php shop_controller.php site_controller.php track_order_controller.php webhook_controller.php`.

### Step 6: Suítes + fumaça no funil

**Verify**:
- PHPUnit site completo → verde. PHPUnit manager → verde (nada do manager mudou além dos 2 arquivos mortos).
- `bin/check-shared-sync.sh` → exit 0.
- Contra o stack vivo: `curl -s -o /dev/null -w "%{http_code}" -H "Host: infinnityimportacao.local" http://localhost/login` → **404**; o mesmo para `/cadastro`, `/area`, `/esqueci-minha-senha`. Home → **200**. `/carrinho` → **200**. `/acompanhar-pedido` → **200**.
- Fluxo de compra manual (home → carrinho → checkout → tela de PIX) contra o stack vivo até a tela de pagamento — sem erro 500 e sem e-mail "Recebemos seu pedido" em `messages` (`SELECT subject FROM messages ORDER BY idx DESC LIMIT 5`).

## Test plan

Sem testes novos — este plano só remove. A rede de segurança é: suítes completas dos 2 ambientes + PHPStan + os curls do Step 6. Os testes existentes do funil (`CheckoutStockTest`, `CartTest`, `TrackOrderTest`, `OrderFeeBreakdownPersistenceTest`) são a regressão do que fica.

## Done criteria

- [ ] PHPStan `[OK]` nos 2 ambientes; PHPUnit completo verde nos 2
- [ ] `/login`, `/cadastro`, `/area`, `/esqueci-minha-senha`, `/redefinir-senha/x`, `/definir-senha/x`, `/verificar-email/x` no SITE → todos 404; funil (home/carrinho/checkout/acompanhar-pedido) → 200
- [ ] `grep -rn "auth_controller" site/ --include="*.php" | grep -v vendor` → 0
- [ ] `grep -rn "sendConfirmationEmail\|Recebemos seu pedido" site/ | grep -v vendor` → 0
- [ ] Diretório com `\n` no nome não existe mais (`ls site/app/inc/controller/ | wc -l` → 6)
- [ ] `site/app/inc/lib/` e `site/app/inc/model/` SEM NENHUMA mudança (`git diff --stat -- site/app/inc/lib site/app/inc/model` → vazio)
- [ ] `bin/check-shared-sync.sh` exit 0; `git status` sem arquivos fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- Plano 020 ainda não mergeado (o reset do manager apontaria para rota morta) — verifique `grep -n "SITE_CANONICAL_URL" manager/app/inc/controller/site_controller.php` → deve ser 0 ANTES de começar.
- Step 1 revela consumidor de `check_login()`/auth fora de `site_controller::home()` com lógica de negócio.
- Algum teste do funil quebra após o Step 4 (indicaria acoplamento não mapeado do e-mail de confirmação).
- Excertos do Current state não batem (drift).

## Maintenance notes

- O site volta a ser 100% anônimo: sessão só carrega carrinho + CSRF. Se um dia "conta de cliente" voltar ao escopo, é feature nova — não restaurar deste histórico sem redesenho.
- Revisor: conferir que NENHUM arquivo de `lib/`/`model/` mudou (é a linha vermelha deste plano) e que o `endif` da home foi pareado certo (view quebrada em produção é o risco nº 1 aqui).
- Follow-up deferido: as tabelas `users` do site nunca tiveram clientes reais (auth era invisível) — nenhuma limpeza de dados necessária.
