# Plan 018: Corrigir login de usuário soft-deleted, sql_mode estrito e guard de upload

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- site/app/inc/controller/auth_controller.php manager/app/inc/controller/auth_controller.php docker/docker-compose.yml docker/interface/default.conf docker/interface/entrypoint.sh`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (o sql_mode estrito pode expor escritas que hoje dependem de coerção silenciosa)
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

Três problemas de segurança independentes, todos confirmados por leitura de código:

1. **Login aceita usuário removido.** A ação "remover" do painel (`/usuarios`) faz soft-delete (`active='no'`), mas a query de login filtra só `enabled='yes'` — um admin "removido" continua logando no manager com a senha antiga. O desprovisionamento não revoga acesso de verdade.
2. **MySQL roda com `sql_mode=""`** no Docker (dev **e** o compose usado como referência de prod), enquanto o CI usa `mysql:8.0` estrito por default. Valores fora de faixa em colunas de centavos, strings estouradas (`customer_name`, `tracking_code`) e ENUMs inválidos são coagidos/truncados em silêncio em runtime, mas causariam erro nos testes — os testes validam contra um banco que se comporta diferente do real.
3. **Diretórios de upload com `chmod 777` e sem guard de execução PHP no nginx** — hoje o `handle_upload()` valida MIME e força extensão segura, mas qualquer regressão futura na validação vira RCE porque o nginx executa qualquer `.php` sob o docroot, inclusive em `/assets/upload/`.

## Current state

- `manager/app/inc/controller/auth_controller.php:52` e `site/app/inc/controller/auth_controller.php:52` — query de login (idêntica nos 2 ambientes):

```php
$users->set_field([" idx ", " name ", " mail ", " login ", " password "]);
$users->set_filter(["enabled = 'yes'", "? IN (mail,login)"], [$info["post"]["login"]]);
```

`set_filter()` **substitui** o filtro default do model — não existe `active='yes'` implícito aqui.

- `manager/app/inc/lib/DOLModel.php:119-137` — `remove()` seta apenas `active='no', removed_at, removed_by`; nunca toca `enabled`.
- `manager/app/inc/controller/site_controller.php:301` — a ação `remover` de `/usuarios` chama `$update->remove()`.
- `docker/docker-compose.yml:38` — `command: --sql_mode=""` no serviço `mysql:8.0`.
- `docker/interface/entrypoint.sh:24-25`:

```bash
chmod 777 /var/www/infinnityimportacao/manager/public_html/assets/upload/ 2>/dev/null || true
chmod 777 /var/www/infinnityimportacao/site/public_html/assets/upload/ 2>/dev/null || true
```

- `docker/interface/default.conf` — dois server blocks (site e manager); em cada um, o location `~ [^/]\.php(/|$)` (linhas 36 e 96) executa qualquer `.php` do docroot via FastCGI. Não há location específico para `/assets/upload/`.

Convenções do repo que se aplicam:
- Framework LEGGO: `app/inc/lib/` e `app/inc/model/` são cópias byte-idênticas entre `site/` e `manager/` — **os controllers são per-ambiente** (este plano edita `auth_controller.php` dos dois lados, mas eles NÃO são idênticos entre si; edite cada um no seu ponto).
- Testes de banco estendem `DBTestCase` (transação + rollback por teste). Exemplo estrutural: `manager/tests/CustomerSearchTest.php`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPUnit site | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/site/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/site/phpunit.xml` | todos passam (1 skip `PAGBANK_TOKEN` é esperado) |
| PHPUnit manager | mesmo comando trocando `site` por `manager` | todos passam |
| Sync guard | `bin/check-shared-sync.sh` | exit 0 |
| Recriar stack | `docker compose -f docker/docker-compose.yml up -d --build` | containers sobem |

Nota conhecida: `bin/test.sh` não fixa o working dir do `docker exec` e o PHPUnit imprime o help — use os comandos acima, não o script.

## Scope

**In scope** (únicos arquivos a modificar):
- `site/app/inc/controller/auth_controller.php` (1 linha)
- `manager/app/inc/controller/auth_controller.php` (1 linha)
- `docker/docker-compose.yml` (1 linha)
- `docker/interface/default.conf` (2 blocos novos)
- `docker/interface/entrypoint.sh` (2 linhas)
- `manager/tests/LoginActiveFilterTest.php` (novo)

**Out of scope** (NÃO tocar):
- `DOLModel.php` / `users_model.php` — a correção é no filtro de login, não no framework.
- O skew de timezone `email_token_expires_at > NOW()` no mesmo arquivo — item aberto separado, já registrado no índice.
- `kernel.php` / `kernel.php.example`.

## Git workflow

- Branch: `advisor/018-fixes-seguranca`
- Commits em PT-BR, Conventional Commits (ex. do repo: `fix: revisao adversarial do plano 017 (indice + validacao de formato de e-mail)`)
- Não abrir PR nem fazer push sem instrução do operador.

## Steps

### Step 1: Adicionar `active = 'yes'` ao filtro de login (2 ambientes)

Em `manager/app/inc/controller/auth_controller.php:52` e `site/app/inc/controller/auth_controller.php:52`, troque:

```php
$users->set_filter(["enabled = 'yes'", "? IN (mail,login)"], [$info["post"]["login"]]);
```

por:

```php
$users->set_filter([" active = 'yes' ", "enabled = 'yes'", "? IN (mail,login)"], [$info["post"]["login"]]);
```

**Verify**: `grep -n "active = 'yes'" site/app/inc/controller/auth_controller.php manager/app/inc/controller/auth_controller.php | grep -i "enabled"` → 1 linha por arquivo. PHPStan nos 2 ambientes → `[OK]`.

