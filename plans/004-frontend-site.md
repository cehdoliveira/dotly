# 004 — Plano de frontend do site (5 telas)

**Commit base:** `47e8535` · **Depende de:** 002 · **Bloqueia:** nada

## Por que isso importa

O público é **completamente leigo**. Cada campo a mais e cada palavra técnica a mais é
gente desistindo antes de pagar. As telas abaixo são desenhadas contra um único critério:
o comprador nunca precisa decidir o que fazer — só existe uma coisa óbvia pra clicar.

## ✅ Decisão tomada: o tema (2026-07-15)

A referência visual é **clara, com índigo/violeta**. O site hoje é **escuro** por padrão:
`site/public_html/assets/css/main.css:9-36` define `--bg: #060b11`, `--accent: #2563eb`.
`head.php` alterna `data-theme` entre light/dark via `localStorage`.

**Correção a uma afirmação anterior deste plano:** dizia que "não existe bloco
`[data-theme="light"]` no `main.css`" — falso, checado tanto no HEAD atual quanto no commit
base `47e8535`. O bloco **já existe** em `main.css:43-59` com a paleta clara completa
(`--bg: #f4f7fa`, `--surface: #ffffff`, etc.). Não falta CSS, só falta forçar o atributo.

**Escolha: opção 1 (recomendada).** Forçar `data-theme="light"` na vitrine (páginas em
`ui/page/`), já que os tokens existem. Não é preciso escrever CSS novo — só garantir que o
controller/`head.php` emita o atributo fixo nas rotas do site público, sem depender do
toggle de `localStorage` (esse toggle continua servindo a área logada/manager, que não é
escopo deste plano).

Nada abaixo depende de detalhe de implementação além disso — os wireframes valem como estão.

## Contexto obrigatório

- **Views** ficam em `site/public_html/ui/page/`. O controller inclui, nesta ordem:
  `ui/common/head.php` → `ui/common/header.php` → `ui/page/<x>.php` →
  `ui/common/footer.php` → `ui/common/foot.php` (ver
  `site/app/inc/controller/site_controller.php:12-16`). A view **não** tem `<html>` — o
  `head.php` já abriu.
- **Já disponível** (via `head.php`, e a CSP já libera `cdn.jsdelivr.net`): Bootstrap 5.3.3,
  Bootstrap Icons 1.11.3, SweetAlert2 11, fonte Inter, Alpine.js. **Não adicione biblioteca
  nenhuma** — principalmente lib de QR Code: o PSP devolve o PNG pronto em base64
  (plano 002).
- 🔒 **CSP**: `script-src 'self' 'nonce-...'`. Todo `<script>` inline **precisa** de
  `nonce="<?= htmlspecialchars($GLOBALS['cspNonce'] ?? '', ENT_QUOTES, 'UTF-8') ?>"` —
  exemplo vivo no fim do `head.php`. Sem nonce o browser bloqueia **em silêncio**.
  Prefira `.js` externo em `assets/js/alpine/`.
- 🔒 **Escape**: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` em tudo que veio do banco ou do
  comprador. Nome de produto é digitado pelo admin; nome/endereço são digitados pelo
  comprador.
- **Repopular formulário**: `old('campo')` (`CommonFunctions.php:752`) — já devolve
  escapado.
- **Mensagens**: `html_notification_print()` (`CommonFunctions.php:277`) renderiza o que os
  controllers puseram em `$_SESSION["messages_app"]`. Já está no layout; não reinvente.
- **CSRF**: todo `<form method="post">` carrega
  `<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['_csrf_token'] ?? '') ?>">`.

## Regras de UX (valem nas 5 telas)

1. **Mobile-first.** Desenhe em 360px e deixe crescer. Card único por coluna no celular.
2. **Uma ação primária por tela.** Um botão preenchido, cheio de contraste. O resto é link
   de texto. Se você tem 2 botões chamativos, o leigo trava.
3. **Zero jargão.** Proibido na tela: "checkout", "carrinho", "token", "gateway", "SKU",
   "webhook", "status", "sessão", "pendente", "erro 500". Use "Meu Pedido", "Finalizar",
   "Pagamento", "Já paguei?", "Estamos conferindo".
4. **Vocabulário da referência, literal:** "Pedido" (nunca "Carrinho"), "+ Adicionar ao
   Pedido", "Unidade" / "Caixa ×10", "Buscar peptídeo...", "Todos", "99% Purity",
   "Acompanhar meu pedido".
