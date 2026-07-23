# 006 — Refatoração da vitrine: header sem auth, navegação in-page, busca client-side

> Escrito contra o commit `0dacce5`. Rode `git rev-parse --short HEAD`. Se for diferente,
> releia **todos** os trechos citados aqui antes de executar (drift check). Se um trecho
> citado não bater mais com o arquivo real, **PARE e reporte** — não improvise.

---

## 0. Contexto — leia antes de tocar em qualquer arquivo

### O que é este projeto

`infinnity-importacao` é um **starter whitelabel PHP 8.4 + MySQL 8.0 sobre um framework
próprio chamado LEGGO** — não é Laravel, não é Symfony. Não procure `artisan`, `composer
require`, service providers, middlewares ou Eloquent. Nada disso existe aqui.

Dois ambientes dividem um repositório:

- `manager/` — painel administrativo. **FORA DE ESCOPO DESTE PLANO. Não abra.**
- `site/` — vitrine pública. **É o único diretório que você vai editar.**

O produto é uma vitrine de peptídeos. O comprador **não faz cadastro nem login**. O fluxo é:
home (grade de produtos) → carrinho → checkout (uma página) → pagamento PIX → confirmação.
O pedido é identificado por um token opaco enviado por e-mail.

### Por que este plano existe

O `plans/README.md` (planos 001-005, todos DONE e mergeados) registra três pendências
conhecidas que este plano resolve:

- Item 5: *"o botão de alternar tema e os links 'Entrar'/'Criar Conta' continuam visíveis
  durante o funil de compra (herdados do `header.php` compartilhado)"*.
- Item 4: *"O badge '🛒 Pedido N' ficou dentro do conteúdo de `home.php`, não no header
  compartilhado — não aparece nas outras 4 telas, só na home."*
- Item 5: *"busca (`?q=`) e filtro de categoria (`?cat=`) na home se anulam um ao outro na
  UI (o backend já suporta os dois juntos)."*

Ou seja: uma vitrine sem cadastro que ainda mostra "Entrar"/"Criar Conta" no funil inteiro,
um badge de carrinho que só existe numa tela, e dois filtros que brigam entre si.

### Comandos de verificação (você vai usar muito)

Rode **da raiz do repositório**, exceto onde indicado:

```bash
# Análise estática — PHPStan nível 4. Precisa passar com ZERO erros novos.
cd site && php app/inc/lib/vendor/bin/phpstan analyse; cd ..

# Guarda de sincronia entre manager/ e site/. Precisa passar.
bash bin/check-shared-sync.sh

# Sintaxe de um arquivo PHP isolado (rápido, use sempre após editar)
php -l site/public_html/ui/common/header.php
```

**Sobre PHPUnit:** o `bin/test.sh` hoje **não** roda os testes de verdade (falta fixar o
working directory no `docker exec`; o PHPUnit imprime o help e o script parece verde). Não
confie nele. Este plano **não pede testes novos** — veja a seção "Plano de testes".

### Baseline obrigatório

**Antes de editar qualquer coisa**, rode os dois comandos de verificação acima e anote a
saída. Se o PHPStan já estiver vermelho no `main` limpo, **PARE e reporte** — você precisa
saber o que era erro pré-existente e o que foi você que quebrou.

---

## 1. Fatos não-óbvios da arquitetura (ignorar isto = quebrar produção)

Leia os cinco. Cada um já causou ou quase causou um bug neste repositório.

### 1.1 Uma transação global por request

`localPDO` abre uma transação no início de todo request.

- `basic_redir($url)` → **commita** e dá `exit()`.
- `basic_redir($url, rollback: true)` → **reverte** e dá `exit()`.
- `localPDO::__destruct()` → **rollback de segurança** se nenhum redirect explícito rolou.

Controllers **não** chamam `commit()`/`rollback()` na mão.

**A pegadinha, e ela é séria:** `json_response()` (`site/app/inc/lib/CommonFunctions.php:804`)
**não commita** — ela só faz `echo` e `exit()`. Então **qualquer endpoint que grave no banco
e responda com `json_response()` perde a escrita** no rollback do destrutor.

`webhook_controller.php:141-144` é explícito sobre isso:

```php
// silenciosamente descartada. Unica rota do site autorizada a
// commitar na mao — e ela acontece ANTES do json_response() final em
// receive() (abaixo), que so roda depois deste metodo retornar.
$orderUpdate->commit();
```

**Por que este plano está a salvo:** os endpoints JSON que você vai criar mexem **só na
sessão** (`Cart::add/setQty/remove` gravam em `$_SESSION`, nunca no banco) ou **só leem** do
banco (`Cart::hydrate()`). Sessão não participa da transação do MySQL, e leitura não precisa
de commit. **Você não vai escrever nenhum `commit()` neste plano.** Se você se pegar
querendo escrever um, parou — você saiu do escopo. Reporte.

### 1.2 `checkout_controller::finalize()` é intocável

É a única rota que grava pedido + baixa estoque, e ela termina em
`basic_redir(sprintf($payment_url, $token))` (`checkout_controller.php:164`), que commita.

**Decisão do dono do repo (2026-07-16): `finalize()` NÃO será convertida para JSON.** O
submit do formulário de checkout continua sendo um POST nativo que redireciona para a tela de
pagamento. Isso já satisfaz o critério "o único redirect real é a tela de pagamento".

Converter para JSON exigiria um `commit()` manual (violando 1.1) e mexeria no fluxo de
pagamento — as duas coisas exigem autorização que este plano não tem.

**Se você achar que precisa tocar `finalize()`, `payment()`, `status()`, `done()` ou
`webhook_controller.php`: PARE e reporte.**

### 1.3 `app/inc/lib/` e `app/inc/model/` são duas cópias byte-idênticas

`site/app/inc/lib/` e `manager/app/inc/lib/` (idem `model/`) **têm que ser idênticos**. O
`bin/check-shared-sync.sh` roda no pre-commit e bloqueia se divergirem.

**Este plano não toca nenhum arquivo em `app/inc/lib/` nem `app/inc/model/`.** Tudo que você
precisa de lá (`Cart`, `json_response`, `validate_csrf`, `basic_redir`) **já existe e já
funciona**. Você só chama.

**Se você achar que precisa editar algo em `lib/` ou `model/`: PARE e reporte.**

### 1.4 CSP com nonce por request — sem `<script>` inline

`site/public_html/index.php:49` manda:

```
script-src 'self' 'nonce-<random>' 'unsafe-eval' https://cdn.jsdelivr.net
```

Um `<script>` inline sem `nonce="<?php echo $GLOBALS['cspNonce']; ?>"` **é bloqueado pelo
browser**. Por isso **todo JS novo deste plano vai em arquivo externo** sob
`site/public_html/assets/js/alpine/` — que é o padrão já existente e passa pelo `'self'`.

`connect-src` não é declarado, então cai no `default-src 'self'` → `fetch()` para a própria
origem **é permitido**. Não tente chamar domínio externo.

