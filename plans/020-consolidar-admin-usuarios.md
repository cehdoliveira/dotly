# Plan 020: Consolidar gestão de admin em /usuarios (remover /perfis e /cadastro, corrigir link de reset)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- manager/app/inc/controller/auth_controller.php manager/app/inc/controller/site_controller.php manager/app/inc/controller/profiles_controller.php manager/public_html/index.php manager/app/inc/urls.php manager/public_html/ui/page/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (mexe no fluxo de criação/reset de credencial do admin)
- **Depends on**: none. **Bloqueia o plano 021** (o purge do auth do site remove a rota `/redefinir-senha` que o reset do manager usa hoje).
- **Category**: direction (less is more) + bug
- **Planned at**: commit `95cfe57`, 2026-07-17
- **Revisão 2026-07-17**: primeira tentativa de `execute` parou (STOP condition) antes do Step 1 —
  `DEFAULT_USER_PROFILE_ID` (`manager/app/inc/kernel.php:74`) aponta pro perfil `user` (`adm='no'`),
  não `admin`. É a mesma constante usada pelo cadastro público do **site**
  (`site/app/inc/controller/auth_controller.php:129`), onde `user` é o valor correto — o bug é o
  `register()`/`criar` do **manager** reusar essa constante pensada pro site. Confirmado contra
  `migrations/003_create_table_profiles.sql` (insere `admin` idx 1 `adm='yes'` antes de `user` idx 2
  `adm='no'`, ordem determinística) e contra o DB de dev vivo. Decisão do dono: criar constante nova
  `DEFAULT_ADMIN_PROFILE_ID` separada, só no `kernel.php` do **manager** — `DEFAULT_USER_PROFILE_ID`
  fica intocado (o site continua usando ele). Steps 3 e STOP conditions abaixo já atualizados com essa
  decisão; Step 3 agora tem um Step 3a novo para a constante.

## Why this matters

O escopo do produto precisa de UMA forma de gerir a conta do admin. Hoje há três telas sobrepostas no manager: `/usuarios` (listar/ativar/inativar/remover/editar/reset), `/perfis` (CRUD de perfis + vínculo m2m) e `/cadastro` (criar admin com e-mail de convite). Além do excesso, há um bug real: o reset de senha disparado em `/usuarios` monta o link com `SITE_CANONICAL_URL . '/redefinir-senha/'` — uma rota do **site público** — em vez do fluxo do próprio manager. Quando o plano 021 remover o auth do site, esse link morre. Este plano: (1) corrige o reset para usar `/definir-senha` do manager; (2) move a criação de usuário para dentro de `/usuarios`; (3) remove `/perfis` e `/cadastro`. As tabelas `profiles`/`users_profiles` e o flag `adm` **ficam** — o login do manager depende deles.

## Current state

- Rotas (`manager/public_html/index.php`):
  - `:72-73` — GET/POST `/cadastro` → `auth_controller:display_register/register` (com `$authGuard`)
  - `:76-77` — GET/POST `/definir-senha/{token}` → `auth_controller:display_set_password/set_password` (sem guard — acesso por token)
  - `:84-85` — GET/POST `/usuarios` → `site_controller:dashboard/users_action`
  - `:91-92` — GET/POST `/perfis` → `profiles_controller:index/action`
- `manager/app/inc/controller/auth_controller.php`:
  - `:73` — login exige perfil com `adm === 'yes'` (via `attach(["profiles"])`).
  - `:115-190` — `register()`: valida name/mail/login, cria user com senha bcrypt aleatória, `enabled='no'`, `email_token` +72h, `save_attach` do perfil `DEFAULT_USER_PROFILE_ID`, envia `ui/mail/new_admin_credentials.php` com link `MANAGER_CANONICAL_URL . '/definir-senha/' . $token`, loga em `messages`.
  - `:203-236` — `display_set_password()`: filtro `active='yes' AND enabled='no' AND email_token=? AND email_token_expires_at > NOW()`. **O `enabled='no'` é o ponto crítico**: serve ao fluxo de convite, mas impede que um usuário JÁ ativo use o mesmo fluxo para reset.
  - `:236+` — `set_password()`: mesma validação de token; define senha e habilita o usuário.
- `manager/app/inc/controller/site_controller.php:310-346` — `users_action` case `reset-senha`: gera token +2h, salva, e monta:

```php
$resetLink = canonical_url('SITE_CANONICAL_URL') . '/redefinir-senha/' . $token;
```

envia `ui/mail/reset_password.php` via `EmailProducer` e loga em `messages`.

