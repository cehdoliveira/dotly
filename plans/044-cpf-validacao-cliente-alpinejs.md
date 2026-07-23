# Plan 044: Validar dígito verificador de CPF no cliente (AlpineJS)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: this plan was written against `main` HEAD
> `d3d3293` (plan 039's PHP changes — `validate_cpf()` in
> `CommonFunctions.php` — are NOT merged into `main` yet; they only exist on
> the unmerged branch `advisor/039-cpf-digito-verificador`, commit `aefb7de`.
> That's fine: this plan is self-contained and the algorithm to port is
> fully inlined in Step 1 below, no need to read the PHP source). Run
> `git diff --stat d3d3293..HEAD -- site/public_html/assets/js/alpine/checkoutController.js`
> — if it shows any output, the in-scope file changed since this plan was
> written; compare the "Current state" excerpt below against the live file
> before proceeding, and treat a mismatch as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: 039 (merged into this branch's history — `validate_cpf()` already exists server-side)
- **Category**: UX (evita bounce de página)
- **Planned at**: commit `aefb7de` (branch `advisor/039-cpf-digito-verificador`), 2026-07-22

## Why this matters

Plano 039 adicionou a checagem de dígito verificador de CPF (módulo 11) no
servidor (`validate_cpf()` em `CommonFunctions.php`, usado em
`checkout_controller::validateCustomer()`). O formulário de checkout já tem
validação client-side via AlpineJS (`checkoutController.js`) que bloqueia o
submit e preserva os dados digitados quando algo está errado — **mas essa
validação só confere comprimento do CPF (11 dígitos), não o dígito
verificador**:

```js
// checkoutController.js:146 (dentro de validate())
this.errors.cpf = digits('cpf').length === 11 ? '' : 'Informe os 11 dígitos do CPF.';
```

Resultado: um CPF com 11 dígitos mas DV errado (ex.: `11111111111`, ou um
dígito trocado) passa pela validação do cliente, o formulário faz o submit
nativo normal (não é AJAX — decisão de arquitetura do projeto, ver
`CLAUDE.md`), e só o servidor rejeita — causando reload de página completo
("bounce") em vez do erro inline sem reload que os outros campos têm. Os
dados não se perdem (`old()` repopula), mas a UX regride pontualmente pra
esse campo. O próprio plano 039 já registrou isso como pendência deliberada:

> Follow-up deferido: validação client-side (JS) no form do checkout para UX
> — fora deste plano (backend é a defesa real).

Este plano fecha essa lacuna. O comentário no topo do arquivo já documenta
que esse exato padrão de bug já aconteceu antes com e-mail (regex do cliente
mais frouxa que o `FILTER_VALIDATE_EMAIL` do servidor, causando bounce) e foi
corrigido apertando a validação do cliente para bater com o servidor — este
plano faz o mesmo para CPF.

## Current state

- `site/public_html/assets/js/alpine/checkoutController.js` — controller
  AlpineJS do formulário de checkout. Função `validate()` (linha ~137) roda
  100% no cliente antes do submit nativo (comentário no topo do arquivo
  explica o porquê: "a validacao roda 100% no cliente ANTES de enviar...
  o servidor revalida em finalize() mesmo assim — defesa em profundidade").
  A linha do CPF (única linha a mudar):

```js
// checkoutController.js:146
this.errors.cpf = digits('cpf').length === 11 ? '' : 'Informe os 11 dígitos do CPF.';
```

- `site/app/inc/lib/CommonFunctions.php` — tem `validate_cpf()` (módulo 11,
  adicionado pelo plano 039, linhas ~869-896). É a fonte de verdade do
  algoritmo — este plano faz uma PORTA do mesmo algoritmo pra JS, não muda o
  PHP.
- Não existe framework de teste JS no repo (`find site -iname "*.test.js"`
  não retorna nada) — verificação é sintática (`node --check`) e funcional
  manual (não automatizada).
- Padrão de mensagem de erro já usado no arquivo: strings curtas em
  português, ex. `'Informe um e-mail válido.'`, `'Informe um CEP válido.'`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Sintaxe JS | `node --check site/public_html/assets/js/alpine/checkoutController.js` | sem saída, exit 0 |
| PHPStan site (não deve mudar nada, é só sanity check de que nada PHP foi tocado) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| Grep de escopo | `git diff --stat` (no fim) | só o arquivo `checkoutController.js` |

Não há PHPUnit relevante aqui — é um arquivo JS puro, sem teste automatizado
no repo. Não crie um framework de teste JS novo — fora de escopo.

## Scope

**In scope** (o único arquivo que você deve modificar):
- `site/public_html/assets/js/alpine/checkoutController.js`

**Out of scope** (NÃO toque):
- `site/app/inc/lib/CommonFunctions.php` / `manager/app/inc/lib/CommonFunctions.php` — o algoritmo do servidor não muda, só é portado (duplicado) pro JS.
- `site/app/inc/controller/checkout_controller.php` — validação server-side já está correta (plano 039).
- `site/public_html/ui/page/checkout.php` — a view/inputs não mudam, só a lógica de validação dentro do controller Alpine.
- Qualquer outro campo do formulário (nome, e-mail, telefone, CEP, endereço) — só a linha do CPF.
- `manager/` — este componente Alpine só existe no site público, não há cópia no manager (`checkoutController.js` NÃO faz parte da regra de sincronismo `app/inc/lib`/`app/inc/model` do CLAUDE.md — é um asset de front-end do site, sem par no manager).

## Git workflow

- Branch: `advisor/044-cpf-validacao-cliente-alpinejs`
- Commits em PT-BR, Conventional Commits (ex. do repo: `fix: nome de item dinamico no payload ao gateway`)
- Não faça push nem abra PR sem instrução do operador.

## Steps

### Step 1: Portar `validate_cpf()` (módulo 11) pra JS

Adicione uma função auxiliar dentro do IIFE/closure do arquivo (fora do
`Alpine.data(...)`, como uma função de módulo comum, já que só é usada
internamente — siga o padrão de `onlyDigits` que já é um método do objeto
Alpine; aqui, por ser lógica pura sem acesso a `this`, prefira uma função
solta no escopo do arquivo, ANTES de `document.addEventListener('alpine:init', ...)`):

```js
/**
 * Porta em JS do validate_cpf() de CommonFunctions.php (modulo 11 da
 * Receita Federal). Mantem os dois algoritmos em sincronia manual — se o
 * PHP mudar, atualize aqui tambem.
 */
function validateCpfChecksum(cpf) {
    const digits = cpf.replace(/\D/g, '');

    if (digits.length !== 11 || /^(\d)\1{10}$/.test(digits)) {
        return false;
    }

    for (let t = 9; t < 11; t++) {
        let sum = 0;
        for (let i = 0; i < t; i++) {
            sum += parseInt(digits[i], 10) * ((t + 1) - i);
        }
        const digit = ((10 * sum) % 11) % 10;
        if (parseInt(digits[t], 10) !== digit) {
            return false;
        }
    }

    return true;
}
```

Note a diferença deliberada de tipo em relação ao PHP: `validate_cpf()` em
PHP recebe `string $cpf` já podendo ter máscara (remove com
`preg_replace('/\D/', '', $cpf)`); aqui é igual — `cpf.replace(/\D/g, '')` no
início cobre o mesmo caso (o campo já vem mascarado do `maskCpf()`, mas a
função deve funcionar com ou sem máscara, igual ao PHP).

**Verify**: `node --check site/public_html/assets/js/alpine/checkoutController.js` → sem erro de sintaxe

### Step 2: Usar a nova função em `validate()`

Troque a linha 146:

```js
this.errors.cpf = digits('cpf').length === 11 ? '' : 'Informe os 11 dígitos do CPF.';
```

por:

```js
this.errors.cpf = validateCpfChecksum(digits('cpf')) ? '' : 'Informe um CPF válido.';
```

Note a mudança de mensagem: `'Informe os 11 dígitos do CPF.'` →
`'Informe um CPF válido.'` — isso é INTENCIONAL, pra bater com a mensagem
que o servidor já usa em `checkout_controller.php:514`
(`"Informe um CPF válido."`), mesmo padrão que a mensagem de e-mail já segue
(cliente e servidor com a mesma frase). Não invente uma mensagem diferente.

`digits('cpf')` já existe como helper dentro de `validate()`
(`const digits = (ref) => this.onlyDigits(this.$refs[ref].value);`) — não
precisa recriar, só reusar a chamada que já existe na função.

**Verify**: `node --check site/public_html/assets/js/alpine/checkoutController.js` → sem erro de sintaxe
**Verify**: `grep -n "Informe os 11 dígitos do CPF" site/public_html/assets/js/alpine/checkoutController.js` → sem match (mensagem antiga não deve sobrar em lugar nenhum)

### Step 3: Verificação funcional manual (sem framework de teste)

Não existe test runner JS no repo — não crie um. Em vez disso, documente no
commit/relatório final que você verificou manualmente com os mesmos casos do
`CommonFunctionsTest.php` do plano 039, usando `node -e`:

```bash
node -e "
$(sed -n '/^function validateCpfChecksum/,/^}/p' site/public_html/assets/js/alpine/checkoutController.js)
const cases = [
  ['52998224725', true],
  ['529.982.247-25', true],
  ['12345678909', true],
  ['52998224724', false],
  ['11111111111', false],
  ['00000000000', false],
  ['1234567890', false],
  ['123456789012', false],
  ['', false],
];
let ok = true;
for (const [cpf, expected] of cases) {
  const got = validateCpfChecksum(cpf);
  if (got !== expected) { ok = false; console.log('FAIL', cpf, 'expected', expected, 'got', got); }
}
console.log(ok ? 'ALL PASS' : 'FAILURES ABOVE');
"
```

**Verify**: saída `ALL PASS`

### Step 4: Sanity check — nada PHP foi tocado

**Verify**: `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors` (deve ser idêntico ao estado antes deste plano — só confirma que nada em PHP foi mexido sem querer)
**Verify**: `git diff --stat` → só `site/public_html/assets/js/alpine/checkoutController.js`

## Test plan

Sem framework de teste JS no repo (confirmado na Recon) — verificação é:
1. Sintática (`node --check`).
2. Funcional via `node -e` com os mesmos 9 casos do
   `CommonFunctionsTest.php` do plano 039 (Step 3 acima) — garante que a
   porta JS do algoritmo concorda com a implementação PHP já testada.
3. Sanity check de que PHPStan continua limpo (nada PHP foi tocado).

Não é necessário testar no browser real neste plano — a lógica é pura
(sem DOM além de ler `$refs[ref].value`, já coberto pelos outros campos do
mesmo arquivo) e o `node -e` já prova a equivalência com o PHP.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -n "function validateCpfChecksum" site/public_html/assets/js/alpine/checkoutController.js` → 1 match
- [ ] `grep -n "Informe os 11 dígitos do CPF" site/public_html/assets/js/alpine/checkoutController.js` → sem match
- [ ] `grep -n "validateCpfChecksum(digits('cpf'))" site/public_html/assets/js/alpine/checkoutController.js` → 1 match, dentro de `validate()`
- [ ] `node --check site/public_html/assets/js/alpine/checkoutController.js` → exit 0
- [ ] `node -e` do Step 3 → `ALL PASS`
- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] `git status` → nenhum arquivo fora de `checkoutController.js`
- [ ] Linha de status atualizada em `plans/README.md`

## STOP conditions

Stop and report back (do not improvise) if:

- A linha 146 (checagem de `digits('cpf').length === 11`) não estiver mais
  onde o plano descreve (drift — releia `validate()` inteira antes de
  continuar).
- `validateCpfChecksum` já existir no arquivo.
- Existir algum framework de teste JS configurado no repo que a Recon deste
  plano não encontrou (ex.: `package.json` com `jest`/`vitest` na raiz do
  `site/`) — nesse caso, use-o em vez do `node -e` manual, e reporte a
  mudança de abordagem.

## Maintenance notes

- Os dois algoritmos (`validate_cpf()` em PHP, `validateCpfChecksum()` em
  JS) são cópias intencionalmente duplicadas — não há bundler/build step
  neste projeto pra compartilhar código entre PHP e JS. Se o algoritmo do
  módulo 11 mudar um dia (não deveria — é uma regra fixa da Receita
  Federal), atualizar os dois.
- O servidor continua sendo a defesa real (`checkout_controller.php`) — esta
  mudança é só UX, não segurança. Não remova nem enfraqueça a validação
  server-side do plano 039.
- Revisor deve conferir: mensagem de erro idêntica à do servidor; nenhum
  outro campo do formulário foi tocado; PHPStan segue limpo (nada PHP
  mudou).
