# Plan 039: Validar dígito verificador de CPF no checkout (módulo 11)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat d3d3293..HEAD -- site/app/inc/controller/checkout_controller.php site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security (antifraude)
- **Planned at**: commit `d3d3293`, 2026-07-22

## Why this matters

O checkout aceita qualquer sequência de 11 dígitos como CPF (`11111111111`,
`00000000000`, dígitos verificadores errados). CPF estruturalmente inválido é
flag direto nos sistemas antifraude do Mercado Pago e do PagBank — eles
correlacionam CPF inválido com fraude/teste de cartão, e isso conta contra a
reputação da conta do recebedor. O `isBlocked()` e o rate limit protegem
contra reuso/flood, mas não contra CPF inválido chegando ao PSP. Este plano
implementa o algoritmo módulo 11 da Receita Federal ANTES de criar a cobrança
PIX.

## Current state

- `site/app/inc/controller/checkout_controller.php` — controller do checkout
  público. `validateCustomer()` (linhas 484–548) valida os dados do comprador;
  a checagem de CPF hoje é só de comprimento:

```php
// checkout_controller.php:489
$cpf    = preg_replace('/\D/', '', (string)($post['cpf'] ?? ''));
...
// checkout_controller.php:513-516
if (strlen($cpf) !== 11) {
    $_SESSION["messages_app"]["danger"] = ["Informe um CPF válido."];
    return null;
}
```

- `site/app/inc/lib/CommonFunctions.php` — funções globais do framework LEGGO
  (ex.: `sanitize_string()` linha 308, `validate_csrf()` linha 352). Funções
  são declaradas no namespace global, snake_case, com type hints PHP 8.4.
- `manager/app/inc/lib/CommonFunctions.php` — **cópia byte-idêntica**. Regra do
  repo (CLAUDE.md): `app/inc/lib/` e `app/inc/model/` DEVEM ser idênticos entre
  `site/` e `manager/`; `bin/check-shared-sync.sh` roda no pre-commit e bloqueia
  divergência.
- `site/tests/CommonFunctionsTest.php` — testes de funções puras (sem DB),
  exemplar estrutural para o teste do helper.
- `site/tests/CheckoutCustomerBlockTest.php` — exemplar de teste que chama
  `validateCustomer()` (privado) via `ReflectionMethod`, com backup/restore de
  `$_SESSION` no setUp/tearDown. Modele o teste de integração nele.

**Fato importante sobre fixtures**: os testes existentes usam o CPF fixture
`12345678909`, que É válido pelo módulo 11 (CPF de teste clássico). A nova
validação NÃO quebra fixtures existentes. Confirme com o passo de verificação
da suíte completa.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |
| PHPUnit site (Docker) | `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit` | all pass (baseline pré-mudança: rode antes para registrar) |
| PHPUnit manager (Docker) | `docker exec -w /var/www/infinnityimportacao/manager infinnityimportacao php app/inc/lib/vendor/bin/phpunit` | all pass |
| Teste isolado | `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit --filter Cpf` | novos testes passam |

**Atenção**: NÃO use `bin/test.sh` como verificação — ele tem um bug conhecido
(falta `-w` no `docker exec`; o PHPUnit imprime o help e o script parece
verde). Use os comandos `docker exec -w ...` acima.

## Scope

**In scope** (the only files you should modify):
- `site/app/inc/lib/CommonFunctions.php` (novo helper `validate_cpf()`)
- `manager/app/inc/lib/CommonFunctions.php` (cópia byte-idêntica do mesmo helper)
- `site/app/inc/controller/checkout_controller.php` (usar o helper em `validateCustomer()`)
- `site/tests/CommonFunctionsTest.php` (casos do helper)
- `site/tests/CheckoutCpfValidationTest.php` (novo — integração via ReflectionMethod)
- `manager/tests/CommonFunctionsTest.php` (espelhar os casos do helper, se o arquivo existir no manager; se não existir, criar seguindo o padrão do site)
- `site/tests/CheckoutCustomerBlockTest.php` — **APENAS** a linha 57
  (`'cpf' => (string) mt_rand(10000000000, 99999999999),` dentro de
  `validPost()`) e a linha 94 (mesmo padrão `mt_rand` dentro de
  `testBlockedByCpfPreventsCheckout` ou equivalente — confira o nome exato no
  arquivo). Ver "Addendum — Step 3.5" abaixo. Não toque em mais nada nesse
  arquivo.

