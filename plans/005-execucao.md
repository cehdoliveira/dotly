# 005 — Plano de execução (branches, aceite, verificação)

**Commit base:** `47e8535` · **Leia este plano ANTES de começar qualquer outro.**

## Por que isso importa

Os planos 001–004 dizem *o quê*. Este diz *em que ordem*, e principalmente *como saber que
acabou*. Cada branch abaixo é uma **fatia vertical**: sai do banco e chega na tela,
verificável sozinha. Ninguém precisa de 6 branches na mão pra ver alguma coisa funcionando.

## Os 3 comandos que decidem tudo

Rode os 3 **antes de abrir cada PR**. Vermelho em qualquer um = a branch não está pronta.
Não há discussão sobre isso.

```bash
# 1. Análise estática — PHPStan level 4, roda no host, nos DOIS ambientes
cd site    && php app/inc/lib/vendor/bin/phpstan analyse
cd manager && php app/inc/lib/vendor/bin/phpstan analyse

# 2. Guard de sincronia — lib/ e model/ têm que ser byte-a-byte idênticos
bash bin/check-shared-sync.sh

# 3. Verificação completa — PHPStan + PHPUnit nos dois ambientes
bin/test.sh
```

`bin/test.sh` é o mesmo que o `.github/workflows/ci.yml` roda (o CI é a autoridade final e
roda o migration runner de verdade contra um MySQL 8.0). Verde local, verde no CI.

Ligue os hooks uma vez, e o guard passa a rodar sozinho:
```bash
git config core.hooksPath .githooks   # pre-commit: PHPStan + sync · pre-push: PHPUnit
```

**O erro nº 1 desta base de código:** criar/editar arquivo em `app/inc/lib/` ou
`app/inc/model/` **num ambiente só**. O pre-commit bloqueia. A correção é sempre *copiar*
pro outro lado:
```bash
cp site/app/inc/model/products_model.php manager/app/inc/model/products_model.php
diff -r site/app/inc/model manager/app/inc/model && echo "SYNC OK"
```
**Nunca** "conserte" editando `bin/check-shared-sync.sh` ou `.githooks/` — estão fora de
escopo e proibidos.

## Sequência de branches

```
feature/pix-schema           (plano 001)
   └─> feature/vitrine       (001 §1-2, 002 §1-3, 004 T1-T2)
         ├─> feature/admin-produtos   (003 §1-2)   ── paralelizável
         └─> feature/checkout         (002 §9, 004 T3)
               └─> feature/pix-gateways  (002 §4-8, 004 T4-T5)
                     ├─> feature/admin-pedidos  (003 §3-4)
                     └─> feature/pix-jobs       (002 §12)  ⚠️ bloqueada por escopo
```

`feature/admin-produtos` sai junto com `feature/checkout` — não dependem uma da outra.
Mas o dono precisa de `admin-produtos` **antes** de qualquer teste manual sério, senão não
há produto pra comprar.

---

### 1 · `feature/pix-schema`

**Entrega:** plano 001 inteiro (migrations `009`–`014`).
**🛑 Bloqueia em:** aprovação do DDL. Criar migration é condição de parada do projeto.