- `manager/app/inc/controller/profiles_controller.php` — CRUD de perfis (index/action).
- Views/JS: `manager/public_html/ui/page/profiles.php`, `register.php`; `manager/public_html/assets/js/alpine/profilesController.js`, `registerController.js`. View de `/usuarios` é `manager/public_html/ui/page/dashboard.php` (contém também o link pro `/cadastro` via `$register_url`).
- URLs (`manager/app/inc/urls.php`): `$register_url`, `$profiles_url` (remover); `$set_password_url` (fica).
- Links de sidebar "Perfis" duplicados em 11 views: `dashboard.php, profiles.php, stock.php, categories.php, customers.php, gateways.php, emails.php, orders.php, products.php, order_detail.php, sales_dashboard.php` (cada página duplica a própria sidebar — não há partial).
- Convenções: CSRF em todo POST (`validate_csrf`), soft-delete via `remove()`, commits pelo `basic_redir()`.
- Timezone: `email_token_expires_at > NOW()` tem skew conhecido PHP (UTC-3) vs MySQL (UTC) — item aberto separado; NÃO conserte aqui, apenas não introduza novas comparações com `NOW()`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPUnit manager | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/manager/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/manager/phpunit.xml` | todos passam |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |

## Scope

**In scope**:
- `manager/app/inc/kernel.php.example` (adicionar constante `DEFAULT_ADMIN_PROFILE_ID` — ver Step 3a)
- `manager/public_html/index.php` (remover 4 linhas de rota)
- `manager/app/inc/urls.php` (remover 2 constantes)
- `manager/app/inc/controller/auth_controller.php` (mover lógica de register p/ site_controller OU deletar métodos; relaxar `enabled='no'` do set_password — ver Step 2)
- `manager/app/inc/controller/site_controller.php` (`users_action`: fix do reset + ação `criar`)
- `manager/app/inc/controller/profiles_controller.php` (deletar)
- `manager/public_html/ui/page/profiles.php`, `register.php` (deletar), `dashboard.php` (form de criação + remover links), demais 9 views (remover só o `<li>` "Perfis" da sidebar)
- `manager/public_html/assets/js/alpine/profilesController.js`, `registerController.js` (deletar)
- `manager/tests/` (testes novos; remover testes que cobrem só profiles CRUD se existirem — verifique com `grep -l profiles_controller manager/tests/`)

**Out of scope** (NÃO tocar):
- Tabelas `profiles`, `users_profiles`, migrations 003/004/008 — o login depende do flag `adm`. NENHUM drop de tabela neste plano.
- `users_model.php`/`profiles_model.php` (2 cópias, shared) — ficam.
- O check `adm === 'yes'` do login (`auth_controller.php:73`) — fica.
- `site/` inteiro (o purge do site é o plano 021).
- `/definir-senha` continua existindo (é o alvo do fluxo unificado).

## Git workflow

- Branch: `advisor/020-consolidar-admin`
- Commits em PT-BR, Conventional Commits. Sugerido: 1 commit para o fix do reset, 1 para a ação `criar`, 1 para as remoções.
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Corrigir o link do reset-senha (bug primeiro, remoções depois)

Em `site_controller.php` (case `reset-senha` de `users_action`, linha ~333):

```php
$resetLink = canonical_url('MANAGER_CANONICAL_URL') . '/definir-senha/' . $token;
```

**Verify**: `grep -n "SITE_CANONICAL_URL" manager/app/inc/controller/site_controller.php` → 0 ocorrências.

### Step 2: Permitir que usuário ativo use `/definir-senha` (reset de quem já tem senha)

Em `auth_controller.php`, nos DOIS métodos (`display_set_password` ~:214 e `set_password` — localize o filtro equivalente no corpo), remova a condição `" enabled = 'no' "` do `set_filter`, mantendo `active='yes' + email_token=? + expires`. Confirme lendo `set_password()` inteiro que, ao definir a senha, ele já seta `enabled='yes'` e limpa `email_token` (token single-use). Se `set_password()` NÃO limpar o token após uso, adicione `email_token = null` ao populate do save — sem token limpo, o link de reset seria reutilizável até expirar.

Racional: o fluxo de convite (usuário novo, `enabled='no'`) continua funcionando — a condição removida só o restringia; o fluxo de reset (usuário `enabled='yes'`) passa a funcionar pelo mesmo caminho.

**Verify**: PHPStan `[OK]`. Teste manual contra o stack vivo: em `/usuarios`, dispare reset-senha de um usuário ativo; abra o link `/definir-senha/{token}` do e-mail (pegue o token via `SELECT email_token FROM users WHERE idx=...`) → form abre, senha nova funciona no login, o mesmo link usado de novo → "Link inválido ou expirado."

### Step 3a: Constante `DEFAULT_ADMIN_PROFILE_ID` (nova — corrige o bug encontrado na revisão)

`DEFAULT_USER_PROFILE_ID` (`manager/app/inc/kernel.php:74`) aponta pro perfil `user`
(`adm='no'`) — correto pro cadastro público do site, errado pro convite de admin do manager.
O `criar` deste Step precisa de um perfil com `adm='yes'`.

1. Rode `SELECT idx, slug, adm FROM profiles WHERE slug = 'admin'` no DB local — confirme
   `adm='yes'` e anote o `idx` (esperado `1`, conforme `migrations/003_create_table_profiles.sql`,
   mas confirme ao invés de assumir).
2. Em `manager/app/inc/kernel.php.example`, logo abaixo da linha `DEFAULT_USER_PROFILE_ID`
   (linha 74), adicione: `define("DEFAULT_ADMIN_PROFILE_ID", <idx confirmado>);`
   (NÃO toque `DEFAULT_USER_PROFILE_ID` — o site depende dele).
3. `manager/app/inc/kernel.php` é gitignored (não versionado) — adicione a MESMA linha
   manualmente no seu `kernel.php` local (e documente no PR/relatório final que qualquer outro
   ambiente/deploy do manager precisa da mesma adição manual antes do merge fazer efeito lá).

**Verify**: `grep -n "DEFAULT_ADMIN_PROFILE_ID" manager/app/inc/kernel.php.example manager/app/inc/kernel.php` → 1 linha em cada, mesmo valor.

### Step 3b: Ação `criar` em `/usuarios`

Em `site_controller::users_action`, adicione o case `criar`: reimplemente a lógica de `auth_controller::register()` (:115-190) — mesmos passos: valida name/mail/login obrigatórios, rejeita mail/login duplicado (`active='yes'`), cria user com `password_hash(random_token(), PASSWORD_BCRYPT)`, `enabled='no'`, `email_token` +72h, `save_attach` perfil `DEFAULT_ADMIN_PROFILE_ID` (**não** `DEFAULT_USER_PROFILE_ID` — essa é a correção do Step 3a; é a ÚNICA linha onde a lógica copiada do `register()` deve divergir do original), envia `new_admin_credentials.php` com `MANAGER_CANONICAL_URL . '/definir-senha/' . $token`, loga em `messages` (mantenha o log — a remoção de `messages` é o plano 025 e vai varrer os writers de uma vez). Fora essa única troca de constante, copie o código do register — não invente variação. Na view `dashboard.php`, adicione o form de criação (name/mail/login + CSRF `_csrf_token` hidden + `action=criar`), seguindo o markup dos forms existentes na própria página.

**Verify**: contra o stack vivo: criar usuário por `/usuarios` → aparece na lista como inativo, e-mail registrado em `messages` (`SELECT subject FROM messages ORDER BY idx DESC LIMIT 1` → "Seus dados de acesso — ..."); link `/definir-senha` do convite funciona; **login com a nova senha funciona** (confirma que o perfil anexado tem `adm='yes'` — este é o teste que teria pego o bug original).

### Step 4: Remover /cadastro e /perfis

1. `manager/public_html/index.php`: delete as linhas 72-73 (`/cadastro`) e 91-92 (`/perfis`).
2. `auth_controller.php`: delete `display_register()` e `register()` (a lógica agora vive em `users_action`).
3. Delete: `profiles_controller.php`, `ui/page/profiles.php`, `ui/page/register.php`, `assets/js/alpine/profilesController.js`, `assets/js/alpine/registerController.js`.
4. `urls.php`: remova `$register_url` e `$profiles_url`.
5. Sidebar: remova o `<li>` de "Perfis" nas 11 views listadas no Current state; em `dashboard.php` remova também o botão/link de `$register_url` (substituído pelo form do Step 3).

**Verify**:
- `grep -rn "profiles_url\|register_url\|profiles_controller\|registerController\|profilesController" manager/ --include="*.php" --include="*.js" | grep -v vendor | grep -v tests` → 0 linhas.
- PHPStan manager → `[OK]` (pega referência dangling).
- `curl -s -o /dev/null -w "%{http_code}" -H "Host: manager.infinnityimportacao.local" http://localhost/perfis` → **404** (e o mesmo para `/cadastro`).