`'unsafe-eval'` está presente porque o build CDN do Alpine precisa. Não remova.

### 1.5 Dispatcher só entende GET e POST

`PUT`/`PATCH`/`DELETE` são silenciosamente ignorados. Todos os endpoints deste plano são GET
ou POST. Rotas ficam em **`site/public_html/index.php`** (não em `urls.php` — `urls.php` só
monta strings de URL).

---

## 2. Dependências — tudo que você precisa já está carregado

**Não instale nada. Não adicione CDN novo. Não crie `package.json`, bundler ou build step.**

Já carregado em `site/public_html/ui/common/foot.php`:

- Alpine.js 3.14.9 (linha 19, `defer`)
- SweetAlert2 11.14.5 (linha 5)
- Bootstrap 5.3.3 bundle (linha 2)

CSS do SweetAlert2 em `head.php:23`. CSS do Bootstrap em `head.php:21`.

### Como carregar um controller Alpine numa tela

`foot.php:8-16` tem um loader dinâmico:

```php
if (isset($alpineControllers) && is_array($alpineControllers) && count($alpineControllers) > 0) {
    $cacheBust = '?v=' . constant('APP_VERSION');
    foreach ($alpineControllers as $controller) {
        $safeController = preg_replace('/[^a-zA-Z0-9_-]/', '', $controller);
        print('<script src="' . constant('cFrontend') . 'assets/js/alpine/' . $safeController . 'Controller.js' . $cacheBust . '"></script>' . "\n    ");
    }
}
```

O controller PHP define `$alpineControllers = ['home'];` **antes** dos includes, e isso
carrega `assets/js/alpine/homeController.js`. Para adicionar um controller `shop`, você usa
`$alpineControllers = ['home', 'shop'];` → carrega `shopController.js`.

**Atenção à ordem:** esses scripts são carregados **sem `defer`**, e o Alpine é carregado
**com `defer`** logo depois. Por isso os controllers registram via
`document.addEventListener('alpine:init', ...)` — que é exatamente o que
`homeController.js:8` já faz. **Siga esse padrão.** Se você registrar `Alpine.data()` fora do
`alpine:init`, não funciona.

---

## 3. Estilo do código — combine com o que já existe

Leia `site/public_html/assets/js/alpine/homeController.js` inteiro (39 linhas) antes de
escrever JS. É o exemplar. Note:

- Cabeçalho `/** ... */` curto explicando o **porquê**, não o **o quê**.
- Comentários em **português sem acento** (`"Nao decide nada do servidor"`). Siga.
- Indentação JS: **4 espaços**. `main.js` usa **2** — não uniformize, cada arquivo mantém o
  seu.
- PHP: 4 espaços, `<?php echo ... ?>` (não `<?= ?>`), `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')`
  em **todo** output de dado dinâmico.
- Commits: **não commite.** Este plano entrega working tree sujo para revisão humana.

---

## 4. Escopo — arquivos

### Em escopo (só estes)

| Arquivo | O que muda |
|---|---|
| `site/public_html/ui/common/header.php` | remove auth, adiciona botão Pedido |
| `site/public_html/ui/common/footer.php` | remove links de auth |
| `site/public_html/ui/page/home.php` | remove `.store-topbar`, larguras, filtros Alpine |
| `site/public_html/ui/page/cart.php` | markup do drawer |
| `site/public_html/ui/page/product.php` | nada obrigatório (ver Passo 8) |
| `site/public_html/ui/page/checkout.php` | markup do painel |
| `site/app/inc/controller/cart_controller.php` | branch JSON |
| `site/app/inc/controller/shop_controller.php` | branch JSON |
| `site/app/inc/controller/checkout_controller.php` | branch JSON **só em `index()`** |
| `site/public_html/assets/js/alpine/shopController.js` | **arquivo novo** |
| `site/public_html/assets/js/alpine/homeController.js` | filtros de busca/categoria |
| `site/public_html/assets/css/main.css` | header + drawer |

### Fora de escopo — NÃO TOQUE

- `manager/` — qualquer coisa
- `docker/`, `.github/`, `bin/`, `migrations/`, `kernel.php`
- `site/app/inc/lib/` e `site/app/inc/model/` — regra de sincronia (1.3)
- `site/app/inc/controller/auth_controller.php`, `webhook_controller.php`
- `site/app/inc/urls.php` — não precisa de URL nova
- `site/public_html/ui/page/login.php`, `register.php`, `dashboard.php`, `payment.php`,
  `done.php`, `forgot_password.php`, `reset_password.php`, `set_password.php`
- `checkout_controller::finalize()`, `payment()`, `status()`, `done()` — ver 1.2
- **A branch MODE 2 de `home.php` (linhas 6-88)** — decisão do dono: fica como está, só é
  reportada. Ver Passo 3.

### Não delete nenhum arquivo

Este plano não deleta arquivo nenhum. Se você achar que precisa, **PARE e reporte**.

---

## 5. Passos

### Passo 1 — Header: fora auth, dentro o botão Pedido

**Arquivo:** `site/public_html/ui/common/header.php`

**Estado atual (arquivo inteiro, 43 linhas):** as linhas 11-37 são um bloco
`if (auth_controller::check_login())` que renderiza ou nome+Sair, ou Entrar+Criar Conta:

```php
                <?php if (auth_controller::check_login()): ?>
                    <?php
                    $userName = htmlspecialchars($_SESSION[constant("cAppKey")]["credential"]["name"] ?? '', ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="ss-navbar-actions">
                        <span class="d-none d-sm-inline" style="font-size:0.78rem;color:var(--text-muted);">
                            <?php echo $userName; ?>
                        </span>
                        <form method="POST" action="<?php echo $GLOBALS['logout_url']; ?>" style="display:inline;">
                            ...
                        </form>
                    </div>
                <?php else: ?>
                    <div class="ss-navbar-actions">
                        <a class="btn btn-ghost btn-sm" href="<?php echo $GLOBALS['login_url']; ?>">
                            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Entrar</span>
                        </a>
                        <a class="btn btn-accent btn-sm" href="<?php echo $GLOBALS['register_url']; ?>">
                            Criar Conta
                        </a>
                    </div>
                <?php endif; ?>
```

**A referência.** `referencias/layout-referencia.html`, `-2.html` e `-3.html` têm o **mesmo**
header. Ele é logo + botão Pedido. **Não tem `<nav>`. Não tem busca.** Verifiquei os três:

```html
<header>
  <div class="header-inner">
    <div class="logo-wrap"><img src="logo.svg" alt="Infinnity Biopharma" style="height:42px;..."/></div>
    <button class="cart-btn" onclick="openCart()">
      <svg width="15" height="15" ...>...</svg>
      Pedido
      <span class="cart-count" id="cartCount">0</span>
    </button>
  </div>
</header>
```

CSS da referência:

```css
header      { position: sticky; top: 0; z-index: 100; background: rgba(255,255,255,.92);
              backdrop-filter: blur(20px); border-bottom: 1px solid var(--border); padding: 0 2rem; }
.header-inner { max-width: 1140px; margin: 0 auto; display: flex; align-items: center;
                justify-content: space-between; height: 68px; }
```

Portanto: **a header final tem logo + botão Pedido, e nada mais.** O briefing menciona
"navegação, busca (se a referência tiver)" — a referência não tem nenhuma das duas. A busca
continua onde está hoje, no corpo da home.

**Substitua as linhas 11-37 por:**

```php
                <a class="store-cart-link" href="<?php echo $GLOBALS['cart_url']; ?>"
                    @click.prevent="$store.shop.openCart()"
                    x-data>
                    <i class="bi bi-bag" aria-hidden="true"></i>
                    Pedido
                    <span class="store-cart-badge" x-show="$store.shop.cartCount > 0"
                        x-text="$store.shop.cartCount"
                        <?php echo Cart::count() > 0 ? '' : 'style="display:none"'; ?>><?php echo (int)Cart::count(); ?></span>
                </a>
```

**Quatro coisas para entender aqui, não copie no piloto automático:**

1. **`Cart::count()` direto, não `$cartCount`.** A variável `$cartCount` só é definida em
   `site_controller::home()` (linha 56) e `shop_controller::product()` (linha 26).
   `cart_controller::index()` e `checkout_controller::index()` **não definem**. Como o header
   é incluído nas cinco telas, usar `$cartCount` daria warning de variável indefinida no
   carrinho e no checkout. `Cart::count()` funciona em qualquer request.

2. **É um `<a href>`, não um `<button>`.** A referência usa `<button onclick>`, mas ali é um
   protótipo 100% client-side. Aqui, sem JS, o link **precisa** navegar para `/carrinho`. O
   `@click.prevent` só intercepta quando o Alpine subiu. Isso é o progressive enhancement
   pedido no briefing.

3. **O `<span>` tem `x-text` E conteúdo PHP.** O PHP pinta o valor correto no primeiro render
   (funciona sem JS); o `x-text` assume depois que o Alpine hidrata. O `style="display:none"`
   inline é para o caso `count == 0` antes do Alpine subir — sem ele o badge "0" pisca.

4. **`x-data` vazio no `<a>`** é obrigatório: `$store` só resolve dentro de um escopo Alpine.

**Não mexa** no `<a class="ss-brand">` (linhas 7-9) nem no `<main id="mainContent">` (linha 42).

**Verificação:**

```bash
php -l site/public_html/ui/common/header.php
# esperado: No syntax errors detected

grep -nE "Entrar|Criar Conta|login_url|register_url|logout_url|check_login" site/public_html/ui/common/header.php
# esperado: NENHUMA saída (exit 1). Se sair qualquer linha, você não terminou.

grep -c "Pedido" site/public_html/ui/common/header.php
# esperado: 1
```

---

### Passo 2 — Footer: fora os links de auth

**Arquivo:** `site/public_html/ui/common/footer.php`

**Decisão do dono do repo (2026-07-16):** remover. Uma vitrine sem cadastro não deve oferecer
"Entrar"/"Criar Conta" no rodapé de todas as telas do funil. **Só a UI sai** — rotas,
`auth_controller.php`, `users_model.php` e as views de login/cadastro ficam intactas.

**Estado atual, linhas 10-17:**

```php
                <div class="ss-footer-links">
                    <a href="<?php echo $GLOBALS['terms_url']; ?>">Termos de Uso</a>
                    <a href="<?php echo $GLOBALS['privacy_url']; ?>">Política de Privacidade</a>
                    <?php if (!auth_controller::check_login()): ?>
                        <a href="<?php echo $GLOBALS['login_url']; ?>">Entrar</a>
                        <a href="<?php echo $GLOBALS['register_url']; ?>">Criar Conta</a>
                    <?php endif; ?>
                </div>
```

**Vira:**

```php
                <div class="ss-footer-links">
                    <a href="<?php echo $GLOBALS['terms_url']; ?>">Termos de Uso</a>
                    <a href="<?php echo $GLOBALS['privacy_url']; ?>">Política de Privacidade</a>
                </div>
```

**Verificação:**

```bash
php -l site/public_html/ui/common/footer.php
grep -nE "Entrar|Criar Conta|login_url|register_url|check_login" site/public_html/ui/common/footer.php
# esperado: NENHUMA saída
```

---

### Passo 3 — Home: tira o topbar órfão e alinha as larguras

**Arquivo:** `site/public_html/ui/page/home.php`

#### 3a. Remova o `.store-topbar` (linhas 96-104)

O botão Pedido agora vive no header (Passo 1). Este bloco virou órfão **por causa da sua
mudança** — por isso você remove (a regra "não delete código morto pré-existente" não se
aplica a órfãos que você mesmo criou).

**Remova exatamente:**

```php
            <div class="store-topbar">
                <a href="<?php echo $GLOBALS['cart_url']; ?>" class="store-cart-link">
                    <i class="bi bi-bag" aria-hidden="true"></i>
                    Pedido
                    <?php if ($cartCount > 0): ?>
                        <span class="store-cart-badge"><?php echo (int)$cartCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
```

Isso deixa a `<div class="container" style="max-width:1100px">` das linhas 95-105 sem
conteúdo. **Remova a div vazia junto** — ela não serve mais para nada.

**Não remova** a `$cartCount` de `site_controller.php:56` nem de `shop_controller.php:26`
neste passo. Veja a nota no fim (Passo 11).

#### 3b. Alinhe as larguras: 1100px → 1140px

Esta é a causa raiz do "desalinhado / estourando o container" do briefing.

`.ss-navbar-inner` (header) usa a classe `.container` do Bootstrap, cuja `max-width` é
**1140px** em telas ≥1200px e **1320px** em ≥1400px. Já `home.php` fixa `max-width:1100px`
inline em **4 lugares**. Resultado: em 1280px a header é 40px mais larga que o conteúdo; em
1440px, 220px mais larga. As bordas nunca alinham.

A referência fixa os dois em **1140px**. Faça o mesmo.

1. Em `home.php`, troque as **4** ocorrências de `max-width:1100px` por `max-width:1140px`.
   Confirme que são 4 e todas em `home.php`:

   ```bash
   grep -c "max-width:1100px" site/public_html/ui/page/home.php   # esperado: 4 ANTES da troca
   grep -rn "max-width:1100px" site/public_html/ui/                # esperado: só home.php
   ```

   (Uma das 4 some junto com a div do passo 3a — então após 3a você troca as 3 restantes.
   Confira o número real antes de afirmar que terminou.)

