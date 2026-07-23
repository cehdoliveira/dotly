# Plan 012: Alinhar o visual do manager ao padrão do site (paleta índigo, fontes, estilo)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4ad3e67..HEAD -- manager/public_html/assets/css manager/public_html/ui/common/head.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3 (visual; independente, sem migration, baixo risco)
- **Effort**: S/M
- **Risk**: LOW
- **Depends on**: none. Pode rodar a qualquer momento.
- **Category**: dx / docs (design system)
- **Planned at**: commit `4ad3e67`, 2026-07-16

## Why this matters

O `manager/` e o `site/` hoje têm identidades visuais divergentes: o site usa
paleta **índigo** (`#2e2b6e`) sobre fundo claro, fonte **Plus Jakarta Sans** +
**DM Mono**, tema único claro; o manager usa **azul** (`#2563eb`), fonte **Inter**,
tema **dark por padrão**. Os arquivos declaram os mesmos NOMES de tokens de propósito
(o site diz: *"Tokens canônicos do whitelabel — mesmos NOMES no manager (valores
podem diferir)"*), o que torna o alinhamento uma troca de **valores**, não de
estrutura. Este plano alinha a paleta, as fontes e o estilo do manager ao padrão do
site. **NÃO redesenha o site** — o site é a referência.

## Fatos arquiteturais (LEGGO)

- Dois ambientes, um codebase. CSS e views são **por-ambiente** — mudar
  `manager/public_html/assets/css/main.css` NÃO afeta o site, e **não** dispara o
  guard de shared-sync (esse guard só cobre `app/inc/lib` e `app/inc/model`, não CSS).
- O manager já tem toggle de tema (`[data-theme]`) com script anti-flash no
  `head.php`. O site força tema claro.

## Current state

### Fontes (Google Fonts em `head.php`)
- **Site** `site/public_html/ui/common/head.php`:
  ```html
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
  ```
  `<meta name="color-scheme" content="light">`, `theme-color #f7f7fb`, `<html lang="pt-BR">` (sem `data-theme` — claro fixo).
- **Manager** `manager/public_html/ui/common/head.php`:
  ```html
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  ```
  `<html lang="pt-BR" data-theme="dark">`, `color-scheme light dark`, `theme-color #0a0e14`, + script anti-flash que lê `localStorage.theme` (default dark).

### Tokens de cor
- **Site** `site/public_html/assets/css/main.css` `:root` (tema único claro):
  ```
  --bg:#f7f7fb; --surface:#ffffff; --surface-2:#eeedf8;
  --accent:#2e2b6e; --accent-hover:#3d3a8c; --accent-dim:#eeedf8; --accent-glow:rgba(46,43,110,0.18);
  --secondary:#5855b0; --text:#1a1830; --text-muted:#7a7890; --text-heading:#2e2b6e;
  --border:#e2e1f0; --border-accent:#c8c6e8;
  --success:#128c7e; --warning:#e67e00; --error:#cc3333;
  --focus-ring:rgba(88,85,176,0.3);
  --font-mono:'DM Mono',ui-monospace,monospace;
  --bs-body-color:#1a1830; --bs-link-color:#2e2b6e; --bs-link-color-rgb:46,43,110; ...
  ```
  Corpo: `font-family:'Plus Jakarta Sans',system-ui,...`; headings `font-weight:800; letter-spacing:-0.02em`.
- **Manager** `manager/public_html/assets/css/main.css`:
  - `:root` (dark default): `--accent:#2563eb; --bg:#0a0a0b; --surface:#111113; --surface-2:#18181b; --text:#d4d8e0; --text-muted:#60657a; --border:#1e1e24; --navbar-bg:#080808; --footer-bg:#080808; --footer-text:...; --focus-ring:rgba(37,99,235,0.28);`
  - `[data-theme="light"]`: `--accent:#2563eb; --bg:#f1f5f9; --surface:#ffffff; --surface-2:#f8fafc; --text:#1e293b; --text-muted:#64748b; --border:#e2e8f0; ...`
  - `font-family:"Inter",...` em ~8 lugares (linhas 79, 96, 111, 145, 222, 243…).
  - Tem tokens que o site **não** tem: `--navbar-bg`, `--footer-bg`, `--footer-text`.
  - **Falta** tokens que o site tem: `--secondary`, `--text-heading`, `--accent-dim`,
    `--border-accent`, `--success`, `--warning`, `--error`, `--font-mono`.