**Out of scope** (do NOT touch):
- `manager/app/inc/controller/customers_controller.php` e qualquer validação de
  CPF no manager (bloqueio de clientes) — comportamento atual preservado.
- `site/app/inc/lib/*Gateway*.php`, `webhook_controller.php` — nada muda no
  fluxo de pagamento.
- Views (`checkout.php`) — a mensagem de erro existente já cobre o caso.
- `blocked_customers` / qualquer outra fixture de teste — só as duas linhas
  `mt_rand` de CPF em `CheckoutCustomerBlockTest.php` listadas acima.

## Addendum — Step 3.5: corrigir fixture de CPF em `CheckoutCustomerBlockTest.php`

**Adicionado após rodada de execução + revisão** (branch
`advisor/039-cpf-digito-verificador`, commit `6a5cfb8`): o código e os testes
do helper foram implementados corretamente e passaram em todas as verificações
estáticas (PHPStan, `check-shared-sync.sh`). O revisor rodou a suíte PHPUnit
completa (via overlay temporário sobre a main tree, revertido depois — não
sobrou nenhuma alteração fora do branch) e confirmou a exata STOP condition
que este plano previa: `site/tests/CheckoutCustomerBlockTest.php` gera CPF de
teste com `(string) mt_rand(10000000000, 99999999999)` — uma sequência de 11
dígitos aleatória, que só por acaso (~1% de chance) passa no módulo 11. Com
`validate_cpf()` agora aplicado em `validateCustomer()`, isso quebrou 3 testes:
`testNonBlockedCustomerPassesValidation`,
`testEmptyBlockedIdentifiersDoNotCrossMatch`, e um terceiro que depende do
mesmo `validPost()` (confirme o nome exato ao rodar a suíte — verifique a
saída de falhas). Baseline antes desta mudança: site 274/274 sem falha.

**Fix (revisado após 1ª tentativa)**: a 1ª tentativa trocou AMBAS as
ocorrências de `mt_rand` pelo MESMO CPF fixo (`'52998224725'`) — isso quebrou
2 testes novos: `testBlockedByCpfIsRejected` agora bloqueia esse CPF em
`blocked_customers`, e como `testNonBlockedCustomerPassesValidation` /
`testEmptyBlockedIdentifiersDoNotCrossMatch` usam o MESMO CPF via
`validPost()` default, eles passam a ser tratados como bloqueados também
(mesmo com `DBTestCase` fazendo rollback por teste, dentro da MESMA suíte os
dados de bloqueio de um teste não podem coincidir com o CPF "não bloqueado"
default de outro). As duas ocorrências precisam de CPFs válidos DIFERENTES:

- linha 57 (`validPost()`, default do "cliente qualquer não bloqueado"):
  mantenha `'52998224725'` (já corrigido, não mexer de novo).
- linha ~94 (dentro de `testBlockedByCpfIsRejected`, variável local `$cpf`
  que é bloqueada de propósito): troque para `'11144477735'` (outro CPF
  válido pelo módulo 11, verificado — ver comando abaixo). Este CPF deve ser
  usado SÓ nesse teste, nunca no `validPost()` default.

**Verify** (executor: NO docker/docker-compose commands — see below):
- `php -r 'function validate_cpf(string $cpf): bool { $cpf = preg_replace("/\D/", "", $cpf) ?? ""; if (strlen($cpf) !== 11 || preg_match("/^(\d)\1{10}$/", $cpf)) return false; for ($t = 9; $t < 11; $t++) { $sum = 0; for ($i = 0; $i < $t; $i++) $sum += (int)$cpf[$i] * (($t + 1) - $i); $digit = ((10 * $sum) % 11) % 10; if ((int)$cpf[$t] !== $digit) return false; } return true; } var_dump(validate_cpf("11144477735"));'` → `bool(true)` (confirma que o novo CPF é válido antes de usar)
- `grep -n "52998224725\|11144477735\|mt_rand(10000000000, 99999999999)" site/tests/CheckoutCustomerBlockTest.php` → deve mostrar `52998224725` só na linha do `validPost()` e `11144477735` só na linha do `testBlockedByCpfIsRejected`; sem nenhum `mt_rand`
- `php -l site/tests/CheckoutCustomerBlockTest.php` → sem erro de sintaxe
- PHPUnit fica por conta do revisor (ver nota abaixo). Esperado ao final:
  suíte completa do site → 289/289 sem falha (274 baseline + 12 novos deste
  plano + 3 que agora voltam a passar).

