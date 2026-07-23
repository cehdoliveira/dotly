# Plan 038: Nome de item dinâmico no payload enviado ao gateway

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 3b66efe..HEAD -- site/app/inc/controller/checkout_controller.php site/tests/InfinitePayGatewayTest.php site/tests/OrderFeeBreakdownPersistenceTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug (risco operacional com o PSP)
- **Planned at**: commit `3b66efe`, 2026-07-22

## Why this matters

O checkout envia ao PSP um único item genérico cujo nome está hardcoded como
`"Peptídios"`. Esse nome descreve a categoria do produto vendido e pode
disparar bloqueio/revisão da conta pelo gateway (compliance de PSPs costuma
filtrar por palavras-chave no nome do item). Trocar por um nome neutro e
dinâmico — `"{cStoreName} - Pedido #{idx}"`, ex.:
`"Infinnity Importação - Pedido #4821"` — remove a palavra sensível e ainda
melhora a rastreabilidade: o operador consegue casar a cobrança no painel do
PSP com o pedido no manager pelo número.

Decisão do dono do repo (registrada nesta sessão): usar o **idx numérico do
pedido** (não o token) no nome, no formato acima.

## Current state

Arquivos relevantes:

- `site/app/inc/controller/checkout_controller.php` — `finalize()` monta o
  item genérico enviado ao PSP (linhas 175–186). **Único ponto a mudar.**
- `site/app/inc/lib/InfinitePayGateway.php:77` — usa `product_name` como
  `description` do item; a soma `qty × price` dos itens é o valor cobrado.
- `site/app/inc/lib/PagBankGateway.php:49` — usa `product_name` como `name`
  do item.
- `site/app/inc/lib/MercadoPagoGateway.php:32` — **ignora** os items; manda
  `'Pedido ' . token` como `description`. Não é afetado.
- `site/tests/InfinitePayGatewayTest.php:111,121` — fixture usa o literal
  `'Peptídios'` como input arbitrário.
- `site/tests/OrderFeeBreakdownPersistenceTest.php:173–200` — replica a
  montagem do item genérico (comentário na 173 e fixture na 187–188 citam
  `"Peptídios"`).

Excerto de `site/app/inc/controller/checkout_controller.php:175-186` como está
hoje (o `$orderId` já existe nesse ponto — vem de `$order->save()` na linha
159):

```php
// Nao expomos a lista de produtos ao PSP: mandamos um unico item generico
// ("Peptídios") com o valor total ja com taxas. Como o total vira o proprio
// valor do item, a soma dos itens continua batendo com orders.total_cents
// para gateways que cobram pela soma (ex.: InfinitePay), sem precisar de uma
// linha separada de taxas. MercadoPago/PagBank cobram por total_cents e usam
// o item apenas como descricao. O detalhamento real fica em order_items (acima).
$gatewayItems = [[
    'product_name'     => 'Peptídios',
    'variant'          => null,
    'qty'              => 1,
    'unit_price_cents' => $totalCents,
]];
```

Constante da loja — `site/app/inc/kernel.php:69` (gitignored; no CI vem do
`kernel.php.example:68`, onde vale `"Sua Loja"`):

```php
define("cStoreName", "Infinnity Importação");
```

Convenções do repo que se aplicam aqui:

- Constantes são lidas via `constant("cNome")` (ver os `include` no fim de
  `index()` no mesmo controller, ex. `constant("cRootServer")`). Use
  `constant("cStoreName")`, não `cStoreName` direto — é o idiom do projeto e
  evita alarme do PHPStan com constantes definidas em arquivo fora da análise.
- Comentários de código em PT-BR sem acento é o padrão dominante nos
  comentários deste controller — mantenha.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| PHPStan (site) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPUnit (site) | `cd site && php app/inc/lib/vendor/bin/phpunit` | todos os testes passam (precisa de `kernel.php` + DB vivo; suba a stack com `docker compose -f docker/docker-compose.yml up -d` se necessário) |
| Teste filtrado | `cd site && php app/inc/lib/vendor/bin/phpunit --filter InfinitePayGatewayTest` | pass |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 (nada em `lib/` muda neste plano) |

## Scope

**In scope** (os únicos arquivos que você pode modificar):

- `site/app/inc/controller/checkout_controller.php` (linhas 175–186 apenas)
- `site/tests/InfinitePayGatewayTest.php` (fixture)
- `site/tests/OrderFeeBreakdownPersistenceTest.php` (comentário + fixture)
- `plans/README.md` (linha de status)

**Out of scope** (NÃO tocar, mesmo parecendo relacionado):

- `site/app/inc/lib/InfinitePayGateway.php`, `PagBankGateway.php`,
  `MercadoPagoGateway.php` — recebem o item pronto; nada muda neles. Qualquer
  edição em `app/inc/lib/` exige espelhar em `manager/app/inc/lib/` (guard de
  sync bloqueia commit) — não entre nessa.
- `manager/` inteiro.
- `order_items` / `order_items_model` — o detalhamento real por produto
  gravado no banco (linhas 161–173 do controller) continua exatamente como
  está; a mudança é só no payload ao PSP.
- `kernel.php` / `kernel.php.example` — `cStoreName` já existe.

## Git workflow

- Branch: `advisor/038-gateway-item-nome-dinamico` (convenção dos planos
  anteriores, ver `plans/README.md`)
- Commits em PT-BR, Conventional Commits (ex. do log:
  `fix: fecha </head> antes do <body> na pagina de vendas encerradas`).
  Sugestão: `fix: nome de item dinamico no payload ao gateway (cStoreName + nº do pedido)`
