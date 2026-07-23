# Plan 041: Documentar o webhook público-por-design do InfinitePay

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat d3d3293..HEAD -- README.md site/app/inc/kernel.php.example manager/app/inc/kernel.php.example site/app/inc/controller/webhook_controller.php site/app/inc/lib/InfinitePayGateway.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S (documentação apenas — zero mudança de comportamento)
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: commit `d3d3293`, 2026-07-22

## Why this matters

`InfinitePayGateway::verifyWebhook()` retorna `true` incondicionalmente — o
InfinitePay não publica assinatura de webhook, então o endpoint é público por
design. A defesa real já existe e foi auditada (plano 031 + red team do
/ship): reconfirmação `confirmPayment()` (POST /payment_check) fail-closed,
rate limit por token de pedido, guarda de replay com UNIQUE em
`transaction_nsu`, e **nenhum efeito colateral irreversível antes da
reconfirmação** (só leituras de DB + incremento de rate limit em Redis). O que
falta é registrar essa decisão nos lugares onde um operador novo procura:
`README.md` e `kernel.php.example`. Sem isso, o `return true` parece bug em
qualquer auditoria futura e o risco é alguém "consertar" quebrando o fluxo.

**Auditoria que motivou este plano pediu**: documentar explicitamente + garantir
que nenhum efeito irreversível ocorre antes de `confirmPayment()`. A garantia
foi VERIFICADA no código em 2026-07-22 (ver "Current state") — sobrou só a
documentação.

## Current state

- `site/app/inc/lib/InfinitePayGateway.php:124-131` — `verifyWebhook()` com
  comentário interno já explicando o design:

```php
public function verifyWebhook(string $rawBody, array $headers, array $query = []): bool
{
    // InfinitePay nao publica assinatura de webhook. verifyWebhook nao e a camada
    // de autenticacao: a defesa real e a reconfirmacao confirmPayment() (POST
    // /payment_check) que o webhook_controller chama para InfinitePay antes de
    // ...
```

- `site/app/inc/controller/webhook_controller.php` — sequência para
  InfinitePay antes de `confirmPayment()` (linha 121): `extractChargeId()`
  (parse puro), SELECTs em `payment_gateways`/`pix_charges` (leituras), guard
  de idempotência, rate limit por `chargeId` em Redis (linhas 113–119 — única
  mutação, e é em Redis, não em dados de negócio). Nenhum decremento de
  estoque, nenhuma criação de pedido, nenhum UPDATE de status antes da
  reconfirmação. Estoque é decrementado no `finalize()` do checkout, nunca no
  webhook.