2. Em `site/public_html/assets/css/main.css`, no bloco `.ss-navbar-inner` que **hoje** está
   assim (linha ~1055):

   ```css
   .ss-navbar-inner {
       display: flex;
       align-items: center;
       justify-content: space-between;
       padding: 0.65rem 0;
   }
   ```

   Adicione o cap e a altura da referência:

   ```css
   .ss-navbar-inner {
       display: flex;
       align-items: center;
       justify-content: space-between;
       padding: 0.65rem 0;
       max-width: 1140px;
       margin: 0 auto;
       min-height: 68px;
   }
   ```

   `min-height` (não `height`) porque o `.store-cart-link` já tem `min-height:44px` — travar
   em 68px fixo pode espremer o botão se a fonte do usuário for maior.

**Não mexa** em `.ss-navbar` (linha 235) — já é `position:sticky; top:0` com blur, igual à
referência.

**Verificação:**

```bash
php -l site/public_html/ui/page/home.php
grep -n "store-topbar" site/public_html/ui/page/home.php    # esperado: nenhuma saída
grep -rn "max-width:1100px" site/public_html/ui/            # esperado: nenhuma saída
```

Visual, no browser (`http://infinnityimportacao.local`):

- Em 1280px e 1440px de largura: a borda esquerda do logo alinha com a borda esquerda do
  primeiro card da grade; a borda direita do botão Pedido alinha com a do último card.
- Em 360px: **nenhum scroll horizontal**. Cole no console:
  `document.documentElement.scrollWidth <= window.innerWidth` → deve dar `true`.
- O botão Pedido aparece nas **5** telas (`/`, `/produto/{slug}`, `/carrinho`, `/checkout`,
  `/pagamento/{token}`), não só na home.

---

### Passo 4 — Negociação de formato: como um controller decide HTML ou JSON

Este passo é **conceitual** — não edite nada ainda. Entenda a convenção antes dos passos 5-8.

**A convenção deste plano:** o request quer JSON quando `$info['get']['format'] === 'json'`.

Por quê assim, e não de outro jeito:

- **Não use o sufixo `.json` das rotas.** Rotas como `/termos-de-uso(\.json|\.xml|\.html)?`
  existem no `index.php`, mas o `Dispatcher` **não lê formato nenhum** — o sufixo é regex
  decorativa herdada do template whitelabel. Não faz nada. (Reportado no fim como código
  morto; **não remova**.)
- **Não use `$info['format']`.** Essa chave existe em `index.php:56` mas é **hardcoded**
  como `".html"` e nunca varia.
- **Não use `X-Requested-With`.** Não é testável com um `curl` simples e não é padrão.

`format=json` é explícito, funciona igual em GET e POST, e é testável na mão com `curl`.

**Regra de ouro (é o "progressive enhancement" do briefing):** o branch JSON é sempre
`if (json) { json_response(...) }` **antes** do caminho HTML atual, e o caminho HTML fica
**exatamente como está hoje**. Sem `format=json`, todo comportamento atual é preservado —
byte por byte. Deep links continuam funcionando, e sem JS o site funciona como hoje.

**Sobre CSRF no branch JSON:** continue chamando
`validate_csrf($post['_csrf_token'] ?? null, $cart_url)` normalmente. Ela vive em `lib/`
(intocável, 1.3) e, se o token for inválido, faz `basic_redir` — ou seja, o `fetch()` vai
receber um 302 seguido de HTML, não JSON. **Isso é tratado no cliente**: o JS confere o
`content-type` da resposta e, se não for JSON, mostra "Sessão expirada, recarregue a página"
no SweetAlert2 (Passo 9). Não tente consertar isso mexendo em `validate_csrf`.

---

### Passo 5 — `cart_controller`: branches JSON

**Arquivo:** `site/app/inc/controller/cart_controller.php` (arquivo inteiro, 51 linhas)

#### 5a. `index()` — ler o carrinho em JSON (para o drawer)

**Estado atual:**

```php
    public function index(array $info): void
    {
        [$lines, $totalCents] = Cart::hydrate();

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        include(constant("cRootServer") . "ui/common/head.php");
        ...
    }
```

**Vira** (assinatura muda de `void` para `void` — `json_response` é `never`, o PHPStan aceita
o early-exit; **não** mude para `never`, porque o caminho HTML retorna normal):

```php
    public function index(array $info): void
    {
        [$lines, $totalCents] = Cart::hydrate();

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        // Leitura do carrinho pro drawer. Cart::hydrate() so le do banco —
        // nao precisa de commit (json_response nao commita, ver plano 006).
        if (($info['get']['format'] ?? '') === 'json') {
            json_response([
                'count'       => Cart::count(),
                'lines'       => $lines,
                'total_cents' => $totalCents,
            ]);
        }

        include(constant("cRootServer") . "ui/common/head.php");
        ...
    }
```

#### 5b. `action()` — escrever no carrinho e responder JSON

**Estado atual (linhas 19-50):**

```php
    public function action(array $info): void
    {
        global $cart_url, $home_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';

        validate_csrf($post['_csrf_token'] ?? null, $cart_url);

        $productId = (int)($post['products_id'] ?? 0);
        $variant   = (string)($post['variant'] ?? '');
        $qty       = (int)($post['qty'] ?? 1);

        switch ($action) {
            case 'adicionar':
                Cart::add($productId, $variant, $qty);
                // Volta pra home (nao teleporta o leigo pra outra tela ao clicar
                // "+ Adicionar ao Pedido" no card).
                basic_redir($home_url);

            case 'atualizar':
                Cart::setQty($productId, $variant, $qty);
                basic_redir($cart_url);

            case 'remover':
                Cart::remove($productId, $variant);
                basic_redir($cart_url);

            default:
                basic_redir($cart_url);
        }
    }
```

**Vira:**

```php
    public function action(array $info): void
    {
        global $cart_url, $home_url;

        $post   = $info['post'] ?? [];
        $action = $post['action'] ?? '';

        validate_csrf($post['_csrf_token'] ?? null, $cart_url);

        $productId = (int)($post['products_id'] ?? 0);
        $variant   = (string)($post['variant'] ?? '');
        $qty       = (int)($post['qty'] ?? 1);

        // Cart::* grava so em $_SESSION, nunca no banco — por isso o branch JSON
        // nao precisa de commit(). Ver plano 006, secao 1.1.
        $wantsJson = ($post['format'] ?? '') === 'json';

        switch ($action) {
            case 'adicionar':
                Cart::add($productId, $variant, $qty);
                break;

            case 'atualizar':
                Cart::setQty($productId, $variant, $qty);
                break;

            case 'remover':
                Cart::remove($productId, $variant);
                break;

            default:
                if ($wantsJson) {
                    json_response(['error' => 'acao invalida'], 400);
                }
                basic_redir($cart_url);
        }

        if ($wantsJson) {
            [$lines, $totalCents] = Cart::hydrate();
            json_response([
                'count'       => Cart::count(),
                'lines'       => $lines,
                'total_cents' => $totalCents,
            ]);
        }

        // Sem JS: 'adicionar' volta pra home (nao teleporta o leigo pra outra
        // tela ao clicar "+ Adicionar ao Pedido" no card); o resto volta pro carrinho.
        basic_redir($action === 'adicionar' ? $home_url : $cart_url);
    }
```