- Há também `manager/public_html/assets/css/dashboard.css` (carregado só no manager).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Guard shared-sync | `bin/check-shared-sync.sh` | exit 0 (CSS não é shared; deve seguir verde) |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` (não deve mudar — é CSS/HTML) |
| Ver Inter restante | `grep -rn "Inter" manager/public_html/` | 0 após o Step |
| Ver azul restante | `grep -rni "2563eb\|#2563" manager/public_html/assets/css/` | 0 após o Step |

## Scope

**In scope**:
- `manager/public_html/ui/common/head.php` (trocar Inter → Jakarta+DM Mono; ajustar `data-theme`/`theme-color`/`color-scheme`)
- `manager/public_html/assets/css/main.css` (remapear tokens para os valores do site; trocar `font-family`)
- `manager/public_html/assets/css/dashboard.css` (trocar `font-family`; ajustar cores hardcoded para tokens)
- Qualquer outro `manager/public_html/**/*.css` que hardcode `#2563eb`/`Inter` (varra com grep)

**Out of scope**:
- **TODO o `site/`** — é a referência, não muda nada.
- `app/inc/lib` e `app/inc/model` (não são CSS; guard bloquearia divergência de qualquer jeito).
- Lógica de controller/rotas/views PHP (exceto o `head.php` do manager, que é view).
- Não mude a estrutura de layout do manager (sidebar, cards) — só paleta/fonte/estilo.
  Reestruturar a tela é outro escopo.

## Git workflow

- Branch: `advisor/012-layout-manager`
- Commits PT-BR Conventional Commits (`feat:`/`chore:`). Sem push/PR sem ordem.

## Steps

### Step 1: Decisão de tema (confirme antes de codar)

O site é **claro único**; o manager tem toggle dark/light. Duas opções:

- **[recomendada — opção A]** Manter o toggle, mas: (1) tornar **light o padrão**
  (o site é claro), (2) remapear o `[data-theme="light"]` para a paleta **índigo do
  site** (valores acima), e (3) retingir o `:root` dark para um "índigo escuro"
  coerente (mesmo `--accent` família índigo, fundos escuros) em vez do azul/preto
  atual. Preserva a feature de toggle e alinha o visual.
- **opção B** Remover o toggle e forçar claro como no site (mais simples, mas
  descarta uma feature existente).

Siga a **opção A** salvo instrução em contrário. Se optar por B, isso remove o
script anti-flash e o botão `.theme-toggle-btn` — reporte antes.

**Verify**: decisão registrada no commit message; segue para Step 2.

### Step 2: Trocar as fontes no `head.php` do manager

Em `manager/public_html/ui/common/head.php`:
- Substitua o `<link>` da fonte Inter pelo mesmo do site (Plus Jakarta Sans + DM Mono):
  ```html
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
  ```
- Opção A: default light → mude `data-theme="dark"` para `data-theme="light"` no
  `<html>` e ajuste o fallback do script anti-flash para `'light'`; `theme-color`
  claro (`#f7f7fb`).

**Verify**: `grep -rn "Inter" manager/public_html/ui/common/head.php` → 0.

### Step 3: Remapear tokens de cor em `main.css` do manager

Em `manager/public_html/assets/css/main.css`:
- No bloco de tema **claro** (que passa a ser o padrão), use os valores índigo do
  site (lista em "Current state"). Acrescente os tokens que faltam
  (`--secondary`, `--text-heading`, `--accent-dim`, `--border-accent`, `--success`,
  `--warning`, `--error`, `--font-mono`) copiando os valores do site.