- `README.md` — tem seção de arquitetura com bullets sobre webhook/expiração
  (linhas ~84–92: "Job de expiração devolve estoque, e o webhook tem guarda de
  corrida..."). É aí que o novo bullet entra.
- `site/app/inc/kernel.php.example` e `manager/app/inc/kernel.php.example` —
  templates dos kernels gitignorados; contêm as constantes de credencial dos
  gateways (ex.: `INFINITEPAY_HANDLE`). **kernel.php.example NÃO é arquivo de
  lib compartilhada** — as duas cópias podem diferir; edite cada uma no bloco
  do InfinitePay correspondente.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Sanidade PHP do example | `php -l site/app/inc/kernel.php.example` | `No syntax errors` |
| Sync guard (não deve acusar nada — nenhuma lib tocada) | `bin/check-shared-sync.sh` | exit 0 |
| PHPStan (inalterado) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |

## Scope

**In scope** (documentação apenas):
- `README.md`
- `site/app/inc/kernel.php.example`
- `manager/app/inc/kernel.php.example`

**Out of scope** (NÃO tocar — é a razão de ser deste plano):
- `site/app/inc/lib/InfinitePayGateway.php` — o `return true` fica como está.
- `site/app/inc/controller/webhook_controller.php` — fluxo já correto.
- Qualquer tentativa de "adicionar verificação de assinatura" — o PSP não
  oferece; a mitigação real já existe.

## Git workflow

- Branch: `advisor/041-docs-webhook-infinitepay`
- Commit em PT-BR: `docs: webhook InfinitePay publico por design (mitigacoes documentadas)`
- Não faça push nem abra PR sem instrução do operador.

## Steps

### Step 1: Bullet no README.md

Na seção de arquitetura onde estão os bullets de webhook/expiração (~linhas
84–92), adicione um bullet no mesmo estilo dos vizinhos (PT-BR, denso,
apontando arquivos):

> - **Webhook do InfinitePay é público por design.** O PSP não publica
>   assinatura de webhook — `InfinitePayGateway::verifyWebhook()` retorna
>   `true` de propósito e NÃO é a camada de autenticação. A autenticidade vem
>   da reconfirmação `confirmPayment()` (POST /payment_check, fail-closed) que
>   o `webhook_controller` executa antes de qualquer escrita de negócio;
>   antes dela só há leituras + rate limit por token de pedido (Redis) + guarda
>   de replay via UNIQUE em `pix_charges.transaction_nsu` (migration 042).
>   Nenhum efeito irreversível (estoque, pedido, status) ocorre antes da
>   reconfirmação — estoque é movimentado só no checkout, nunca no webhook.
>   Não "consertar" o `return true` adicionando validação de assinatura que o
>   PSP não oferece.

Ajuste o texto ao formato real dos bullets vizinhos (leia a seção antes).

**Verify**: `grep -n "público por design" README.md` (ou o termo exato usado) → 1 match

### Step 2: Comentário nos kernel.php.example

Nos DOIS arquivos (`site/app/inc/kernel.php.example` e
`manager/app/inc/kernel.php.example`), localize o bloco das constantes do
InfinitePay (`INFINITEPAY_HANDLE` etc. — se o manager não tiver o bloco,
adicione o comentário só onde o bloco existir e REPORTE) e adicione acima
dele:

```php
// ATENCAO: o endpoint de webhook do InfinitePay (/webhook/pix/infinitepay) e
// PUBLICO POR DESIGN — o PSP nao publica assinatura de webhook. A autenticacao
// real e a reconfirmacao POST /payment_check (fail-closed) feita pelo
// webhook_controller antes de qualquer escrita; ver README (secao de
// arquitetura) e InfinitePayGateway::verifyWebhook().
```

**Verify**: `php -l` nos dois `.example` → `No syntax errors`
**Verify**: `grep -l "PUBLICO POR DESIGN" site/app/inc/kernel.php.example manager/app/inc/kernel.php.example` → os arquivos onde o bloco existe

### Step 3: Confirmação de zero mudança de comportamento

**Verify**: `git diff --stat` → somente os 3 arquivos do In scope (ou 2, se o
manager não tiver bloco InfinitePay).
**Verify**: `bin/check-shared-sync.sh` → exit 0.

## Test plan

Nenhum teste novo — mudança é 100% documentação. A suíte não precisa rodar
(nenhum `.php` executável tocado); rode PHPStan por higiene se quiser.

## Done criteria

- [ ] README.md contém o bullet sobre o webhook público-por-design com as 3
      mitigações (payment_check fail-closed, rate limit, UNIQUE de replay)
- [ ] `kernel.php.example` (onde houver bloco InfinitePay) contém o aviso
- [ ] `git diff --stat` → só arquivos do In scope
- [ ] `php -l` limpo nos `.example` tocados
- [ ] Linha de status atualizada em `plans/README.md`

## STOP conditions

- Ao reler `webhook_controller.php`, você encontrar QUALQUER escrita de
  negócio (estoque, pedido, status de cobrança) executando antes de
  `confirmPayment()` no ramo InfinitePay — isso invalida a premissa "só
  documentação"; reporte, porque aí o fix é refactor, não docs.
- `verifyWebhook()` do InfinitePay não retornar mais `true` incondicional
  (alguém já mexeu — drift).
- Os `.example` não tiverem nenhum bloco de InfinitePay em nenhum dos dois
  ambientes (a constante pode ter mudado de nome — não invente onde colocar).

## Maintenance notes

- Se o InfinitePay algum dia publicar assinatura de webhook (HMAC/JWKS),
  implementá-la em `verifyWebhook()` e REMOVER os avisos criados aqui — os
  três lugares (README, 2× example) estão listados neste plano.
- Revisor: conferir que NENHUM arquivo `.php` de código executável mudou.
