# Plan 013: Corrigir fallback de tema hardcoded em `main.js` que anula o default light do manager

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 9a92892..HEAD -- manager/public_html/assets/js/main.js`
> If this file changed since this plan was written, compare the "Current
> state" excerpt against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P3 (visual/DX; pequeno, sem migration, baixo risco)
- **Effort**: S
- **Risk**: LOW
- **Depends on**: [012-unificacao-layout-manager.md](012-unificacao-layout-manager.md) (deve estar mergeado/aplicado antes — este plano assume que `head.php` já tem `data-theme="light"` como default e fallback do anti-flash script ajustado para light. Sem 012, este plano ainda é correto isoladamente, mas o efeito prático de "light por padrão" só se completa com os dois juntos.)
- **Category**: bug
- **Planned at**: commit `9a92892`, 2026-07-16

## Why this matters

O plano 012 mudou o default de tema do manager para **light** (o padrão do
`site/`), ajustando o script anti-flash em `head.php` para não escurecer a
tela antes do CSS carregar. Só que existe um **segundo** ponto que decide o
tema, em `manager/public_html/assets/js/main.js`, que roda depois, no evento
`DOMContentLoaded`, e tem seu próprio fallback **hardcoded para `"dark"`**.
Resultado: em todo primeiro acesso (sem `localStorage.theme` salvo), o
`head.php` aplica `light` para evitar flash, mas *imediatamente depois*
`main.js` sobrescreve para `dark` e **persiste isso no `localStorage`** — o
visitante nunca vê o tema light por padrão, e a partir daí todo acesso
subsequente já carrega `dark` porque ficou salvo. Isso anula, na prática, a
decisão do plano 012 (Step 1, opção A: "tornar light o padrão"). É um bug de
uma linha, isolado, sem relação com CSS — por isso foi deixado fora do
escopo do 012 e virou este plano dedicado.

## Current state

- `manager/public_html/assets/js/main.js` — script de UI do manager (toggle
  de tema + smooth scroll). Função relevante: `initializeTheme()`.

  ```js
  function initializeTheme() {
    const storageKey = "theme";
    const root = document.documentElement;

    const applyTheme = (theme) => {
      const isDark = theme === "dark";
      root.setAttribute("data-theme", isDark ? "dark" : "light");

      document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
        button.innerHTML = isDark
          ? '<i class="bi bi-sun"></i><span class="d-none d-md-inline ms-1">Claro</span>'
          : '<i class="bi bi-moon-stars"></i><span class="d-none d-md-inline ms-1">Escuro</span>';
        button.setAttribute(
          "aria-label",
          isDark ? "Ativar tema claro" : "Ativar tema escuro",
        );
      });
    };

    const saved = localStorage.getItem(storageKey) || "dark";   // <-- linha 43, o bug
    localStorage.setItem(storageKey, saved);
    applyTheme(saved);

    document.addEventListener("click", function (event) {
      const button = event.target.closest("[data-theme-toggle]");
      if (!button) {
        return;
      }

      const isDark = root.getAttribute("data-theme") === "dark";
      const nextTheme = isDark ? "light" : "dark";
      localStorage.setItem(storageKey, nextTheme);
      applyTheme(nextTheme);
    });
  }
  ```

  Linha exata (confirme com o grep abaixo antes de editar — a numeração pode
  ter deslocado 1-2 linhas se houve edições não relacionadas):
  `const saved = localStorage.getItem(storageKey) || "dark";`

- O anti-flash script em `manager/public_html/ui/common/head.php` (pós-plano
  012) já usa o fallback correto:
  ```js
  var saved = localStorage.getItem('theme');
  var theme = saved ?
      saved :
      (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme', theme);
  ```
  Este plano só precisa alinhar `main.js` a este mesmo comportamento — não
  reescrever a lógica de toggle.

- Os dois scripts leem a **mesma chave** `localStorage` (`"theme"`), então
  basta trocar o fallback de `main.js` para o mesmo padrão do anti-flash
  script (respeitar `prefers-color-scheme`, com fallback final `light`).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Confirmar linha do bug | `grep -n 'getItem(storageKey)' manager/public_html/assets/js/main.js` | 1 match, mostra a linha a editar |
| Guard shared-sync | `bin/check-shared-sync.sh` | exit 0 (JS de `public_html` não é shared; deve seguir verde) |
| Nenhum dark hardcoded remanescente | `grep -n '"dark"' manager/public_html/assets/js/main.js` | só ocorrências de comparação (`=== "dark"`), nenhum fallback default para dark |

## Scope

**In scope**:
- `manager/public_html/assets/js/main.js` (só a função `initializeTheme`, especificamente a linha do fallback)

**Out of scope**:
- Qualquer coisa em `site/`.
- `head.php` (já corrigido pelo plano 012 — não mexer de novo).
- CSS (`main.css`, `dashboard.css`) — não é sobre paleta, é sobre a lógica de tema em JS.
- Reescrever a UI do toggle, os botões `data-theme-toggle`, ou a lógica de clique — só o fallback default.

## Git workflow

- Branch: `advisor/013-theme-default-fix`
- Commit único, Conventional Commits (`fix:`).
- Sem push/PR sem ordem.

## Steps

### Step 1: Alinhar o fallback de `main.js` ao do `head.php`

Em `manager/public_html/assets/js/main.js`, na função `initializeTheme()`,
troque:

```js
const saved = localStorage.getItem(storageKey) || "dark";
```

por:

```js
const saved =
  localStorage.getItem(storageKey) ||
  (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
```

Isso faz `main.js` respeitar o mesmo default que o anti-flash script de
`head.php` já aplica (light, exceto quando o SO pede dark explicitamente),
em vez de forçar dark toda vez que não há valor salvo.

**Verify**:
`grep -n 'getItem(storageKey)' manager/public_html/assets/js/main.js` → mostra
a nova linha com `matchMedia`, sem `|| "dark"` isolado.

## Test plan

Não há teste automatizado de JS de UI neste repo. Verificação:
- Grep acima confirma a mudança textual.
- Conferência manual (se stack disponível): abrir o manager em aba anônima
  (sem `localStorage` prévio) e SO configurado para light → tela carrega
  light e permanece light após `DOMContentLoaded` (sem "piscar" para dark).
  Se não houver stack disponível na execução, registre isso e prossiga —
  não é STOP condition, é o mesmo caso do plano 012.

## Done criteria

- [ ] `main.js` não tem mais `|| "dark"` como fallback incondicional em `initializeTheme`
- [ ] `grep -n 'getItem(storageKey)' manager/public_html/assets/js/main.js` mostra o novo fallback com `matchMedia`
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] Nenhum arquivo fora de `manager/public_html/assets/js/main.js` modificado (`git status`)
- [ ] `plans/README.md` atualizado

## STOP conditions

- A linha citada em "Current state" não bater com o código atual (drift) —
  pare e reporte antes de editar.
- `initializeTheme` tiver sido refatorada de forma que a lógica de toggle
  dependa de outro contrato de dados — pare e reporte em vez de adaptar.

## Maintenance notes

- Se o plano 012 (`data-theme` default e anti-flash script) ainda não tiver
  sido mergeado quando este plano rodar, o efeito combinado ("light por
  padrão de fato") só se completa quando os dois estiverem juntos em
  produção — mas esta correção é válida e correta independentemente disso.
- Se o `head.php` mudar sua lógica de fallback no futuro, `main.js` precisa
  ser atualizado junto — os dois scripts leem a mesma chave `localStorage`
  e devem concordar sobre o default, para não haver "flash" de tema errado
  entre o anti-flash script e o `DOMContentLoaded`.