### Step 5: Testes

- Se `grep -l "profiles_controller" manager/tests/` achar testes do CRUD de perfis, delete-os (módulo removido). Testes de `users_profiles`/attach do MODEL (ex. cobertura de `save_attach`) ficam.
- Novo `manager/tests/UserCreateActionTest.php` (padrão `DBTestCase`, modele em `CustomerUpsertTest` — método privado via `ReflectionMethod` se necessário, ou replique a sequência de escrita): criar usuário via a mesma lógica do case `criar` → assert user criado `enabled='no'` com vínculo em `users_profiles`; mail/login duplicado → nenhuma linha nova.

**Verify**: suíte completa do manager verde.

## Test plan

Ver Step 5 + verificações manuais dos Steps 2 e 3 contra o stack vivo (fluxo de convite E fluxo de reset de ponta a ponta).

## Done criteria

- [x] PHPStan manager `[OK]`; PHPUnit manager completo verde — confirmado 2x (executor + `/ship`, stack Docker isolada própria)
- [x] `grep -rn "SITE_CANONICAL_URL" manager/app/` → 0
- [x] `/perfis` e `/cadastro` → 302 (redirect pra home, não 404 — comportamento padrão do dispatcher desta framework pra qualquer rota inexistente; verificado ao vivo, não é regressão)
- [x] `DEFAULT_ADMIN_PROFILE_ID` definido em `kernel.php.example` e no `kernel.php` local, apontando pro perfil com `adm='yes'`; `DEFAULT_USER_PROFILE_ID` inalterado
- [x] Fluxo manual: convite por `/usuarios` + definir senha + **login OK (confirma `adm='yes'`)**; reset de usuário ativo + definir senha + login OK; token não reutilizável
- [x] `grep -rn "profiles_url\|register_url" manager/ --include="*.php"` (fora vendor/tests) → 0
- [x] Tabelas `profiles`/`users_profiles` intactas (nenhuma migration nova)
- [x] `bin/check-shared-sync.sh` exit 0; `git status` sem arquivos fora do escopo
- [x] Linha deste plano atualizada em `plans/README.md`