**NUNCA rode `docker compose up` (com ou sem nome de serviço) a partir de um
worktree.** `docker-compose.yml` usa `container_name` fixo para
`mysql`/`redis`/`kafka`/`infinnityimportacao` — são singletons **globais ao
host**, compartilhados por todos os worktrees. Mesmo nomeando só o serviço
`infinnityimportacao`, o Compose recria a árvore de `depends_on` inteira
(mysql/redis/kafka) e reaponta os binds para o `_data/` (e `.env`, se
existir) DESTE worktree — que normalmente está vazio/sem credenciais. Isso já
aconteceu duas vezes na execução deste plano: derrubou o `mysql` que outro
worktree/revisor estava usando, e na segunda vez o container subiu com
credenciais em branco e schema vazio (recuperável só porque os arquivos de
dados do mysql-data da main tree não foram apagados, só desmontados).
Qualquer verificação que precise do container rodando deve ser feita pelo
revisor a partir da main tree (que já tem o container certo no ar), nunca
pelo executor a partir do worktree.

## Git workflow

- Branch: `advisor/039-cpf-digito-verificador`
- Commits em PT-BR, Conventional Commits (ex. do repo: `fix: nome de item dinamico no payload ao gateway`)
- Não faça push nem abra PR sem instrução do operador.

## Steps

### Step 1: Criar `validate_cpf()` em `CommonFunctions.php` (site)

Adicione ao final de `site/app/inc/lib/CommonFunctions.php` (após a última
função), seguindo o estilo das funções vizinhas (docblock em PT-BR, snake_case,
type hints):

```php
/**
 * Valida CPF pelo algoritmo modulo 11 da Receita Federal.
 * Aceita entrada com ou sem mascara (so os digitos sao considerados).
 * Rejeita comprimento != 11 e sequencias de digitos repetidos
 * (111.111.111-11 passa no modulo 11 aritmetico, mas e invalido).
 */
function validate_cpf(string $cpf): bool
{
    $cpf = preg_replace('/\D/', '', $cpf) ?? '';

    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += (int)$cpf[$i] * (($t + 1) - $i);
        }
        $digit = ((10 * $sum) % 11) % 10;
        if ((int)$cpf[$t] !== $digit) {
            return false;
        }
    }

    return true;
}
```

**Verify**: `php -l site/app/inc/lib/CommonFunctions.php` → `No syntax errors`

### Step 2: Replicar byte-idêntico no manager

Copie a MESMA função (mesmos bytes) para
`manager/app/inc/lib/CommonFunctions.php`, na mesma posição relativa (final do
arquivo, após a última função).

**Verify**: `bin/check-shared-sync.sh` → exit 0
**Verify**: `diff site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php` → sem saída

### Step 3: Usar o helper em `validateCustomer()`

Em `site/app/inc/controller/checkout_controller.php`, substitua o bloco das
linhas 513–516:

```php
if (strlen($cpf) !== 11) {
    $_SESSION["messages_app"]["danger"] = ["Informe um CPF válido."];
    return null;
}
```

por:

```php
if (!validate_cpf($cpf)) {
    $_SESSION["messages_app"]["danger"] = ["Informe um CPF válido."];
    return null;
}
```