5. **Alvo de toque ≥ 44px.** Os `−` / `+` do card são os controles mais usados no celular.
6. **Dinheiro sempre `R$ 70,00`** — `number_format($cents / 100, 2, ',', '.')`.
   A divisão por 100 é **só de exibição**; a conta é sempre em centavos (plano 001).
7. **Erro fala português de gente.** "Não conseguimos gerar seu PIX agora. Tente de novo em
   instantes." Nunca código, nunca nome de gateway.
8. **Nada de modal no caminho de compra.** Leigo fecha modal por reflexo.

---

## Tela 1 — Home / vitrine (`ui/page/home.php`)

Rota: `GET /?` → `site_controller:home` (já existe). É a tela mais importante: na
referência, o produto entra no pedido **daqui**, sem passar por página de produto.

```
┌──────────────────────────────────────────────┐
│  [logo]                      [🛒 Pedido  2]  │ ← header fixo; badge = Cart::count()
├──────────────────────────────────────────────┤
│                                              │
│   ● Atendimento exclusivo via WhatsApp       │ ← pílula
│                                              │
│   Peptídeos                                  │ ← h1
│   premium                                    │
│   para você                                  │
│                                              │
│   Escolha os produtos, a quantidade e        │
│   finalize seu pedido pagando com PIX.       │
│                                              │
│   [99% Purity] [46+ Peptídeos] [Entrega Rápida]
│                                              │
├──────────────────────────────────────────────┤
│  🔍 [ Buscar peptídeo...                  ]  │ ← GET ?q=, submit no Enter
│                                              │
│  (Todos) (GH Secretagogo) (Nootrópico) …     │ ← chips = SELECT DISTINCT category
│                                              │     link GET ?cat=<slug>; ativo destacado
│  ┌────────────────────┐                      │
│  │ Ipamorelin  [99%]  │ ← nome + selo        │
│  │ GH SECRETAGOGO     │ ← categoria          │
│  │ 10mg               │ ← dosage             │
│  │                    │                      │
│  │ TIPO               │                      │
│  │ [Unidade][Caixa×10]│ ← só se price_box    │
│  │                    │   não for NULL       │
│  │ [−] 1 [+]  R$ 70,00│ ← preço reage ao tipo│
│  │                    │                      │
│  │ [+ Adicionar ao Pedido] │ ← AÇÃO PRIMÁRIA │
│  └────────────────────┘                      │
│  (… 1 coluna no celular, 4 no desktop)       │
└──────────────────────────────────────────────┘
```

Comportamento:

- Cada card é **um `<form method="post" action="/carrinho">`** com
  `action=adicionar`, `products_id`, `variant`, `qty`, `_csrf_token`. Funciona **sem
  JavaScript**; Alpine só troca o preço exibido e o valor do stepper.
- "Unidade"/"Caixa ×10" = dois `<input type="radio">` estilizados como pílula (não um
  `<select>` — o leigo vê as duas opções sem abrir nada). `Caixa ×10` some quando
  `price_box_cents` é NULL; o `×10` vem de `box_qty`, não é literal.
- O `[−] 1 [+]` é `<input type="number" min="1" max="99">` com botões. Alpine só ajusta o
  número.
- Adicionar **volta pra home** (plano 002, Passo 3) com o badge incrementado e um toast
  verde "Ipamorelin adicionado ao seu pedido." — o leigo não é teleportado pra outra tela.
- `stock <= 0` → card apagado, botão vira "Avise-me" desabilitado. Nunca deixe adicionar o
  que não tem.
- Nome do produto linka pra `/produto/<slug>` (Tela 1b), pra quem quer ler a descrição.
- Rodapé: `Infinnity Biopharma · Atendimento: +55 …` e **"Acompanhar meu pedido"** (leva
  a Tela 5 via link do e-mail; se não houver link, texto "o link está no seu e-mail").

**Tela 1b — Produto (`ui/page/product.php`), opcional.** `GET /produto/<slug>`. Fotos,
descrição, mesmo bloco de tipo/quantidade/adicionar. Existe pra link direto e busca do
Google, **não** é passagem obrigatória. Não invista aqui antes das outras 4 estarem de pé.

---

## Tela 2 — Meu Pedido (`ui/page/cart.php`)

Rota: `GET /carrinho` → `cart_controller:index`. **Nunca escreva "Carrinho"** na tela.

