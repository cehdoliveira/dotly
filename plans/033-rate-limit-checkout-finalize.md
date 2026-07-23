# Plan 033: Rate limit em POST /checkout (finalize)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0c3158b..HEAD -- site/app/inc/controller/checkout_controller.php`
> If it changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (mas o valor completo aparece após o plano 032, que faz o
  estoque voltar — ver "Why")
- **Category**: security
- **Planned at**: commit `0c3158b`, 2026-07-20

## Why this matters

`POST /checkout` (`checkout_controller::finalize()`) é a rota mais cara e com
efeito colateral irreversível-por-request do site — **decrementa estoque real,
grava pedido e cria uma cobrança PIX real no PSP** — e é a única das três rotas
POST públicas **sem rate limit**. As auxiliares têm: `/checkout/cep` (30/60s) e
`POST /acompanhar-pedido` (5/300s). Sem throttle, um script postando `/checkout`
em loop zera o estoque de um produto (permanente até o plano 032 existir) e
polui o gateway com milhares de cobranças PIX fantasmas (custo/reputação, risco de
ban do PSP). A correção é uma linha do padrão já usado nas outras duas rotas.

## Current state

- **Mecanismo de rate limit do projeto** —
  `site/app/inc/lib/CommonFunctions.php:447`:
  `check_and_increment_rate_limit(?object $redis, string $key, int $max, int $window): bool`
  Retorna `true` quando o limite foi **estourado** (deve bloquear). Usa Redis se
  disponível e cai para um **fallback em filesystem com `flock`** quando `$redis`
  é `null` (só é fail-open no pior caso: nem Redis nem FS disponíveis).

- **Os dois únicos chamadores hoje**:
  - `site/app/inc/controller/checkout_controller.php:328-334` (`cep()`, 30/60s):
    ```php
    $redis = $GLOBALS['redis'] ?? null;
    $rateKey = "checkout_cep:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (check_and_increment_rate_limit($redis, $rateKey, 30, 60)) {
        json_response(['error' => 'Muitas consultas de CEP. Aguarde um instante.'], 429);
    }
    ```
  - `site/app/inc/controller/track_order_controller.php:19-24` (`search()`,
    5/300s):
    ```php
    $redis   = $GLOBALS['redis'] ?? null;
    $rateKey = "track_order:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (check_and_increment_rate_limit($redis, $rateKey, 5, 300)) {
        $_SESSION["messages_app"]["danger"] = ["Muitas tentativas. Aguarde alguns minutos."];
        basic_redir($GLOBALS['track_order_url']);
    }
    ```

- **`finalize()` NÃO chama** `check_and_increment_rate_limit` (grep confirma só os
  dois callers acima). O topo do método (`checkout_controller.php:58-97`) faz, em
  ordem: `validate_csrf()` (`:65`), guarda anti-duplo-submit por token de sessão
  (`:81-83`), `Cart::hydrate()` (`:85`), `validateCustomer()` (`:92`),
  `lockAndValidateCart()` (`:97`), e só então decrementa estoque (`:114-120`) e
  cria a cobrança (`:196`). O rate limit deve entrar **logo após o `validate_csrf`
  e antes do `Cart::hydrate()`**, para barrar o flood antes de qualquer trabalho
  ou efeito colateral.

- A guarda anti-duplo-submit (`:81-83`) NÃO é rate limit: ela só evita reenvio do
  **mesmo** token na mesma sessão. N submits com tokens novos passam direto.

- Rota: `public_html/index.php:83`:
  `$dispatcher->add_route("POST", "/checkout", "checkout_controller:finalize", ...)`

## Convenções do repositório

- `checkout_controller.php` é controller do **site** — arquivo único, **não**
  compartilhado (não vai em `manager/`). Sem shared-sync aqui.
- `finalize()` retorna `never` e usa `basic_redir()` (que commita a transação
  global). Ao bloquear por rate limit, redirecione com `basic_redir($checkout_url)`
  e mensagem — **não** use `json_response` aqui (finalize é submit nativo com
  redirect, não AJAX; ver `plans/README.md`, decisão "finalize() continua nativo").
- Chave por `REMOTE_ADDR`, mesmo padrão das outras rotas.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHP lint | `php -l site/app/inc/controller/checkout_controller.php` | `No syntax errors` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Grep do novo caller | `grep -n check_and_increment_rate_limit site/app/inc/controller/checkout_controller.php` | 2 linhas (cep + finalize) |

## Scope

**In scope**: `site/app/inc/controller/checkout_controller.php` (só o método
`finalize()`).

**Out of scope**:
- `cep()` / qualquer outra rota — não mexa nos limites existentes.
- O mecanismo `check_and_increment_rate_limit` em si.
- Expiração/estorno de estoque (plano 032) e webhook (plano 031).

## Git workflow