Não mude a mensagem nem a posição do bloco (antes do CEP, depois do telefone).
`$cpf` já chega só-dígitos (linha 489); o helper re-normaliza por robustez.

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`

### Step 4: Testes do helper (unitários, sem DB)

Em `site/tests/CommonFunctionsTest.php`, adicione casos (siga o estilo dos
testes existentes no arquivo — asserts diretos, sem DB):

- CPF válido sem máscara: `52998224725` → true
- CPF válido com máscara: `529.982.247-25` → true
- Fixture do repo: `12345678909` → true (garante que fixtures não quebram)
- Dígito verificador errado: `52998224724` → false
- Sequência repetida: `11111111111` → false; `00000000000` → false
- Comprimento errado: `1234567890` (10) → false; `123456789012` (12) → false
- Vazio: `''` → false

Espelhe os mesmos casos em `manager/tests/CommonFunctionsTest.php` (crie o
arquivo se não existir, seguindo o boilerplate do teste do site).

**Verify**: `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit --filter CommonFunctions` → todos passam

### Step 5: Teste de integração de `validateCustomer()`

Crie `site/tests/CheckoutCpfValidationTest.php` modelado em
`site/tests/CheckoutCustomerBlockTest.php` (mesmo backup/restore de
`$_SESSION`, mesma chamada via `ReflectionMethod` a
`checkout_controller::validateCustomer()`). Como `validateCustomer()` chama
`isBlocked()` (toca DB), estenda `DBTestCase` como o exemplar faz. Casos:

- POST completo e válido com CPF `52998224725` → retorna array (não-null), `cpf === '52998224725'`
- Mesmo POST com CPF `11111111111` → retorna null, `$_SESSION["messages_app"]["danger"]` contém "Informe um CPF válido."
- Mesmo POST com CPF `52998224724` (DV errado) → retorna null

Monte o POST válido com todos os campos obrigatórios (name, mail, phone 11
dígitos, zip 8 dígitos, street, number, district, city, uf válida como `SP`) —
copie a estrutura do exemplar.

**Verify**: `docker exec -w /var/www/infinnityimportacao/site infinnityimportacao php app/inc/lib/vendor/bin/phpunit --filter CheckoutCpfValidation` → 3 casos passam

### Step 6: Suíte completa (regressão)

**Verify**: PHPUnit site completo → mesmo nº de falhas da baseline do Step 0
(nenhuma regressão; se `CheckoutPaymentChargeTest` falhar, confira se a falha
já existia na baseline antes de atribuí-la a este plano).
**Verify**: PHPUnit manager completo → sem regressão.
**Verify**: PHPStan site + manager → `[OK]` nos dois.

## Test plan

Consolidado nos Steps 4–5: 7+ casos unitários do helper (nas duas suítes) + 3
casos de integração de `validateCustomer()`. Exemplares:
`CommonFunctionsTest.php` (unitário) e `CheckoutCustomerBlockTest.php`
(ReflectionMethod + sessão).

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -n "validate_cpf" site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php site/app/inc/controller/checkout_controller.php` → 3 arquivos com match
- [ ] `grep -n "strlen(\$cpf) !== 11" site/app/inc/controller/checkout_controller.php` → sem match
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] PHPStan site + manager → `[OK] No errors`
- [ ] PHPUnit site + manager completos → sem regressão vs baseline
- [ ] `git status` → nenhum arquivo fora da lista In scope
- [ ] Linha de status atualizada em `plans/README.md`

## STOP conditions

Stop and report back (do not improvise) if:

- O bloco `strlen($cpf) !== 11` não estiver mais nas linhas ~513–516 (drift).
- `validate_cpf` já existir em qualquer uma das cópias de `CommonFunctions.php`.
- Fixtures de teste existentes quebrarem com a nova validação (indicaria CPF
  fixture inválido em algum teste — não "conserte" fixtures de outros testes
  sem reportar).
- `bin/check-shared-sync.sh` falhar depois do Step 2 por arquivos que você NÃO
  tocou (drift pré-existente entre as cópias).

## Maintenance notes

- Se um dia o manager ganhar cadastro/edição de cliente com CPF, usar o MESMO
  `validate_cpf()` — nunca duplicar o algoritmo.
- Revisor deve conferir: helper idêntico nas duas cópias; mensagem de erro
  inalterada; nenhuma mudança de comportamento para CPF válido.
- Follow-up deferido: validação client-side (JS) no form do checkout para UX —
  fora deste plano (backend é a defesa real).