**Cuidado — o `switch` mudou de forma.** Antes, cada `case` terminava em `basic_redir()`,
que é `never` (dá `exit()`), então não precisava de `break`. Agora os cases usam `break` e o
redirect é decidido **depois**, para não duplicar o bloco JSON três vezes. **O
comportamento sem JS tem que ser idêntico ao de hoje:** `adicionar` → home, `atualizar` →
carrinho, `remover` → carrinho, ação inválida → carrinho. Confira um a um.

Note que aqui é `$post['format']` (não `$info['get']['format']`) — é um POST.

**Verificação:**

```bash
php -l site/app/inc/controller/cart_controller.php
cd site && php app/inc/lib/vendor/bin/phpstan analyse; cd ..
# esperado: 0 erros
```

Comportamento (browser, com JS desligado em `about:config` ou DevTools → Settings → Debugger
→ Disable JavaScript):

- Clicar "+ Adicionar ao Pedido" num card da home → volta para a home, badge do header sobe.
- No `/carrinho`, alterar quantidade → volta para `/carrinho` com o valor novo.
- No `/carrinho`, remover → volta para `/carrinho` sem a linha.

---

### Passo 6 — `shop_controller`: branch JSON para o modal de produto

**Arquivo:** `site/app/inc/controller/shop_controller.php` (arquivo inteiro, 36 linhas)

Insira o branch JSON **depois** do guard `if (!$product) { basic_redir($home_url); }` (linha
24) e **antes** de `$cartCount = Cart::count();` (linha 26):

```php
        // Payload do modal de produto. Só leitura — sem commit (ver plano 006, 1.1).
        if (($info['get']['format'] ?? '') === 'json') {
            json_response(['product' => $product]);
        }
```

**Não** mude a assinatura de `product()` — continua `: void`.

**Segurança — leia antes de decidir o payload.** `$product` sai do
`products_model` com `join("images", ...)`. Mandar o array cru significa mandar **toda coluna
que o model seleciona**. Confira o que `products_model::$field` contém:

```bash
grep -n -A20 'field' site/app/inc/model/products_model.php | head -30
```

Se aparecer qualquer coluna que não deva ir para o browser (custo, margem, nota interna,
fornecedor), **PARE e reporte** — aí o payload precisa ser uma allowlist explícita
(`['idx','name','slug','category','dosage','purity_label','description','price_unit_cents','price_box_cents','box_qty','stock','images_attach']`)
em vez do `$product` cru. Não adivinhe: olhe o model.

Lembre que os preços de `home.php` já vão para o HTML público hoje (`home.php:180-182`), então
preço não é segredo. O risco é uma coluna administrativa vir de carona.

**Verificação:**

```bash
php -l site/app/inc/controller/shop_controller.php
curl -s "http://infinnityimportacao.local/produto/<slug-real>?format=json" | head -c 400
# esperado: JSON com a chave "product"
curl -s -o /dev/null -w "%{content_type}\n" "http://infinnityimportacao.local/produto/<slug-real>"
# esperado: text/html — o deep link NAO pode ter mudado
```

Pegue um slug real com:

```bash
docker exec mysql sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SELECT slug FROM \`$MYSQL_DATABASE\`.products WHERE active=\"yes\" LIMIT 1;"' 2>/dev/null
```

---

### Passo 7 — `checkout_controller::index()`: branch JSON para o painel

**Arquivo:** `site/app/inc/controller/checkout_controller.php`

**MEXA SÓ EM `index()`.** `finalize()`, `payment()`, `status()` e `done()` são intocáveis
(1.2). O cabeçalho do arquivo diz isso, e ele continua verdadeiro depois da sua mudança:

```php
/**
 * Checkout transacional. `finalize()` e a UNICA rota que grava o pedido — toda
 * ela roda dentro da transacao global aberta por localPDO e e commitada pelo
 * basic_redir() final. ...
 */
```

**Estado atual de `index()` (linhas 11-31):**

```php
    public function index(array $info): void
    {
        global $cart_url;

        [$lines, $totalCents] = Cart::hydrate();

        if (empty($lines)) {
            $_SESSION["messages_app"]["danger"] = ["Seu carrinho está vazio."];
            basic_redir($cart_url);
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = random_token();
        }

        include(constant("cRootServer") . "ui/common/head.php");
        ...
    }
```

Insira **depois** do bloco do CSRF e **antes** do primeiro `include`:

```php
        // Payload do painel de checkout. Só leitura — quem grava é finalize(),
        // que continua sendo POST nativo com redirect pro pagamento (plano 006, 1.2).
        if (($info['get']['format'] ?? '') === 'json') {
            json_response([
                'lines'       => $lines,
                'total_cents' => $totalCents,
                'csrf_token'  => $_SESSION['_csrf_token'],
            ]);
        }
```

**Sobre devolver o CSRF token no JSON:** é seguro e necessário. O painel de checkout aberto
via AJAX precisa montar o formulário cujo submit é o POST nativo para `/checkout`. O token só
é entregue para uma sessão que já o possui (mesma origem, cookie `SameSite=Lax`,
`HttpOnly`) — não há elevação de privilégio. O carrinho vazio continua redirecionando para
`/carrinho` **antes** deste branch, então o painel nunca abre vazio.

**Verificação:**

```bash
php -l site/app/inc/controller/checkout_controller.php
cd site && php app/inc/lib/vendor/bin/phpstan analyse; cd ..

# finalize() NAO pode ter mudado:
git diff site/app/inc/controller/checkout_controller.php
# esperado: o diff toca SOMENTE index(). Se aparecer qualquer linha
# de finalize/payment/status/done no diff, desfaça.
```

---

### Passo 8 — Markup do drawer e do painel

**Princípio, e ele é o que decide se este passo dá certo:** o drawer/painel é preenchido pelo
JS a partir do JSON. **Não duplique o markup do `cart.php` dentro do `home.php`.** A regra do
briefing — "não duplique regra de negócio" — vale para markup também: preço, total e rótulo
de variante vêm **sempre** do servidor via `hydrate()`, nunca recalculados no JS.

**O drawer vive no `header.php`**, logo depois do `</nav>` e antes do `<main>`, para existir
nas cinco telas. Estrutura (segue os ids da referência: `cartOverlay`, `cartPanel`,
`cartBody`):

```php
    <div class="cart-overlay" x-data x-show="$store.shop.cartOpen"
        @click="$store.shop.closeCart()" x-transition.opacity style="display:none"></div>

    <aside class="cart-panel" x-data x-show="$store.shop.cartOpen"
        x-transition:enter-start="cart-panel--closed" x-transition:leave-end="cart-panel--closed"
        role="dialog" aria-modal="true" aria-label="Meu Pedido" style="display:none"
        @keydown.escape.window="$store.shop.closeCart()">
        <template x-if="$store.shop.cartOpen">
            <div class="cart-panel-inner">
                <!-- cabeçalho, linhas (x-for sobre $store.shop.lines), total, CTA -->
            </div>
        </template>
    </aside>
```