- Branch: `advisor/033-rate-limit-checkout`
- Commit PT-BR Conventional Commits (`fix:` ou `security:`). Ex. do histórico:
  `fix: remover etapa "Entrega" do rastreio de pedido`.
- Sem push/PR salvo instrução do operador.

## Steps

### Step 1: Adicionar o rate limit no topo de `finalize()`

Em `site/app/inc/controller/checkout_controller.php`, dentro de `finalize()`,
**logo após** `validate_csrf($submittedToken, $checkout_url);` (`:65`) e **antes**
da guarda de `_finalized_tokens` (`:81`), insira:

```php
// Rate limit por IP: finalize() decrementa estoque e cria cobranca real no PSP —
// sem throttle, um flood esvazia o estoque e polui o gateway. Mesmo mecanismo de
// checkout_controller::cep() e track_order_controller::search(). Limite conservador
// pra nao atrapalhar retry legitimo apos erro de PIX.
$redis   = $GLOBALS['redis'] ?? null;
$rateKey = "checkout_finalize:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (check_and_increment_rate_limit($redis, $rateKey, 8, 60)) {
    $_SESSION["messages_app"]["danger"] = ["Muitas tentativas de finalizar o pedido. Aguarde um instante e tente de novo."];
    basic_redir($checkout_url);
}
```

Notas:
- `8, 60` = no máximo 8 finalizações por IP por 60s. Folga suficiente para o
  comprador que erra e refaz (ex.: PIX falhou, corrige dados, tenta de novo), mas
  corta flood automatizado.
- Colocar ANTES da guarda de duplo-submit é intencional: barra o abuso antes de
  qualquer leitura de carrinho/estoque.
- `$checkout_url` já está no `global` no topo de `finalize()` (`:60`) — não
  redeclare.

**Verify**: `php -l site/app/inc/controller/checkout_controller.php`
→ `No syntax errors`.

### Step 2: PHPStan

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse`
→ `[OK] No errors`.

## Test plan

Não há teste automatizado das outras duas rotas de rate limit no repo (é
side-effect de infra: Redis/FS + `REMOTE_ADDR`), então **não** se espera um teste
unitário novo aqui — seguir o precedente. A verificação é:

1. **Estático**: PHPStan verde + `php -l` + o grep de done criteria.
2. **Manual (registrar no PR, rodar se houver stack Docker vivo)**: com o site no
   ar, postar `/checkout` mais de 8× em <60s do mesmo IP e confirmar que a 9ª cai
   na mensagem "Muitas tentativas..." e volta pro checkout **sem** criar pedido.
   Se não houver ambiente para o teste manual, registre no PR que ficou pendente
   de verificação em ambiente vivo.

Se você quiser cobertura automatizada e existir um padrão de mock do
`check_and_increment_rate_limit` no repo (verifique com
`grep -rn "check_and_increment_rate_limit" site --include='*Test.php'`), siga-o;
caso não exista, não invente infraestrutura de teste nova só para isto.

## Done criteria

- [ ] `grep -n check_and_increment_rate_limit site/app/inc/controller/checkout_controller.php`
      retorna **2** linhas (a de `cep()` e a nova em `finalize()`).
- [ ] O novo bloco está entre `validate_csrf` e a guarda `_finalized_tokens`
      (antes de `Cart::hydrate()`).
- [ ] `php -l` passa; PHPStan `[OK] No errors` em `site/`.
- [ ] `git status` mostra só `checkout_controller.php` modificado.
- [ ] Linha de status em `plans/README.md` atualizada.

## STOP conditions

Pare e reporte se:

- O topo de `finalize()` divergir das linhas citadas (drift) — em especial se
  `validate_csrf` ou a guarda de duplo-submit tiverem mudado de lugar.
- Você concluir que `REMOTE_ADDR` não é o IP real do cliente em produção (atrás de
  proxy sem `real_ip`) — o mesmo cuidado do plano 031 Step 2. Nesse caso a chave
  por IP agrupa todos os clientes atrás do proxy num único balde; reporte para
  decidir a chave correta (o mesmo problema afeta `cep`/`track_order` hoje, então
  provavelmente já está resolvido no nginx — confirme antes de assumir o contrário).

## Maintenance notes

- **Revisor deve escrutinar**: posição do bloco (antes dos efeitos colaterais) e o
  limite (8/60 é um chute conservador — ajustável se atrapalhar conversão real).
- **Reforço opcional (follow-up)**: uma 2ª chave por CPF/e-mail do payload
  pegaria abuso distribuído por muitos IPs; não incluído aqui por simplicidade e
  porque a chave por IP já corta o caso comum.
- **Interação com o plano 032**: enquanto o job de expiração/estorno não existir,
  o estoque esvaziado por flood NÃO volta — este rate limit é a primeira linha de
  defesa; o plano 032 é a rede de recuperação. Idealmente ambos entram.