```
┌──────────────────────────────────────────────┐
│  ← Continuar comprando                       │ ← link discreto, não botão
├──────────────────────────────────────────────┤
│   Meu Pedido                                 │ ← h1
│                                              │
│  ┌──────────────────────────────────────┐    │
│  │ [foto] Ipamorelin                    │    │
│  │        Unidade · 10mg                │    │
│  │        [−] 2 [+]        R$ 140,00    │    │
│  │                          Remover     │    │ ← link texto, cinza
│  └──────────────────────────────────────┘    │
│  ┌──────────────────────────────────────┐    │
│  │ [foto] Semax                         │    │
│  │        Caixa ×10 · 10mg              │    │
│  │        [−] 1 [+]        R$ 500,00    │    │
│  │                          Remover     │    │
│  └──────────────────────────────────────┘    │
│                                              │
│  ────────────────────────────────────────    │
│   Total                        R$ 640,00     │ ← maior elemento da tela
│                                              │
│  [    Finalizar pedido    ]                  │ ← AÇÃO PRIMÁRIA, largura total
│                                              │
│   🔒 Pagamento por PIX. Você recebe o        │
│      código na próxima tela.                 │
└──────────────────────────────────────────────┘
```

- Vazio → estado vazio honesto: "Seu pedido está vazio." + **"Ver produtos"** (uma ação
  só). Nunca mostre a tela vazia com o botão Finalizar apagado.
- Preço e nome vêm de `Cart::hydrate()`, **do banco** (plano 002, Passo 2).
- `[−]` em quantidade 1 vira "Remover". Mudança de quantidade = POST `atualizar`,
  volta pra `/carrinho`.
- Sem cupom, sem frete, sem "produtos relacionados". Fora de escopo, e cada um deles é uma
  chance a mais do leigo se perder.

---

## Tela 3 — Finalizar (`ui/page/checkout.php`)

Rota: `GET /checkout` → `checkout_controller:index`. **Uma página só**, sem abas, sem passos.

```
┌──────────────────────────────────────────────┐
│  ← Voltar ao pedido                          │
├──────────────────────────────────────────────┤
│   Falta pouco!                               │ ← h1 — humano, não "Checkout"
│   Precisamos de alguns dados para enviar     │
│   seu pedido.                                │
│                                              │
│   SEUS DADOS                                 │
│   Nome completo    [____________________]    │
│   E-mail           [____________________]    │
│    ↳ É pra lá que vai o link do seu pedido.  │ ← microcopy sob o campo
│   WhatsApp         [(00) 00000-0000_____]    │
│                                              │
│   ENDEREÇO DE ENTREGA                        │
│   CEP              [00000-000]               │
│   Rua              [____________________]    │
│   Número  [_____]  Complemento [_________]   │
│   Bairro           [____________________]    │
│   Cidade           [__________]  UF [ ▾ ]    │
│                                              │
│  ────────────────────────────────────────    │
│   2 itens                      R$ 640,00     │ ← resumo colapsado, sem voltar
│                                              │
│  [   Gerar meu PIX   ]                       │ ← AÇÃO PRIMÁRIA
│                                              │
│   Você vai ver o código PIX na próxima tela. │
└──────────────────────────────────────────────┘
```

- **12 campos e nem um a mais** (11 + CPF, decisão do dono 2026-07-15 — ver plano 002 Passo
  6). Adicione o campo CPF em SEUS DADOS, com microcopy "Exigido pelo banco para gerar o
  PIX.", `inputmode="numeric"`. Cada campo novo derruba conversão — este é o único aceito.
  Sem "confirmar e-mail", sem senha, sem cadastro — não existe login neste site.
- `POST /checkout` → `checkout_controller:finalize` (plano 002, Passo 9).
- Erro → volta pra cá; **repopule tudo com `old()`** e mostre a mensagem no topo via
  `html_notification_print()`. Perder o que o leigo digitou é abandono garantido.
- `type="email"` e `inputmode="numeric"` no CEP/WhatsApp — teclado certo no celular.
- UF é `<select>` populado de `$GLOBALS['ufbr_lists']` (`app/inc/lists.php`).
- **Validação real é a do servidor** (plano 002, Passo 9). O HTML5 (`required`) é só
  conforto.

---

## Tela 4 — Pagamento (`ui/page/payment.php`)