O `<template x-if>` interno garante que as linhas só são montadas quando o drawer abre.

**Atenção — a lição do plano 004 (README item 5):** *"Tela 4 (`payment.php`) tinha todo o
conteúdo dentro de `<template x-if>` — se o Alpine não carregasse, a tela ficava em branco."*
Aqui isso **não** se aplica, porque o drawer é um **extra**: sem Alpine, o `<a href>` do botão
Pedido navega para `/carrinho`, que renderiza `cart.php` normalmente. **Nunca** ponha conteúdo
que precisa existir sem JS dentro de um `x-if`.

**`cart.php` e `checkout.php` continuam existindo e funcionando como página inteira** — são o
fallback e o deep link. Você só mexe neles se precisar de um `id`/classe para o JS reaproveitar
markup. Se não precisar, **não toque**.

**`product.php`: nenhuma mudança obrigatória.** O modal de produto é montado pelo JS a partir
do JSON do Passo 6. `product.php` continua servindo `/produto/{slug}` como página.

**CSS novo em `main.css`** — só o necessário para overlay e painel. Reaproveite os tokens que
já existem (`var(--surface)`, `var(--border)`, `var(--accent)`); **não invente paleta nova**:

```css
/* ---- CART DRAWER ---- */
.cart-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1040; }
.cart-panel {
    position: fixed; top: 0; right: 0; bottom: 0; width: min(420px, 100vw);
    background: var(--surface); border-left: 1px solid var(--border);
    z-index: 1050; display: flex; flex-direction: column; overflow-y: auto;
    transition: transform .25s ease;
}
.cart-panel--closed { transform: translateX(100%); }
```

`420px` é a largura de painel da referência (`max-width: 420px`). O `z-index` fica acima do
`.ss-navbar` (que é `1030`).

**`.store-cart-link` e `.store-cart-badge` já existem** em `main.css:1436` e `:1459` — o
botão Pedido do Passo 1 reusa as duas. **Não redefina.**

**`.store-topbar` (main.css:1429) fica órfã** depois do Passo 3a. **NÃO delete** — CSS morto
não quebra nada e o briefing manda só reportar. Anote no relatório.

**Verificação:** com JS ligado, clicar Pedido abre o drawer sem page load (aba Network do
DevTools: nenhum documento novo, só um XHR para `/carrinho?format=json`). Com JS desligado,
clicar Pedido navega para `/carrinho` e a página renderiza igual a hoje.

---

### Passo 9 — `shopController.js` (arquivo novo): store, drawer, modal, AJAX

**Arquivo novo:** `site/public_html/assets/js/alpine/shopController.js`

Registre nos controllers que renderizam o header — ou seja, **todos**. Em
`site_controller::home()` (linha 58) e `shop_controller::product()` (linha 28) troque:

```php
$alpineControllers = ['home'];
```

por:

```php
$alpineControllers = ['home', 'shop'];
```

E **adicione** `$alpineControllers = ['shop'];` em `cart_controller::index()` e
`checkout_controller::index()`, antes dos includes — hoje esses dois **não definem a
variável**, então o header deles ficaria sem o JS do badge/drawer.

**Estrutura** (siga o padrão de `homeController.js`: registro dentro de `alpine:init`,
comentários em português sem acento, 4 espaços):

```js
/**
 * Shop Controller - Alpine.js
 * Estado global da vitrine: contador do pedido no header, drawer do carrinho e
 * modal de produto. Todo preco/total vem do servidor via JSON — o cliente nunca
 * recalcula dinheiro. Sem JS, os links continuam navegando normalmente.
 */

document.addEventListener('alpine:init', () => {
    Alpine.store('shop', {
        cartCount: 0,
        cartOpen: false,
        lines: [],
        totalCents: 0,
        loading: false,

        init() {
            // Contador inicial vem do DOM que o PHP ja pintou (ver header.php),
            // pra nao gastar um request so pra saber o que a pagina ja sabe.
            const badge = document.querySelector('.store-cart-badge');
            this.cartCount = badge ? parseInt(badge.textContent, 10) || 0 : 0;
        },

        async openCart() { /* fetch /carrinho?format=json, preenche, cartOpen = true */ },
        closeCart() { this.cartOpen = false; },
        async addToCart(payload) { /* POST /carrinho com format=json */ },
        async openProduct(slug) { /* fetch /produto/{slug}?format=json → Swal modal */ },
    });
});
```

**As quatro regras que fazem ou quebram este passo:**

1. **CSRF em toda escrita.** Todo POST manda `_csrf_token`. Leia do DOM
   (`document.querySelector('input[name="_csrf_token"]')`) — todo card já tem um
   (`home.php:225`).

2. **Confira o `content-type` de toda resposta.** Se o CSRF falhar, o servidor responde 302
   → o `fetch` segue → você recebe HTML, não JSON. Sem essa checagem você toma um
   `JSON.parse` explodindo no console — e o critério de aceite é console limpo:

   ```js
   const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
   const type = res.headers.get('content-type') || '';
   if (!res.ok || !type.includes('application/json')) {
       Swal.fire({
           icon: 'error',
           title: 'Não foi possível concluir',
           text: 'Sua sessão pode ter expirado. Recarregue a página e tente de novo.',
       });
       return null;
   }
   return res.json();
   ```

3. **Formate dinheiro a partir dos centavos que o servidor mandou.** `homeController.js:28-37`
   já tem a função `formattedPrice()` com a formatação BRL correta (`R$ 1.234,56`). **Copie o
   padrão dela**, não invente outro. Nunca some/multiplique preço no cliente para decidir
   total: use o `total_cents` do JSON.

4. **`credentials: 'same-origin'`** em todo `fetch` — sem isso o cookie de sessão não vai e o
   carrinho volta vazio.

**Progressive enhancement (o briefing é explícito):** todo handler é `@click.prevent` num
elemento que **já tem `href` ou `type=submit` funcional**. Se o Alpine não subir, tudo navega
como hoje.

**No `home.php`**, o form de cada card (linha 222) mantém `method="post"
action="<cart_url>"` e ganha `@submit.prevent="$store.shop.addToCart(...)"`. O link do nome do
produto (linha 203) mantém o `href` e ganha `@click.prevent="$store.shop.openProduct(slug)"`.

**Feedback de sucesso.** O README (item 4) registra que o toast *"Ipamorelin adicionado ao seu
pedido"* ficou pendente do plano 004. Agora ele é natural — no sucesso do `addToCart`, um
toast do SweetAlert2:

```js
Swal.fire({
    toast: true, position: 'top-end', icon: 'success',
    title: nome + ' adicionado ao seu pedido.',
    showConfirmButton: false, timer: 2200, timerProgressBar: true,
});
```