- Mantenha os tokens exclusivos do manager (`--navbar-bg`, `--footer-bg`,
  `--footer-text`), mas retingidos para a paleta clara (ex.: `--navbar-bg:#ffffff`,
  `--footer-bg:#f8fafc` — coerentes com o site).
- Se seguir opção A com dark retintado: no `:root`/`[data-theme="dark"]`, troque o
  `--accent` azul por índigo (`#5855b0`/`#3d3a8c` família) e os fundos preto-azulado
  por um escuro neutro — sem reintroduzir `#2563eb`.
- Troque **todas** as ocorrências de `font-family:"Inter",...` por
  `font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;`.
- Para dados tabulares (preços, códigos) use `var(--font-mono)` como no site, se
  aplicável a colunas numéricas — opcional, sem exagero.
- Headings: alinhe `font-weight:800; letter-spacing:-0.02em` (padrão do site).

**Verify**:
`grep -rni "2563eb" manager/public_html/assets/css/main.css` → 0;
`grep -rn "Inter" manager/public_html/assets/css/main.css` → 0.

### Step 4: `dashboard.css` e demais CSS do manager

Varra `grep -rni "inter\|2563eb\|#2563\|#0a0a0b\|#111113" manager/public_html/assets/css/`
e substitua fontes/cores hardcoded pelos tokens/valores do site. Não introduza
cores novas fora da paleta índigo.

**Verify**: `grep -rni "Inter\|2563eb" manager/public_html/` → 0 ocorrências.

### Step 5: Conferência visual (manual)

Suba o stack e navegue o manager logado em todas as telas
(`/`, `/usuarios`, `/produtos`, `/pedidos`, `/gateways`, `/perfis`, `/emails`,
login): confira que fundo, acentos e fontes batem com o padrão índigo/Jakarta do
site; nenhum resquício azul/Inter; contraste de texto legível; toggle de tema (se
mantido) funciona sem flash.

**Verify**: checklist visual acima OK; comparar lado a lado com uma tela do site.

## Test plan

Não há teste automatizado de CSS neste repo. A verificação é:
- Os greps de "azul/Inter zerados" (Done criteria).
- Conferência visual manual (Step 5) — registre no PR quais telas foram vistas.
- `bin/check-shared-sync.sh` e PHPStan seguem verdes (nada de shared/PHP mudou de
  forma relevante).

## Done criteria

- [ ] `grep -rni "Inter" manager/public_html/` → 0
- [ ] `grep -rni "2563eb" manager/public_html/` → 0
- [ ] `head.php` do manager carrega Plus Jakarta Sans + DM Mono
- [ ] `main.css` do manager tem os tokens que faltavam (`--secondary`, `--text-heading`, `--font-mono`, `--success`, `--warning`, `--error`, `--accent-dim`, `--border-accent`) com os valores do site
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` → `[OK] No errors`
- [ ] Nenhum arquivo em `site/` modificado (`git status`)
- [ ] Conferência visual das telas do manager registrada no PR
- [ ] `plans/README.md` atualizado

## STOP conditions

- Os trechos de token/fonte em "Current state" não baterem (drift no CSS).
- Descobrir que uma tela do manager depende estruturalmente de cor hardcoded (ex.:
  ícone SVG inline com fill azul) — reporte a lista antes de mudar em massa.
- A opção de tema escolhida quebrar o contraste/legibilidade em alguma tela —
  pare e reporte.

## Maintenance notes

- Os **nomes** dos tokens já são o contrato whitelabel comum entre os dois
  ambientes; este plano só alinha **valores** do manager. Uma futura marca troca os
  valores em ambos os `main.css`.
- O `bin/init-whitelabel.sh` gera kernels por marca mas **não** toca CSS — a paleta
  continua manual por ambiente. Se o whitelabel evoluir para tokens compartilhados,
  este é o ponto a revisitar.
- Revisor deve conferir que nada do `site/` foi tocado e que a paleta índigo ficou
  consistente entre login e telas internas do manager.