Rota: `GET /pagamento/<token>` → `checkout_controller:payment`. **Duas variantes**, conforme
`payment_gateways.mode` (plano 001, Passo 3).

### 4a — modo `qr` (Mercado Pago, PagBank)

```
┌──────────────────────────────────────────────┐
│   Pague com PIX                              │ ← h1
│   Seu pedido está reservado por 30 minutos.  │
│                                              │
│         ┌──────────────────┐                 │
│         │                  │                 │
│         │   [ QR CODE ]    │ ← <img src="data:image/png;base64,...">
│         │                  │                 │
│         └──────────────────┘                 │
│                                              │
│   Abra o app do seu banco e aponte a câmera. │
│                                              │
│   ── ou ──                                   │
│                                              │
│   [ Copiar código PIX ]                      │ ← AÇÃO PRIMÁRIA no celular
│   ↳ Cole no seu banco, em "Pix Copia e Cola" │
│                                              │
│   Total: R$ 640,00                           │
│   ⏱ Expira em 29:41                          │ ← contagem regressiva
│                                              │
│   ● Aguardando seu pagamento...              │ ← polling, atualiza sozinho
│                                              │
│   Pode fechar esta página: o link também     │
│   está no seu e-mail.                        │
└──────────────────────────────────────────────┘
```

- `<img src="data:image/png;base64,<?= $charge['qr_image_base64'] ?>">`. A CSP já permite
  `img-src 'self' data:` (ver header em `site/public_html/index.php`). **Não instale lib de
  QR.**
- Copiar = `navigator.clipboard.writeText()` com fallback `document.execCommand('copy')`
  (WebView de banco é antigo). Botão vira "✓ Copiado!" por 2s. Deixe também o código visível
  num `<textarea readonly>` — tem gente que copia na mão.
- **Polling**: `fetch('/pagamento/<token>/status')` a cada **5s**, `AbortController` com
  timeout, e **pare depois de 30 min** (não deixe aba esquecida martelando o servidor pra
  sempre). `status === 'pago'` → `location.href = '/pedido/<token>'`.
- A contagem regressiva é `expires_at` renderizado em ISO e contado no cliente. Zerou →
  troca por "Este pedido expirou." + "Fazer novo pedido". **Nunca** deixe pagar depois.
- **Não** escreva "pendente", "aguardando_pagamento" nem o nome do gateway. Pro comprador
  é sempre "PIX", nunca "Mercado Pago" ou "PagBank".

### 4b — modo `redirect` (InfinitePay)

```
┌──────────────────────────────────────────────┐
│   Pague com PIX                              │
│   Seu pedido está reservado por 30 minutos.  │
│                                              │
│   Total: R$ 640,00                           │
│                                              │
│   [   Ir para o pagamento   ]                │ ← AÇÃO PRIMÁRIA → redirect_url
│                                              │
│   Você vai para o ambiente seguro de         │
│   pagamento e volta pra cá no fim.           │
│                                              │
│   O link também está no seu e-mail.          │
└──────────────────────────────────────────────┘
```

- Sem QR (não temos — plano 002, Passo 7), sem polling da contagem de QR. `redirect_url` sai
  do banco; `target="_self"`; `rel="noopener"` se for `_blank`.
- Aqui **não há reconciliação**: se o webhook não vier, o pedido expira (plano 002,
  Passo 7). Não prometa na tela o que o backend não garante — por isso o texto é "volta pra
  cá no fim", e não "confirmação instantânea".
- A view escolhe a variante por `$charge['redirect_url'] !== null`. Uma view, dois blocos.

---

## Tela 5 — Confirmação (`ui/page/done.php`)

Rota: `GET /pedido/<token>` → `checkout_controller:done`. É também a tela de "Acompanhar
meu pedido" do e-mail — ela mostra **o status que estiver**, não só sucesso.

```
┌──────────────────────────────────────────────┐
│              ✓                               │ ← ícone grande, verde
│         Pagamento confirmado!                │
│                                              │
│   Recebemos seu pagamento de R$ 640,00.      │
│   Vamos preparar seu envio e falar com você  │
│   no WhatsApp (11) 98888-8888.               │
│                                              │
│   ── Seu pedido ──                           │
│   Ipamorelin · Unidade · 2      R$ 140,00    │
│   Semax · Caixa ×10 · 1         R$ 500,00    │
│   Total                         R$ 640,00    │
│                                              │
│   Entrega em: Rua X, 123 — Bairro,           │
│   Cidade/UF · 00000-000                      │
│                                              │
│   Guarde este link para acompanhar:          │
│   [ Copiar link do pedido ]                  │
│                                              │
│   Dúvidas? [ Falar no WhatsApp ]             │
└──────────────────────────────────────────────┘
```