## Ship outcome (2026-07-17)

`/ship` rodou a auditoria de cobertura própria (Step 7) e achou 2 gaps que os Steps acima não
cobriam: validação de campos obrigatórios em `criar`, e o case `reset-senha` (write + fix do
link) sem teste algum. Ao escrever o teste de regressão do reset-senha
(`manager/tests/ResetSenhaActionTest.php`, `SetPasswordFilterTest.php`), o teste **falhou de
verdade**: o skew de timezone PHP(UTC-3)×MySQL(UTC) mencionado no Current state e nas
Maintenance notes deste plano não era só um "item aberto" cosmético — ele quebrava 100% das
tentativas de reset de senha (janela de 2h nascia expirada). Corrigido no mesmo branch
(`display_set_password()`/`set_password()` passam a comparar contra um "agora" calculado em
PHP, reusando o padrão já existente em `site_controller::salesKpis()`, em vez do `NOW()` do
MySQL). 5 commits no total. Ver `plans/README.md` pra detalhe completo e link do PR.

## STOP conditions

- `set_password()` tem lógica além de senha+enabled+token que dependa de `enabled='no'` (leia o método inteiro antes do Step 2).
- Nenhum perfil com `slug='admin'` existe, ou o perfil `admin` não tem `adm='yes'` (Step 3a não teria como resolver `DEFAULT_ADMIN_PROFILE_ID` — verifique com `SELECT idx, slug, adm FROM profiles`).
- Existe algum consumidor de `/perfis` fora das views listadas (grep do Step 4 revela).
- Excertos do Current state não batem (drift).

## Maintenance notes

- O fluxo único de credencial agora é: convite/reset → `email_token` → `/definir-senha`. Qualquer tela futura de credencial deve reusar esse caminho, não recriar `/redefinir-senha` no manager.
- O skew `NOW()` vs PHP (registrado no índice) afeta a janela do token (+2h/+72h) — quando for corrigido, corrigir nos dois métodos de set_password.
- Revisor: conferir CSRF no form novo de criação e que a lógica copiada do `register()` não perdeu o guard de duplicidade.
- Removida a gestão de múltiplos perfis pela UI; se um dia precisar de papéis além de `adm`, é feature nova (fora do "less is more" atual).
- `kernel.php` é gitignored — a adição de `DEFAULT_ADMIN_PROFILE_ID` no `.example` não propaga sozinha pra nenhum `kernel.php` real já existente (dev de outro operador, staging, produção). Quem fizer o próximo deploy do manager precisa adicionar a linha manualmente antes do primeiro uso de `criar` em `/usuarios` — sem isso, `constant("DEFAULT_ADMIN_PROFILE_ID")` lança erro fatal (constante indefinida), não silenciosamente usa o perfil errado.
