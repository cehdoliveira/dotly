# Plan 026: Testes diretos de assinatura/parsing dos gateways + investigação do data.id do Mercado Pago

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- site/app/inc/lib/MercadoPagoGateway.php site/app/inc/lib/PagBankGateway.php site/app/inc/lib/InfinitePayGateway.php site/tests/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (testes novos + no máximo 1 correção pontual guiada por doc oficial)
- **Depends on**: none
- **Category**: tests + correctness
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

As funções que decidem se um webhook de pagamento é AUTÊNTICO (`verifyWebhook`) e QUANTO foi pago (`extractPaidAmountCents`) são a lógica de dinheiro mais crítica do sistema — e não têm nenhum teste direto. A cobertura atual passa por `WebhookIdempotencyTest`, que exercita amounts/idempotência via InfinitePay (o único gateway cujo `verifyWebhook` sempre retorna `true`). Uma mutação no manifest HMAC do Mercado Pago ou no hash do PagBank passaria no CI inteiro. De quebra, há uma suspeita concreta a investigar: o `verifyWebhook` do MP monta o manifest com `extractChargeId($rawBody, [])` — query vazia — enquanto o modo de notificação do MP pode entregar `data.id` **só na query string**; nesse cenário a assinatura sempre falha e pagamentos reais ficam presos em `aguardando_pagamento` (falha fechada, mas falha).

## Current state

- `site/app/inc/lib/MercadoPagoGateway.php` (cópia byte-idêntica em `manager/app/inc/lib/`):
  - `:85` `verifyWebhook(string $rawBody, array $headers): bool` — parseia header `x-signature` (pares `ts=`/`v1=`), monta `$manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};"` e compara `hash_hmac('sha256', $manifest, $secret)` com `hash_equals`. **`:116`**: `$dataId = $this->extractChargeId($rawBody, []);` ← query vazia.
  - `:127` `extractChargeId(string $rawBody, array $query): ?string`
  - `:143` `extractPaidAmountCents(string $rawBody): ?int`
- `site/app/inc/lib/PagBankGateway.php`:
  - `:113` `verifyWebhook` — exige `PAGBANK_TOKEN` definido (senão loga e retorna false), header `x-authenticity-token`, e compara `hash('sha256', $token . '-' . $rawBody)` com `hash_equals`. O comentário no código avisa: o body precisa ser RAW.
  - `:132` `extractChargeId` — `payload['qr_codes'][0]['id']`.
  - `:143` `extractPaidAmountCents` — fallback chain: `charges[0].amount.value ?? qr_codes[0].amount.value`, `is_numeric` → int.
