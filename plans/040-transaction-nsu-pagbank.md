# Plan 040: Gravar transaction_nsu do PagBank no webhook (e documentar o caso MP)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat d3d3293..HEAD -- site/app/inc/controller/webhook_controller.php site/app/inc/lib/PixGateway.php site/app/inc/lib/PagBankGateway.php site/app/inc/lib/MercadoPagoGateway.php site/app/inc/lib/InfinitePayGateway.php manager/app/inc/lib/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: MED (toca fluxo de confirmação de pagamento + coluna UNIQUE)
- **Depends on**: none
- **Category**: correctness (reconciliação/chargeback)
- **Planned at**: commit `d3d3293`, 2026-07-22

## Why this matters

Em disputa/chargeback, o PSP referencia a transação pelo id de transação
(NSU-equivalente). Hoje `pix_charges.transaction_nsu` só é preenchido para
InfinitePay; para PagBank fica NULL, e a reconciliação depende só de
`gateway_charge_id` — que para PagBank é o id do **QR code** (`QRCO_...`),
não o da **cobrança** (`CHAR_...`) que o PagBank usa em disputas. Sem o
`CHAR_...` gravado, cruzar uma contestação formal com o pedido exige consulta
manual à API. Este plano captura o id de transação do PagBank a partir do
webhook (já verificado por assinatura) e documenta por que o Mercado Pago não
precisa de campo novo.

**Decisão de design (não reabrir)**: para Mercado Pago, `gateway_charge_id` JÁ
É o `payment_id` (a cobrança é criada via `POST /v1/payments` e o webhook
notifica esse mesmo id) — o NSU-equivalente do MP já está persistido. Gravar o
mesmo valor de novo em `transaction_nsu` seria redundante e interagiria à toa
com a UNIQUE key. Para MP a entrega deste plano é **documentação em docblock**,
não código.

## Current state

- `site/app/inc/lib/PixGateway.php` — interface dos 3 adapters. Métodos atuais:
  `createCharge()`, `verifyWebhook()`, `extractChargeId()`,
  `extractPaidAmountCents()`, `fetchStatus()`.
- `site/app/inc/lib/PagBankGateway.php` — adapter PagBank.
  `extractChargeId()` (linhas 132–141) retorna `qr_codes[0].id`;
  `extractPaidAmountCents()` (143–153) já lê `charges[0].amount.value` do
  payload do webhook — ou seja, o array `charges[0]` do webhook é estrutura já
  conhecida/confiável no código. O id da cobrança é `charges[0].id`
  (`CHAR_...`). `verifyWebhook()` (113–130) valida assinatura sha256 com
  `PAGBANK_TOKEN` — o body é autenticado.
- `site/app/inc/lib/MercadoPagoGateway.php` — `extractPaidAmountCents()`
  (linhas 150–157) retorna null por design (webhook do MP só traz o
  payment_id; valor confirmado via `fetchStatus()`).
- `site/app/inc/lib/InfinitePayGateway.php` — o `transaction_nsu` do
  InfinitePay vem de `confirmPayment()` (POST /payment_check), NÃO do corpo do
  webhook. Não mexer.
- `site/app/inc/controller/webhook_controller.php` — ponto onde o update é
  montado:

```php
// webhook_controller.php:196-207
$chargeUpdateData = [
    'status'  => 'pago',
    'paid_at' => $paidAt,
];
// Grava o transaction_nsu reconfirmado (InfinitePay). A UNIQUE key da
// migration 042 e a garantia real contra replay — ...
if ($infinitepayTransactionNsu !== null) {
    $chargeUpdateData['transaction_nsu'] = $infinitepayTransactionNsu;
}
```

- `migrations/042_add_transaction_nsu_to_pix_charges.sql` — coluna
  `transaction_nsu VARCHAR(64) DEFAULT NULL` + `UNIQUE KEY
  uq_pix_charge_transaction_nsu`. NULL não conflita na UNIQUE (MySQL permite
  múltiplos NULL). Formatos por PSP são disjuntos (InfinitePay = UUID,
  PagBank = `CHAR_...`), então não há colisão cross-gateway.
- `site/app/inc/model/pix_charges_model.php` — `$field` já inclui
  `transaction_nsu` (nada a mudar no model).
- **Regra do repo**: `app/inc/lib/` é byte-idêntico entre `site/` e
  `manager/`. Toda mudança em `PixGateway.php`/`PagBankGateway.php`/
  `MercadoPagoGateway.php`/`InfinitePayGateway.php` vai nas DUAS cópias;
  `bin/check-shared-sync.sh` bloqueia divergência. O
  `webhook_controller.php` é per-env (só site).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |
| PHPUnit site (Docker) | `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit` | sem regressão vs baseline |
| Testes focados | `... phpunit --filter 'PagBank\|Webhook'` | passam |

NÃO use `bin/test.sh` (bug conhecido: sem `-w`, o PHPUnit imprime help e
parece verde).

## Scope