### Step 2: Teste de regressão

Crie `manager/tests/LoginActiveFilterTest.php` estendendo `DBTestCase`. O caminho de `login()` termina em `basic_redir()`→`exit()` (não exercitável — convenção do repo, ver docblock de `WebhookIdempotencyTest`), então o teste replica a MESMA query do controller:

- Caso 1: cria usuário `active='no', enabled='yes'` com senha conhecida; roda `users_model` com o filtro novo (`active='yes'` + `enabled='yes'` + `? IN (mail,login)`); assert de resultado vazio.
- Caso 2: mesmo usuário com `active='yes'` → assert que a linha volta.

Padrão estrutural: siga `manager/tests/CustomerSearchTest.php` (criação de fixture via model + asserts `assertSame`).

**Verify**: PHPUnit manager com `--filter LoginActiveFilterTest` → 2/2 passam.

### Step 3: Habilitar sql_mode estrito

Em `docker/docker-compose.yml:38`, troque `command: --sql_mode=""` por:

```yaml
command: --sql_mode="STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"
```

(é o default do MySQL 8.0 — o mesmo que o CI usa). Recrie o container do MySQL: `docker compose -f docker/docker-compose.yml up -d mysql`.

**Verify**: `docker exec mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT @@sql_mode;"` (senha vem de `docker/.env`, não a copie para lugar nenhum) → contém `STRICT_TRANS_TABLES`.

### Step 4: Rodar as duas suítes completas contra o modo estrito

**Verify**: PHPUnit site e manager completos → todos passam. Se qualquer teste falhar com erro de truncamento/out-of-range que não falhava antes, isso é um bug real de escrita que o modo lax mascarava — registre o teste e a coluna exatos e trate como STOP condition (não "conserte" alterando o teste).

### Step 5: Guard de execução PHP nos uploads (nginx)

Em `docker/interface/default.conf`, adicione em CADA um dos 2 server blocks, ANTES do location `~ [^/]\.php(/|$)` (nginx dá precedência a `^~`):

```nginx
    location ^~ /assets/upload/ {
        try_files $uri =404;
    }
```

Sem `fastcgi_pass` dentro do bloco, um `.php` ali é servido como arquivo estático (ou 404), nunca executado.

**Verify**: `docker compose -f docker/docker-compose.yml up -d --build` e depois `docker exec infinnityimportacao nginx -t` → `syntax is ok`. Teste funcional: crie um arquivo `docker exec infinnityimportacao sh -c 'echo "<?php echo \"EXEC\"; " > /var/www/infinnityimportacao/site/public_html/assets/upload/probe.php'`, então `curl -s -H "Host: infinnityimportacao.local" http://localhost/assets/upload/probe.php` → a resposta NÃO contém `EXEC` (vem o fonte cru ou 404). Remova o probe depois.

### Step 6: Permissões dos uploads

Em `docker/interface/entrypoint.sh:24-25`, troque os dois `chmod 777` por:

```bash
chown -R www-data:www-data /var/www/infinnityimportacao/manager/public_html/assets/upload/ /var/www/infinnityimportacao/site/public_html/assets/upload/ 2>/dev/null || true
chmod 775 /var/www/infinnityimportacao/manager/public_html/assets/upload/ /var/www/infinnityimportacao/site/public_html/assets/upload/ 2>/dev/null || true
```

**Verify**: recrie o container; suba uma imagem de produto real pelo manager (`/produtos`, form de upload) contra o stack vivo → upload funciona. Se o volume for bind-mount de host com owner diferente e o upload falhar com permission denied, isso é STOP condition (reporte o uid/gid observado em vez de voltar pro 777).

## Test plan

- `manager/tests/LoginActiveFilterTest.php` (Step 2): 2 casos descritos acima.
- Suítes completas nos 2 ambientes após o Step 3 (o modo estrito É o teste — qualquer falha nova é achado).
- Probe manual de execução PHP no upload dir (Step 5).

## Done criteria

- [ ] PHPStan `[OK]` nos 2 ambientes
- [ ] PHPUnit site e manager completos passam com sql_mode estrito
- [ ] `LoginActiveFilterTest` 2/2
- [ ] `grep -c "sql_mode=\"\"" docker/docker-compose.yml` → 0
- [ ] `grep -c "chmod 777" docker/interface/entrypoint.sh` → 0
- [ ] `grep -c "assets/upload" docker/interface/default.conf` → 2 (um por server block)
- [ ] `bin/check-shared-sync.sh` → exit 0
- [ ] `git status` sem arquivos fora do escopo
- [ ] Linha deste plano atualizada em `plans/README.md`

## STOP conditions

- Excertos do "Current state" não batem com o código vivo (drift).
- Step 4: qualquer teste falha em modo estrito (coerção mascarava bug real — reportar coluna/teste, não contornar).
- Step 6: upload real falha após a troca de permissão.
- Alguma conta legítima do banco de dev tem `active='no'` + `enabled='yes'` e alguém depende de logar com ela (verifique com `SELECT idx, login, active, enabled FROM users` antes do Step 1 e reporte se existir).

## Maintenance notes

- Quem revisar o PR deve conferir que o filtro novo usa a MESMA grafia com espaços (` active = 'yes' `) do resto do repo — `set_filter` concatena com `implode(" and ")`.
- Se um dia a criação de usuário mudar (plano 020), o teste do Step 2 continua válido — ele testa a query, não o fluxo.
- O sql_mode estrito passa a ser pré-requisito implícito de qualquer migration futura: colunas mal dimensionadas agora erram em runtime em vez de truncar.