- NÃO fazer push nem abrir PR sem instrução do operador.

## Steps

### Step 1: Trocar o nome hardcoded pelo dinâmico

Em `site/app/inc/controller/checkout_controller.php`, substitua o bloco das
linhas 175–186 por:

```php
// Nao expomos a lista de produtos ao PSP: mandamos um unico item generico
// e neutro ("{loja} - Pedido #{idx}") com o valor total ja com taxas — nome
// de produto real no payload arrisca bloqueio de compliance no gateway.
// Como o total vira o proprio valor do item, a soma dos itens continua
// batendo com orders.total_cents para gateways que cobram pela soma
// (ex.: InfinitePay), sem precisar de uma linha separada de taxas.
// MercadoPago/PagBank cobram por total_cents e usam o item apenas como
// descricao. O detalhamento real fica em order_items (acima).
$gatewayItems = [[
    'product_name'     => constant("cStoreName") . ' - Pedido #' . $orderId,
    'variant'          => null,
    'qty'              => 1,
    'unit_price_cents' => $totalCents,
]];
```

**Verify**: `grep -c "Peptídios" site/app/inc/controller/checkout_controller.php`
→ `0`. Depois `cd site && php app/inc/lib/vendor/bin/phpstan analyse` →
`[OK] No errors`.

### Step 2: Atualizar fixtures dos testes

1. `site/tests/InfinitePayGatewayTest.php` (linhas 111 e 121): troque o
   literal `'Peptídios'` nas duas linhas por
   `'Loja Teste - Pedido #4821'` (input arbitrário do teste de serialização —
   o valor com espaço, hífen e `#` continua exercendo o encode do JSON; NÃO
   use `constant("cStoreName")` aqui, o teste é unitário do gateway e não
   deve depender do kernel).
2. `site/tests/OrderFeeBreakdownPersistenceTest.php`:
   - No comentário da linha 173, troque `("Peptídios")` por
     `(nome neutro "{loja} - Pedido #{idx}")`.
   - Na fixture das linhas 187–188, troque
     `'product_name' => 'Peptídios',` por
     `'product_name' => constant("cStoreName") . ' - Pedido #999',`
     (este teste replica a montagem do controller, então aqui SIM espelha o
     formato real; `constant("cStoreName")` funciona tanto local quanto no CI,
     onde vale `"Sua Loja"` — nunca asserte o literal `"Infinnity Importação"`,
     isso quebraria o CI).

**Verify**:
`cd site && php app/inc/lib/vendor/bin/phpunit --filter 'InfinitePayGatewayTest|OrderFeeBreakdownPersistenceTest'`
→ todos passam.

### Step 3: Suíte completa + guard

**Verify**:
- `cd site && php app/inc/lib/vendor/bin/phpunit` → verde (baseline na main
  em 2026-07-22: 274/274).
- `bin/check-shared-sync.sh` → exit 0.
- `git status` → só os arquivos in-scope modificados.

## Test plan

Sem teste novo: não existe teste unitário de `finalize()` inteiro (ele exige
sessão, carrinho, PSP), e extrair um helper para uma concatenação seria
over-engineering. A cobertura vem de:

- `InfinitePayGatewayTest` (fixture atualizada) — continua provando que
  `product_name` vira `description` no corpo enviado, agora com o novo formato
  (espaço, hífen, `#`, acento via `cStoreName` local).
- `OrderFeeBreakdownPersistenceTest` (fixture atualizada) — continua provando
  o invariante "um único item com `unit_price_cents = total com taxas`".
- Done criteria com `grep` prova que o literal sumiu do controller.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -rn "Peptídios" site/app/inc/controller/ site/tests/` → nenhuma ocorrência
- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK]`
- [ ] `cd site && php app/inc/lib/vendor/bin/phpunit` → verde, sem regressão
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `git status` → nenhum arquivo fora do in-scope modificado
- [ ] Linha do plano 038 atualizada em `plans/README.md`

## STOP conditions

Stop and report back (do not improvise) if:

- O bloco em `checkout_controller.php:175-186` não bate com o excerto acima
  (drift desde `3b66efe`).
- `cStoreName` não estiver definido no `site/app/inc/kernel.php` do ambiente
  de execução (`php -r 'require "site/app/inc/kernel.php"; echo constant("cStoreName");'`
  falha) — não invente fallback nem defina a constante você mesmo.
- PHPUnit quebrar em teste NÃO tocado por este plano (pode ser falha
  pré-existente de ambiente — ex. `CheckoutPaymentChargeTest` já teve falhas
  por DB; verifique rodando a suíte na main antes de atribuir ao seu diff, e
  reporte em vez de "consertar").
- A mudança parecer exigir edição em qualquer arquivo de `app/inc/lib/`.

## Maintenance notes

- Se um dia o número exibido ao cliente mudar (ex. pedido passar a ter número
  "amigável" separado do idx), o nome do item ao PSP deve acompanhar — hoje
  ele usa `orders.idx` cru.
- `MercadoPagoGateway` segue mandando `'Pedido ' . token` próprio
  (`MercadoPagoGateway.php:32`); se quiser unificar o texto entre gateways no
  futuro, é mudança na lib compartilhada (sync manager obrigatório) — fora
  deste plano de propósito.
- Revisor: conferir que `order_items.product_name` (detalhamento real no
  banco, linha 166 do controller) NÃO foi alterado — só o payload ao PSP.
- Whitelabel: o nome vem de `cStoreName`, então novas marcas instanciadas via
  `bin/init-whitelabel.sh` já saem corretas sem tocar no controller.