**In scope**:
- `site/app/inc/lib/PixGateway.php` + `manager/app/inc/lib/PixGateway.php` (novo método na interface)
- `site/app/inc/lib/PagBankGateway.php` + cópia manager (implementação real)
- `site/app/inc/lib/MercadoPagoGateway.php` + cópia manager (retorna null + docblock)
- `site/app/inc/lib/InfinitePayGateway.php` + cópia manager (retorna null + docblock)
- `site/app/inc/controller/webhook_controller.php` (gravar quando disponível)
- `site/tests/PagBankGatewayTest.php` (casos novos)
- `site/tests/WebhookIdempotencyTest.php` OU um teste novo `site/tests/WebhookTransactionNsuTest.php` (integração)

**Out of scope**:
- `migrations/` — a coluna e a UNIQUE já existem (042). Nenhuma migration nova.
- `confirmPayment()`/fluxo InfinitePay — intocado.
- `OrderReconciler.php` — reconciliação por polling não usa transaction_nsu hoje; não estender.
- Qualquer uso de `transaction_nsu` como critério de AUTORIZAÇÃO de pagamento
  para MP/PagBank — é metadado de reconciliação, não portão de segurança.

## Git workflow

- Branch: `advisor/040-transaction-nsu-pagbank`
- Commits em PT-BR, Conventional Commits (`fix:`/`feat:`).
- Não faça push nem abra PR sem instrução do operador.

## Steps

### Step 1: Adicionar `extractTransactionNsu()` à interface

Em `site/app/inc/lib/PixGateway.php`, adicione após `extractPaidAmountCents()`:

```php
/**
 * Extrai o id de transacao (NSU-equivalente) do payload do webhook, ou null
 * quando o PSP nao expoe um id distinto do gateway_charge_id ali.
 *
 * - PagBank: charges[0].id (CHAR_...) — distinto do gateway_charge_id, que
 *   e o id do QR code (QRCO_...). E o id que o PagBank referencia em disputa.
 * - Mercado Pago: null — o gateway_charge_id JA E o payment_id (cobranca
 *   criada via POST /v1/payments), o NSU-equivalente ja esta persistido.
 * - InfinitePay: null — o transaction_nsu vem da reconfirmacao
 *   confirmPayment() (POST /payment_check), nunca do corpo do webhook
 *   (nao assinado, nao confiavel).
 *
 * Metadado de reconciliacao/chargeback, NAO portao de autorizacao: o
 * webhook_controller so grava depois de assinatura + fetchStatus/confirmPayment.
 */
public function extractTransactionNsu(string $rawBody): ?string;
```

Replique byte-idêntico em `manager/app/inc/lib/PixGateway.php`.

**Verify**: `diff site/app/inc/lib/PixGateway.php manager/app/inc/lib/PixGateway.php` → sem saída

### Step 2: Implementar nos 3 adapters (nas 2 cópias cada)

`PagBankGateway.php` — logo após `extractPaidAmountCents()`, mesmo estilo:

```php
public function extractTransactionNsu(string $rawBody): ?string
{
    $payload = json_decode($rawBody, true);

    $id = is_array($payload) ? ($payload['charges'][0]['id'] ?? null) : null;

    return (is_string($id) && $id !== '') ? $id : null;
}
```