Um estado por `orders.status` — sempre com **uma** saída óbvia:

| status | Título | Ação |
|---|---|---|
| `pago` | ✓ Pagamento confirmado! | "Falar no WhatsApp" |
| `aguardando_pagamento` | ⏱ Estamos aguardando seu PIX | "Ver o código PIX" → Tela 4 |
| `expirado` | O prazo deste pedido acabou | "Fazer novo pedido" → home |
| `cancelado` | Pedido cancelado | "Fazer novo pedido" → home |

- **Não mostre o token na tela** — mostre o botão "Copiar link do pedido" (que copia a URL
  inteira). O token é a credencial: menos gente o vê solto, melhor.
- 🔒 Adicione `<meta name="robots" content="noindex, nofollow">` nesta view e na Tela 4.
  Pedido no Google é vazamento de dado pessoal. O `head.php` é compartilhado — a forma
  limpa é o controller setar `$noindex = true` e o `head.php` respeitar.
- Nunca mostre "erro", `gateway_charge_id` nem nome de gateway.

---

## Critérios de aceite (binários)

```bash
cd site && php app/inc/lib/vendor/bin/phpstan analyse   # 0 erros
bin/test.sh                                             # verde
```

1. Os 2 comandos passam.
2. Zero jargão nas views:
   ```bash
   grep -rniE "checkout|carrinho|token|gateway|webhook|pendente|aguardando_pagamento" \
     site/public_html/ui/page/{home,cart,checkout,payment,done}.php
   ```
   → só pode aparecer em **atributo de formulário/URL** (ex.: `action="/checkout"`), nunca
   em texto visível. Revise linha a linha.
3. Todo formulário tem CSRF:
   ```bash
   grep -c "_csrf_token" site/public_html/ui/page/cart.php site/public_html/ui/page/checkout.php
   ```
   → `>= 1` em cada.
4. Todo `<script>` inline tem `nonce`:
   ```bash
   grep -rn "<script" site/public_html/ui/page/ | grep -v "nonce" | grep -v "src="
   ```
   → **vazio**.
5. Nenhuma lib nova:
   ```bash
   grep -rniE "qrcode|qrious|jquery" site/public_html/ui/ site/public_html/assets/js/
   ```
   → **vazio**.
6. Manual, no DevTools em 360px: as 5 telas sem rolagem horizontal; cada uma com
   **exatamente um** botão preenchido; adicionar → badge sobe; copiar código PIX funciona;
   com JS desligado, adicionar ao pedido e finalizar **ainda funcionam** (o QR não atualiza
   sozinho — aceitável).

## Teste

Views não têm teste unitário neste repo (não há suíte de browser, e não é hora de
introduzir uma). A cobertura de comportamento é a do plano 002. Aqui a verificação é a
lista manual acima — **rode ela de verdade e cole o resultado no PR**.

Se quiser fixar uma peça, `site/tests/CommonFunctionsTest.php` é o lugar dos helpers de
formatação (ex.: centavos → `R$ 70,00`), caso você extraia um.

## Manutenção

- Card na home e card no carrinho mostram preço em dois lugares. Mudou a regra de preço,
  mudou nos dois. Melhor: um helper de formatação, usado pelas duas.
- Ao revisar: jargão vazando pra tela; botão primário duplicado; campo novo no checkout
  sem alguém ter aprovado; `<script>` sem nonce; lib nova.
- Tela 4 é a mais frágil: mexeu no polling, teste com JS desligado **e** com a aba em
  segundo plano.

## Escape hatches — pare e reporte, não improvise

- ~~A decisão do tema (topo deste plano) → **pare** antes de codar as views.~~ Resolvida
  2026-07-15 (opção 1).
- ~~CPF no checkout → **pare**.~~ Resolvido 2026-07-15: campo obrigatório padrão, 12º campo
  na Tela 3.
- Se alguma tela parecer precisar de uma lib nova, **pare**: quase sempre é sinal de que o
  backend devia estar entregando o dado pronto (foi o caso do QR).
- Se pedirem "só mais um campinho" no checkout, **pare e pergunte** — o número de campos é
  a decisão de produto mais cara desta tela.