**Verificação:**

- Adicionar ao carrinho, abrir carrinho, abrir checkout: **zero page load** (Network sem
  documento novo, só XHR).
- Console do browser **sem nenhum erro** — inclusive sem violação de CSP.
- Badge do header atualiza na hora ao adicionar.
- `/produto/{slug}`, `/carrinho`, `/checkout` colados direto na barra de endereço continuam
  renderizando HTML normal.
- Com JS desligado: fluxo inteiro (adicionar → carrinho → checkout → pagamento) funciona
  igual a hoje.

---

### Passo 10 — Busca e filtro de categoria, client-side

**Arquivos:** `site/public_html/ui/page/home.php`, `assets/js/alpine/homeController.js`

#### A decisão, e o porquê

**Client-side, sobre o conjunto já renderizado.** Medido no banco local:

```
40 produtos ativos, 1 categoria distinta, 51 linhas no total
```

`site_controller::home()` (linhas 28-33) **não tem `LIMIT` nem paginação** — ela carrega e
renderiza **todos** os produtos ativos em toda visita. Os 40 cards já estão no DOM antes de
qualquer digitação. Filtrar client-side é: zero request novo, zero latência, zero endpoint
para manter. Ir ao servidor a cada tecla seria pagar rede para reordenar dados que o browser
já tem.

A referência (`referencias/layout-referencia.html`) também filtra client-side —
`filterProducts()` / `renderGrid()` / `setFilter()`.

**Quando revisar:** se o catálogo passar de ~200 produtos, ou se a home ganhar paginação, o
custo vira o payload inicial, não o filtro — aí o certo é paginar e mover busca/filtro para o
servidor via endpoint AJAX. Registre isso no relatório final.

**O `?q=` e o `?cat=` server-side continuam existindo** (`site_controller.php:18-26`) e não
mudam: são o deep link, o fallback sem JS, e o que o Google indexa.

#### Implementação

`home.php` já tem tudo de que você precisa nas linhas 173-186 — `$productName` e
`$productCategory` estão em variáveis por card.

1. Envolva a seção de busca+chips+grade (linhas ~142-267) num escopo Alpine:
   `x-data="productFilter()"`.

2. No input de busca (linha 149), troque o `<form method="get">` por um form que continua
   funcionando sem JS mas não recarrega com JS:

   ```php
   <form method="get" action="<?php echo $GLOBALS['home_url']; ?>" class="store-search"
       @submit.prevent>
       <i class="bi bi-search" aria-hidden="true"></i>
       <input type="search" name="q" placeholder="Buscar peptídeo..."
           x-model.debounce.250ms="query"
           value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
   </form>
   ```

   `x-model.debounce.250ms` é o debounce de ~250ms pedido no briefing — nativo do Alpine, não
   escreva `setTimeout` na mão. O `@submit.prevent` impede o reload no Enter quando há JS; sem
   JS, o Enter submete e o `?q=` server-side responde, como hoje.

3. Cada `<div class="col-12 col-sm-6 col-lg-3">` (linha 187) ganha os dados do filtro e o
   `x-show`:

   ```php
   <div class="col-12 col-sm-6 col-lg-3"
       data-name="<?php echo htmlspecialchars(mb_strtolower($productName), ENT_QUOTES, 'UTF-8'); ?>"
       data-category="<?php echo htmlspecialchars($productCategory, ENT_QUOTES, 'UTF-8'); ?>"
       x-show="matches($el)">
   ```

   `mb_strtolower` no servidor (não `toLowerCase()` no cliente a cada tecla) — nomes de
   peptídeo têm acento, e comparar já-minúsculo com já-minúsculo evita surpresa de locale.

4. Os chips de categoria (linhas 154-162) mantêm o `href` e ganham `@click.prevent`:

   ```php
   <a href="<?php echo $GLOBALS['home_url']; ?>"
       class="category-chip" :class="category === '' ? 'active' : ''"
       @click.prevent="category = ''">Todos</a>
   ```

   e, dentro do `foreach`:

   ```php
   <a href="<?php echo $GLOBALS['home_url'] . '?cat=' . urlencode($categoryName); ?>"
       class="category-chip"
       :class="category === <?php echo json_encode($categoryName); ?> ? 'active' : ''"
       @click.prevent="category = <?php echo json_encode($categoryName); ?>">
   ```

   `json_encode()` gera um literal JS com escape correto — categoria com aspas ou acento não
   quebra o atributo. **Não** use `htmlspecialchars` aqui: o contexto é expressão JS, não
   texto HTML.

5. Em `homeController.js`, adicione `productFilter` **junto** do `productCard` já existente,
   dentro do mesmo `alpine:init`:

   ```js
   Alpine.data('productFilter', () => ({
       // Estado inicial vem do server (?q= e ?cat=), pra que o deep link
       // e o filtro client-side comecem concordando.
       query: new URLSearchParams(location.search).get('q') || '',
       category: new URLSearchParams(location.search).get('cat') || '',

       matches(el) {
           const name = el.dataset.name || '';
           const cat = el.dataset.category || '';
           const q = this.query.trim().toLowerCase();
           // Os dois filtros COMPOEM (bug conhecido: na UI antiga um anulava o outro).
           return (q === '' || name.includes(q)) && (this.category === '' || cat === this.category);
       },
   }));
   ```

6. O empty state (linhas 165-170) hoje é server-side (`if (empty($products))`). Com filtro
   client-side, some tudo e não aparece nada. Adicione um empty state client-side com
   `x-show="visibleCount === 0"`, calculado a partir de `matches()`. **Mantenha o
   server-side também** — ele cobre `?q=xyz` sem resultado, sem JS.