Aceite (binário):
- [ ] `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` → `009`–`014` como `success`, zero `error`
- [ ] Rodar **de novo** → zero `error` (idempotência)
- [ ] `SELECT COUNT(*) FROM payment_gateways` → `3`, e continua `3` após reexecutar
- [ ] As 6 tabelas existem (query do plano 001, Aceite #3)
- [ ] `bin/test.sh` verde

---

### 2 · `feature/vitrine`

**Entrega:** 6 models (×2 ambientes), `lib/Cart.php` (×2), `cart_controller`, rotas de
carrinho, home com grid/busca/filtro, tela "Meu Pedido".
**Planos:** 002 §1-3 · 004 T1-T2.

Aceite (binário):
- [ ] Os 3 comandos verdes
- [ ] `diff -r site/app/inc/model manager/app/inc/model` → sem diferença
- [ ] `diff -r site/app/inc/lib manager/app/inc/lib` → sem diferença
- [ ] `grep -rn "price" site/app/inc/lib/Cart.php` → **nenhuma escrita de preço em `$_SESSION`**
- [ ] `CartTest.php` e `CartHydrateTest.php` passam
- [ ] Manual: adicionar 2 produtos → badge "Pedido 2"; `[−]` até 0 remove; total confere
- [ ] Manual: forjar `qty=-5` e `variant=hack` no POST → rejeitado, sem erro 500
- [ ] Manual: **com JavaScript desligado**, adicionar ao pedido funciona

---

### 3 · `feature/admin-produtos`

**Entrega:** `products_controller` + views + upload.
**Plano:** 003 §1-2, §5-6.

Aceite (binário):
- [ ] Os 3 comandos verdes
- [ ] `grep -n "add_route" manager/public_html/index.php | grep produtos` → **toda** linha com `$authGuard`
- [ ] `curl -s -o /dev/null -w "%{http_code}" http://manager.infinnityimportacao.local/produtos` deslogado → redirect, **nunca 200**
- [ ] `grep -rn "DELETE FROM" manager/app/inc/controller/` → vazio
- [ ] `ProductsValidationTest.php` passa ("R$ 70,00" → `7000`)
- [ ] Manual: criar produto com 2 fotos → aparece na home do site com a capa certa
- [ ] Manual: remover → some da home, e `SELECT active FROM products WHERE idx=?` → `no` (a linha continua lá)

---

### 4 · `feature/checkout`

**Entrega:** `checkout_controller::index`/`finalize`, tela "Falta pouco!", pedido gravado
com estoque baixado. **Ainda sem PIX** — `finalize` termina redirecionando pra um
placeholder da Tela 4.
**Planos:** 002 §9 (menos o passo 10 do PSP) · 004 T3.

> Esta é a fatia mais delicada do projeto: é onde o dinheiro é calculado. Revisão humana
> obrigatória.

Aceite (binário):
- [ ] Os 3 comandos verdes
- [ ] `CheckoutStockTest.php` passa
- [ ] Manual: pedido gravado com `token` de 32 chars hex, `status='aguardando_pagamento'`, `expires_at` = +30min
- [ ] Manual: **preço adulterado no POST é ignorado** — o total do banco é o do banco
- [ ] Manual: estoque insuficiente → nenhum pedido criado (`SELECT COUNT(*) FROM orders` não muda)
- [ ] Manual: erro de validação → volta pro form **com os campos preenchidos** (`old()`)
- [ ] Manual: `SELECT stock FROM products` baixou o valor certo (`qty` ou `qty * box_qty`)
- [ ] Manual: `finalize` com erro forçado → `basic_redir(rollback: true)` → **estoque volta**

---

### 5 · `feature/pix-gateways`

**Entrega:** `PixGateway` + 3 adapters + `GatewayRouter` + `webhook_controller` + polling +
Telas 4 e 5.
**Planos:** 002 §4-8, §10-11 · 004 T4-T5.
**🛑 Bloqueia em:** spike do PagBank/CPF (002 §6) antes de escrever o adapter.

Aceite (binário):
- [ ] Os 3 comandos verdes
- [ ] `grep -rn "validate_csrf" site/app/inc/controller/webhook_controller.php` → **vazio**
- [ ] `grep -c "commit()" site/app/inc/controller/webhook_controller.php` → **exatamente 1**, antes do `json_response` final
- [ ] `grep -rn "mt_rand\|rand(" site/app/inc/lib/GatewayRouter.php` → vazio (só `random_int`)
- [ ] `grep -rniE "qrcode|guzzle|sdk" site/app/inc/lib/vendor/composer/installed.json` → **nenhum pacote novo**
- [ ] `git diff --stat 47e8535..HEAD -- '*/composer.json' '*/composer.lock'` → **vazio**
- [ ] `GatewayRouterTest.php` e `WebhookIdempotencyTest.php` passam
- [ ] **O teste que prova o commit:** dispare o webhook com assinatura válida via `curl`,
      depois, **numa requisição nova**, `GET /pagamento/<token>/status` → `{"status":"pago"}`.
      Se voltar `aguardando_pagamento`, o `__destruct` comeu seu commit (002 §11).
- [ ] Webhook com assinatura inválida → **401**, e o pedido **não** muda de status
- [ ] Webhook reentregue 2× → **uma** transição, resposta 200 nas duas
- [ ] Webhook com valor **menor** que `total_cents` → **não** marca pago, loga `warning`
- [ ] Webhook de cobrança desconhecida → **200** (nunca 404 — 404 gera retry infinito)
- [ ] Manual em sandbox: MP e PagBank mostram QR + copia-e-cola; InfinitePay mostra
      "Ir para o pagamento"
- [ ] Manual: com todos os gateways `enabled='no'` → checkout falha com mensagem humana,
      **sem estoque perdido**

---

### 6 · `feature/admin-pedidos`

**Entrega:** `orders_controller` (listagem + detalhe), `gateways_controller` (limites).
**Plano:** 003 §3-4.

Aceite (binário):
- [ ] Os 3 comandos verdes
- [ ] `grep -n "\$post\['slug'\]\|\$post\['mode'\]" manager/app/inc/controller/gateways_controller.php` → **vazio**
- [ ] `GatewaysActionTest.php` e `OrdersFilterTest.php` passam
- [ ] `?status=<injeção>` → ignorado, sem erro, sem SQL injection
- [ ] Nenhuma rota de escrita em `orders` (nada de "marcar como pago")
- [ ] Manual: `customer_name` = `<script>alert(1)</script>` → aparece **escapado** na tela
      do admin, não executa

---

### 7 · `feature/pix-jobs` ⚠️

**Entrega:** `pix_expire.php` e `pix_reconcile.php`.
**Plano:** 002 §12.
**🛑 BLOQUEADA:** `site/cgi-bin/` **não está** no escopo autorizado, e o agendamento vive em
`docker/`, explicitamente proibido. **Peça extensão de escopo antes de abrir esta branch.**

Enquanto estiver bloqueada, aceite conscientemente: **cobrança expirada não devolve
estoque automaticamente**, e não há fallback se um webhook se perder. Não contorne com
expiração preguiçosa no request do comprador — pedido abandonado nunca mais é lido, e o
estoque fica preso pra sempre.

Aceite, quando destravar:
- [ ] Os 3 comandos verdes
- [ ] `pix_expire.php` 2× seguidas → mesma quantidade de pedidos expirados (idempotente)
- [ ] Estoque volta ao valor de antes do pedido expirado
- [ ] Pedido `pago` **nunca** é tocado pelo job de expiração
- [ ] Ambos os scripts fazem `commit()` explícito (são CLI — não há `basic_redir`)

---

## Padrão de commit e PR

Conventional Commits em PT-BR (preferência do dono do repo):

```
feat: adiciona carrinho em sessão e vitrine de produtos
fix: corrige commit ausente no webhook do PIX
chore: sincroniza models entre manager e site
```

Todo PR descreve: **o que entregou**, **o resultado colado dos 3 comandos**, e **o que
ficou de fora**. Se um critério de aceite não foi rodado, diga que não foi rodado — não
escreva "funcionando" sem ter visto funcionar.

## Ordem de revisão humana

Nem tudo pede o mesmo olho. Peça revisão atenta em:

1. `feature/checkout` — é onde o dinheiro é calculado.
2. `feature/pix-gateways` — é onde o dinheiro é confirmado.

O resto (vitrine, admin) é CRUD e roda no piloto automático dos 3 comandos.

## Escape hatches — pare e reporte, não improvise

- Qualquer 🛑 acima (DDL, CPF do PagBank, escopo do `cgi-bin`).
- Vontade de instalar pacote Composer → **pare**. O desenho inteiro existe pra evitar isso.
- `bin/check-shared-sync.sh` acusando divergência que "só dá pra resolver" mexendo no guard
  → **pare**. Você está resolvendo o problema errado.
- Um critério de aceite que parece impossível de cumprir → **pare e reporte**. Um critério
  errado é bug do plano, e o plano se conserta. O que não se faz é baixar a régua em
  silêncio.