- `site/app/inc/lib/InfinitePayGateway.php` — `:73` `verifyWebhook` sempre `true` (decisão registrada, não mudar); `:83` `extractChargeId` (`order_nsu`); `:94` `extractPaidAmountCents`.
- Consumo: `site/app/inc/controller/webhook_controller.php:39` passa `$_GET` real como `$query` para `extractChargeId` no processamento — a assimetria com a linha :116 do MP é o cerne da investigação.
- Segredos: vêm de constantes do `kernel.php` (gitignored). **Nunca** copie valores reais para testes — os testes usam segredos FIXTURE definidos no próprio teste.
- Testes existentes (exemplares estruturais): `site/tests/WebhookIdempotencyTest.php` (DBTestCase, chama métodos do gateway direto), `site/tests/OrderPricingTest.php` (teste puro de lib). Bootstrap dos testes carrega `kernel.php`; testes SEM banco podem estender `TestCase` puro (convenção do repo: "non-DB tests extend plain TestCase").
- Métodos são públicos e puros (sem rede): testáveis por chamada direta com fixtures de body/header. `verifyWebhook` do MP/PagBank lê o secret de constante — verifique no início do método como a constante é lida (`defined(...) ? constant(...)`) e defina a constante fixture no teste apenas se ainda não definida pelo kernel local (`if (!defined('PAGBANK_TOKEN')) define(...)`) — cuidado: se o kernel local JÁ define, calcule o hash esperado do teste com `constant('PAGBANK_TOKEN')` dinamicamente em vez de hardcodar.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPUnit (novos) | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/site/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/site/phpunit.xml --filter GatewayAdapterTest` | todos passam |
| PHPUnit site full | idem sem filter | verde (1 skip esperado) |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `site/tests/MercadoPagoGatewayTest.php`, `site/tests/PagBankGatewayTest.php`, `site/tests/InfinitePayGatewayTest.php` (novos)
- POSSIVELMENTE `site/app/inc/lib/MercadoPagoGateway.php` + `manager/app/inc/lib/MercadoPagoGateway.php` (1 linha, SÓ se a investigação do Step 3 confirmar o bug — edição idêntica nas 2 cópias)

**Out of scope** (NÃO tocar):
- `webhook_controller.php`, `InfinitePayGateway.php` (o `return true` é decisão registrada), `PagBankGateway.php` (nenhuma mudança de produção), `createCharge`/`fetchStatus`/`request()` (testá-los exigiria seam de HTTP — deferido, ver Maintenance).
- `kernel.php` / segredos reais (nunca reproduzir valores).

## Git workflow

- Branch: `advisor/026-testes-gateway`
- Commits em PT-BR, Conventional Commits (`test: ...` para os testes; `fix: ...` separado se o Step 3 confirmar).
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Testes do PagBank (o contrato mais simples)

`site/tests/PagBankGatewayTest.php` — estenda `TestCase` puro (sem banco). Casos, todos com fixtures inline:

1. `verifyWebhook` válido: monte `$rawBody = '{"qr_codes":[{"id":"QRCO_X","amount":{"value":1000}}]}'`, compute `hash('sha256', $token . '-' . $rawBody)` no teste, passe como header `x-authenticity-token` → `true`.
2. Assinatura errada → `false`. 3. Header ausente → `false`. 4. Body reformatado (espaço a mais) com hash do body original → `false` (prova a sensibilidade ao RAW).
5. `extractChargeId`: body com `qr_codes[0].id` → string; body sem → `null`; JSON inválido → `null`.
6. `extractPaidAmountCents`: `charges[0].amount.value` presente → int; só `qr_codes[0].amount.value` → int (fallback); nenhum → `null`; valor não numérico → `null`.

Atenção ao aviso do Current state sobre `PAGBANK_TOKEN` já definido pelo kernel local — o hash esperado é sempre computado com o valor efetivo de `constant('PAGBANK_TOKEN')`, nunca hardcodado. Se a constante não estiver definida no ambiente de teste, o caso 1 deve virar skip com mensagem (mesmo padrão do skip existente de `PAGBANK_TOKEN` na suíte).

**Verify**: `--filter PagBankGatewayTest` → todos passam.

### Step 2: Testes do Mercado Pago e InfinitePay

`site/tests/MercadoPagoGatewayTest.php`:
1. `verifyWebhook` válido: body `{"data":{"id":"123"}}`, headers `x-signature: ts=1700000000,v1=<hmac computado no teste>` + `x-request-id: req-1`; manifest `id:123;request-id:req-1;ts:1700000000;`; HMAC com o secret efetivo (mesma tática do Step 1 para a constante do MP — descubra o nome dela lendo `accessToken()`/o início de `verifyWebhook` no arquivo) → `true`.
2. `v1` adulterado → `false`. 3. Header `x-signature` ausente/malformado → `false`. 4. Body sem `data.id` (o cenário da investigação) → documente o comportamento ATUAL com um assert (`false` hoje).
5. `extractChargeId`: body com `data.id` → string; query `['data_id' => 'q9']` sem body — leia `extractChargeId` (:127-142) para saber qual chave de query ele honra e asserte o comportamento REAL; JSON inválido → `null`.
6. `extractPaidAmountCents`: caminho feliz e ausência → leia :143-151 e cubra os branches reais.

`site/tests/InfinitePayGatewayTest.php` (documentação executável da decisão):
1. `verifyWebhook('qualquer', [])` → `true` (trava a decisão registrada — se alguém mudar, o teste avisa).
2. `extractChargeId`/`extractPaidAmountCents`: branches reais de :83-107.

**Verify**: `--filter "MercadoPagoGatewayTest|InfinitePayGatewayTest"` → todos passam. PHPUnit site completo verde.

### Step 3: Investigar o data.id-na-query do Mercado Pago

1. Consulte a documentação oficial do MP sobre validação de assinatura de webhooks (busque "mercado pago webhooks assinatura x-signature manifest" — a doc oficial especifica se o `id` do manifest vem de `data.id` do body ou do query param `data.id`).
2. Se a doc confirmar que o manifest usa o `data.id` **da query string** (ou que notificações IPN chegam com id só na query): a correção é `MercadoPagoGateway.php:116` passar a query recebida ao `extractChargeId` — o que exige `verifyWebhook` receber a query. Verifique a assinatura da interface/dos chamadores (`webhook_controller.php:39` e a interface comum dos 3 gateways — `grep -n "verifyWebhook" site/app/inc/lib/*.php site/app/inc/controller/webhook_controller.php`). Se a correção couber SEM mudar a assinatura pública (ex.: ler `$_GET` não é aceitável em lib — prefira mudança de assinatura coordenada nos 3 gateways + chamador), implemente nas 2 cópias, atualize o caso 4 do Step 2 para o comportamento novo e adicione um caso de assinatura válida com id vindo da query.
3. Se a doc mostrar que o manifest SEMPRE usa o body (ou a conta usa só notificações "webhooks" com body completo): nenhuma mudança de produção — registre a conclusão com o link da doc na seção de Maintenance do próprio plano e no `plans/README.md`.

**Verify**: se houve mudança — PHPStan 2 envs `[OK]`, `bin/check-shared-sync.sh` exit 0, `diff` das 2 cópias vazio, suíte completa verde. Se não houve — conclusão registrada por escrito.

## Test plan

É o próprio plano (Steps 1-2): ~16 casos novos cobrindo autenticidade e parsing de valor dos 3 gateways. Padrão estrutural: `OrderPricingTest.php` (teste puro de lib, asserts `assertSame`).

## Done criteria

- [ ] 3 arquivos de teste novos; PHPUnit site completo verde
- [ ] Cada gateway tem ao menos: 1 assinatura válida (ou skip documentado), 1 inválida, 1 header ausente, parsing de valor com e sem campo
- [ ] Step 3 concluído com: correção aplicada + testada, OU conclusão "comportamento correto" registrada com fonte
- [ ] PHPStan `[OK]` nos 2 ambientes; `bin/check-shared-sync.sh` exit 0
- [ ] `git status` limpo fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- Os métodos não forem chamáveis isoladamente (ex.: construtor do gateway exige constante indefinida no ambiente de teste e não há como definir fixture sem tocar kernel) — reporte o bloqueio em vez de refatorar a lib.
- O Step 3 confirmar o bug mas a correção exigir mudar a assinatura de `verifyWebhook` em mais lugares do que os 3 gateways + `webhook_controller.php` — reporte o desenho antes de implementar.
- Excertos do Current state não batem (drift).

## Maintenance notes

- Deferido conscientemente: testes de `createCharge`/`fetchStatus` (request-building e mapeamento de status HTTP, incl. o 404→'pendente' do PagBank) exigem um seam de HTTP no `request()` privado — refactor de lib compartilhada, fora do apetite atual. Registrar como candidato se um incidente de gateway acontecer.
- Estes testes travam contratos EXTERNOS (formato de webhook dos PSPs). Se um PSP mudar o formato, o teste quebra ANTES do dinheiro sumir — é o comportamento desejado; atualize fixture + código juntos.
- Revisor: conferir que nenhum secret real apareceu em fixture (os testes devem computar hashes com `constant(...)` em runtime ou usar constantes fixture próprias).