**Efeito colateral desejado:** isso resolve o bug do README item 5 (*"busca e filtro de
categoria se anulam um ao outro na UI"*). Client-side os dois compõem naturalmente — é o `&&`
do `matches()`.

**Verificação:**

- Digitar "ipa" na busca → grade filtra enquanto digita, **aba Network vazia** (nenhum
  request).
- Clicar numa categoria → filtra, **sem reload** (o ícone de reload do browser não pisca).
- Busca + categoria **juntas** → filtram juntas, não se anulam.
- Filtro sem resultado → empty state aparece.
- `?q=ipa&cat=<categoria>` direto na barra de endereço → server-side filtra igual a hoje, e o
  estado do Alpine inicia refletindo os dois.
- Com JS desligado → Enter na busca e clique no chip continuam funcionando via `?q=`/`?cat=`.

---

### Passo 11 — Órfãos que você criou

Só isto. **Não limpe mais nada.**

- `$cartCount` em `site_controller.php:56` e `shop_controller.php:26`: depois do Passo 3a, o
  `home.php` não usa mais. Mas `product.php:3` documenta `$cartCount` no cabeçalho e o
  header agora usa `Cart::count()` direto. **Verifique se sobrou algum uso**:

  ```bash
  grep -rn "cartCount" site/public_html/ui/ site/app/inc/controller/
  ```

  Se **nenhuma view** usar mais, remova as duas atribuições e ajuste o comentário de
  `product.php:3`. Se **alguma** ainda usar, deixe como está. **Não adivinhe: rode o grep.**

- `.store-topbar` em `main.css:1429`: fica órfã. **NÃO delete** — CSS morto, só reporte.

---

## 6. Critérios de aceite (todos verificáveis por comando)

```bash
# 1. PHPStan nível 4, zero erros
cd site && php app/inc/lib/vendor/bin/phpstan analyse; cd ..

# 2. Sincronia manager/site intacta
bash bin/check-shared-sync.sh

# 3. Header sem auth, com Pedido
grep -nE "Entrar|Criar Conta|login_url|register_url|logout_url|check_login" site/public_html/ui/common/header.php   # nenhuma saída
grep -c "Pedido" site/public_html/ui/common/header.php                                                              # 1

# 4. Footer sem auth
grep -nE "Entrar|Criar Conta|login_url|register_url|check_login" site/public_html/ui/common/footer.php              # nenhuma saída

# 5. Larguras alinhadas
grep -rn "max-width:1100px" site/public_html/ui/                                                                     # nenhuma saída

# 6. Nada fora de escopo foi tocado
git status --porcelain | grep -E "^\s*M\s+(manager/|docker/|bin/|migrations/|\.github/)"                            # nenhuma saída
git diff --stat site/app/inc/lib/ site/app/inc/model/                                                               # vazio

# 7. finalize() intocada
git diff site/app/inc/controller/checkout_controller.php                                                            # só index()

# 8. Nenhum commit() novo no site
git diff -U0 site/ | grep -E "^\+.*->commit\(\)"                                                                    # nenhuma saída

# 9. Deep links continuam HTML
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" http://infinnityimportacao.local/carrinho                   # 200 text/html
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" http://infinnityimportacao.local/checkout                   # 200 ou 302
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" "http://infinnityimportacao.local/produto/<slug>"           # 200 text/html

# 10. Endpoints JSON respondem JSON
curl -s -o /dev/null -w "%{content_type}\n" "http://infinnityimportacao.local/carrinho?format=json"                  # application/json
curl -s -o /dev/null -w "%{content_type}\n" "http://infinnityimportacao.local/produto/<slug>?format=json"            # application/json

# 11. Nenhum arquivo deletado
git status --porcelain | grep "^ D"                                                                                  # nenhuma saída
```

**Manuais (browser):**

- Console limpo — zero erro, zero violação de CSP — nas 5 telas.
- Adicionar → abrir carrinho → avançar no checkout: nenhum page load.
- Submit do checkout → **único** redirect real, para `/pagamento/{token}` com o QR PIX.
- Busca filtra ao digitar; categoria filtra ao clicar; os dois compõem.
- 360px: sem scroll horizontal. 1280/1440px: header alinhada com a grade.
- JS desligado: fluxo inteiro ainda funciona.

---

## 7. Plano de testes

**Este plano não pede testes automatizados novos.** Razão honesta: o que ele muda é markup,
CSS e branches de apresentação. A regra de negócio — `Cart`, `hydrate()`, `finalize()` — **não
é tocada**, e já é coberta por `CartTest`, `CartHydrateTest` (21/21) e `CheckoutStockTest`
(5/5) em `site/tests/`, que continuam válidos sem alteração.

**Não escreva teste de DOM/browser** — não existe Playwright/Cypress aqui, e adicionar um é
dependência nova (proibido).

**Se você quiser mesmo cobrir o branch JSON**, o único ponto de valor é `cart_controller::action`
com `format=json` — e ele exige sessão + `$_SESSION`, o que os testes atuais não montam.
**Isso é opcional e fora do escopo.** Se for fazer, `site/tests/CartTest.php` é o exemplar de
estilo, e `DBTestCase` é a classe base para teste que toca o banco (transação + rollback
automático por teste).

**O que você DEVE rodar:** PHPStan (critério 1) e os greps da seção 6. **Não afirme que o
PHPUnit passou** — o `bin/test.sh` está quebrado (não fixa o working directory no `docker
exec`, o PHPUnit imprime o help e o script parece verde). Se quiser rodar de verdade:

```bash
docker exec -e HTTP_HOST=localhost infinnityimportacao \
  php /var/www/infinnityimportacao/site/app/inc/lib/vendor/bin/phpunit \
  -c /var/www/infinnityimportacao/site/phpunit.xml
```

Se não conseguir rodar, **diga isso no relatório** em vez de omitir.

---

## 8. Escape hatches — PARE e reporte se

1. Você precisar de `commit()` ou `rollback()` manual em qualquer lugar (viola 1.1).
2. Você precisar editar `site/app/inc/lib/` ou `site/app/inc/model/` (viola 1.3).
3. Você precisar tocar `finalize()`, `payment()`, `status()`, `done()` ou
   `webhook_controller.php` (viola 1.2).
4. Você precisar deletar qualquer arquivo.
5. Você precisar de dependência nova, CDN novo, `package.json` ou build step.
6. `products_model::$field` expuser coluna administrativa no JSON do Passo 6.
7. O PHPStan já estiver vermelho **antes** de você editar (baseline quebrada).
8. Um trecho citado aqui não bater com o arquivo real (drift — o plano foi escrito contra
   `0dacce5`).
9. O drawer não conseguir montar sem duplicar o markup do `cart.php` — sinal de que o
   desenho precisa de revisão humana, não de copy-paste.

Em todos: **pare, escreva o que encontrou, devolva.** Não improvise contornando a regra.

---

## 9. Nota de manutenção

- **`json_response()` nunca commita.** Todo endpoint JSON futuro que **gravar no banco** vai
  precisar de commit explícito — e isso hoje é privilégio exclusivo do `webhook_controller`.
  Em review, se aparecer `json_response()` num caminho que escreve, é bug até prova em
  contrário.
- **O filtro client-side tem prazo de validade.** Ele é correto em 40 produtos sem paginação.
  Quem adicionar paginação ou levar o catálogo a ~200+ tem que mover busca/filtro para o
  servidor. O `?q=`/`?cat=` server-side segue lá, intacto, exatamente para essa volta ser
  barata.
- **O header agora chama `Cart::count()` a cada request de toda tela.** É sessão pura, sem
  custo de banco. Se `Cart::count()` algum dia passar a consultar o banco, isso vira +1 query
  por página — reavalie ali.
- **Auth continua inteira por baixo.** Só os pontos de entrada na UI saíram. `auth_controller`,
  `users_model`, as rotas e as views de login/cadastro/dashboard estão intactas e funcionais
  por URL direta. Se o produto um dia quiser conta de comprador, é religar UI, não reconstruir.
- **A MODE 2 de `home.php` (linhas 6-88) segue viva e inalcançável** pelo comprador. Ver o
  relatório de código morto.
```