`MercadoPagoGateway.php` e `InfinitePayGateway.php` — `return null;` com
docblock de 1–2 linhas apontando para a explicação na interface (MP:
"gateway_charge_id já é o payment_id"; InfinitePay: "NSU vem de
confirmPayment(), não do corpo").

Replique cada arquivo byte-idêntico no `manager/`.

**Verify**: `bin/check-shared-sync.sh` → exit 0
**Verify**: PHPStan site + manager → `[OK] No errors` (a interface obriga os 3 a implementar — se faltar um, o PHPStan/PHP acusa)

### Step 3: Gravar no webhook_controller

Em `site/app/inc/controller/webhook_controller.php`, altere o bloco das linhas
196–207. Depois do `if ($infinitepayTransactionNsu !== null)` existente,
adicione:

```php
// PagBank: charges[0].id (CHAR_...) do webhook ja verificado por assinatura
// + fetchStatus — metadado de reconciliacao/chargeback (o gateway_charge_id
// do PagBank e o id do QR, QRCO_..., que nao e o que o PSP cita em disputa).
// MP/InfinitePay retornam null aqui (ver PixGateway::extractTransactionNsu).
// So grava se a cobranca ainda nao tem NSU — nunca sobrescreve.
if ($infinitepayTransactionNsu === null && empty($charge['transaction_nsu'])) {
    $webhookNsu = $gateway->extractTransactionNsu($rawBody);
    if ($webhookNsu !== null) {
        $chargeUpdateData['transaction_nsu'] = $webhookNsu;
    }
}
```

Não mexa em nada mais do método — em particular no UPDATE condicional
(`status <> 'expirado'`), no commit explícito e no bloco de replay do
InfinitePay.

Nota sobre a UNIQUE: reentrega do mesmo webhook cai no guard de idempotência
(`status === 'pago'`, linha 73) antes de chegar aqui; e o mesmo `CHAR_...`
não pertence a duas cobranças no PSP. Se ainda assim houver violação de
constraint, `save()` lança `RuntimeException` → catch de `processEvent()` →
500 → PSP reentrega → idempotência responde 200. Comportamento aceitável;
não adicione tratamento extra.

**Verify**: PHPStan site → `[OK] No errors`

### Step 4: Testes de unidade do adapter

Em `site/tests/PagBankGatewayTest.php` (siga o padrão dos testes existentes no
arquivo — payload JSON literal, sem rede):

- Payload com `charges[0].id = "CHAR_ABC-123"` → retorna `"CHAR_ABC-123"`
- Payload sem `charges` → null
- Body não-JSON (`"not json"`) → null
- `charges[0].id` vazio (`""`) → null

Se existirem `MercadoPagoGatewayTest.php`/`InfinitePayGatewayTest.php` com
estrutura análoga, adicione 1 caso em cada: qualquer payload → null.

**Verify**: `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit --filter PagBankGateway` → passam

### Step 5: Teste de integração do webhook

Leia `site/tests/WebhookIdempotencyTest.php` primeiro — ele já monta o cenário
completo (gateway + pedido + cobrança + `processEvent()`), inclusive o
`->commit()` explícito que ele documenta. Modele nele um teste (no mesmo
arquivo ou em `WebhookTransactionNsuTest.php` novo) que:

1. Cria pedido + cobrança PagBank pendente (fixtures como o exemplar).
2. Simula o webhook de pagamento com `charges[0].id = "CHAR_TEST_NSU_1"`
   (a assinatura/fetchStatus precisarão do mesmo contorno que o exemplar já
   usa — siga exatamente a técnica dele; se o exemplar só testa InfinitePay e
   não houver como simular PagBank sem rede, teste a camada que dá: chame
   diretamente o trecho novo com um `$chargeUpdateData` montado, ou marque o
   caso como coberto pelos testes de unidade do Step 4 e REPORTE isso).
3. Confere que `pix_charges.transaction_nsu == "CHAR_TEST_NSU_1"` após o processamento.
4. Confere que uma cobrança InfinitePay processada continua com o NSU vindo de
   `confirmPayment()` (regressão: o novo bloco não roda quando
   `$infinitepayTransactionNsu !== null`).

**Verify**: `--filter 'Webhook'` → passam, sem regressão nos casos existentes

### Step 6: Regressão completa

**Verify**: PHPUnit site completo → sem regressão vs baseline.
**Verify**: PHPUnit manager completo → sem regressão.
**Verify**: `bin/check-shared-sync.sh` → exit 0.

## Test plan

Steps 4–5. Exemplares: `PagBankGatewayTest.php` (unidade, payload literal) e
`WebhookIdempotencyTest.php` (integração com `processEvent()`).

## Done criteria

- [ ] `grep -c "extractTransactionNsu" site/app/inc/lib/PixGateway.php site/app/inc/lib/PagBankGateway.php site/app/inc/lib/MercadoPagoGateway.php site/app/inc/lib/InfinitePayGateway.php site/app/inc/controller/webhook_controller.php` → ≥1 em cada
- [ ] `diff` das 4 libs entre site/ e manager/ → idênticas; `bin/check-shared-sync.sh` exit 0
- [ ] PHPStan site + manager `[OK]`
- [ ] PHPUnit site + manager completos sem regressão; casos novos passam
- [ ] Nenhuma migration nova criada (`git status migrations/` limpo)
- [ ] `git status` → nenhum arquivo fora do In scope
- [ ] Linha de status atualizada em `plans/README.md`

## STOP conditions

- O bloco `$chargeUpdateData` não estiver mais nas linhas ~196–207 (drift).
- `extractTransactionNsu` já existir em qualquer adapter.
- A UNIQUE `uq_pix_charge_transaction_nsu` não existir no schema do banco de
  dev (migration 042 não aplicada) — rode
  `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php`
  uma vez; se continuar ausente, STOP.
- O teste de integração exigir tocar `verifyWebhook()`/`fetchStatus()` de
  produção para ficar testável (ex.: injetar flag de teste) — STOP e reporte;
  não enfraqueça código de produção por causa de teste.

## Maintenance notes

- Se um 4º gateway entrar (a doc do `GatewayRouter` prevê isso), a interface
  agora exige decidir o NSU dele explicitamente — é intencional.
- Revisor deve conferir: nenhum uso do novo valor como critério de decisão de
  pagamento; o bloco novo só roda para não-InfinitePay; `empty()` protege
  contra sobrescrita.
- Follow-up deferido: expor `transaction_nsu` na tela de detalhe do pedido no
  manager (hoje ninguém lê a coluna na UI) — só quando houver demanda real de
  disputa.
