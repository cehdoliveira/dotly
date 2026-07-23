# Plan 028: `users.login` ganha UNIQUE constraint no banco

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 6cd0d58..HEAD -- migrations manager/app/inc/controller/config_controller.php`
> Se qualquer arquivo em escopo mudou desde este plano, compare os trechos de
> "Current state" com o código vivo antes de prosseguir; se divergir, trate
> como STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: MED (um ALTER ADD UNIQUE falha se já houver `login` duplicado ativo no banco)
- **Depends on**: none
- **Category**: migration
- **Planned at**: commit `6cd0d58`, 2026-07-18

## Why this matters

`config_controller::userConflictExists()` checa duplicidade de `login` via SELECT
antes do INSERT/UPDATE de usuário admin, mas só `mail` tem UNIQUE no banco
(migration `002_create_table_users.sql`). `login` **não tem**. Duas criações
concorrentes com o mesmo `login` (mails diferentes) podem ambas passar a checagem
antes de qualquer uma comitar → dois usuários ativos com o mesmo login. É a mesma
classe de race que o UNIQUE em `blocked_customers.customer_mail`
(migration 035) fechou para o bloqueio de clientes. O fix fecha a corrida a nível
de banco: o segundo INSERT concorrente falha na constraint e o controller trata
como conflito real.

## Current state

- `migrations/002_create_table_users.sql` — cria a tabela `users`. Hoje só tem
  `UNIQUE KEY mail_UNIQUE (mail)`. A coluna login é `login VARCHAR(255) DEFAULT NULL`
  (linha 12) — **NULL é permitido**; MySQL trata múltiplos NULL como distintos num
  UNIQUE, então rows com `login IS NULL` não colidem entre si. Só logins não-nulos
  iguais colidiriam.
- `manager/app/inc/controller/config_controller.php:307-310` — o `criar` já chama
  `userConflictExists(null, mail, login)` e aborta com mensagem se conflita:
  ```php
  if ($this->userConflictExists(null, $post["mail"], $post["login"])) {
      $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login"];
      basic_redir($config_url);
  }
  ```
- `manager/app/inc/controller/config_controller.php:360-364` — o `criar` já tem um
  catch genérico que, em qualquer `Exception` do INSERT, reporta conflito-ou-erro e
  faz rollback:
  ```php
  } catch (Exception $e) {
      error_log("Erro ao criar usuário: " . $e->getMessage());
      $_SESSION["messages_app"]["danger"] = ["Já existe um usuário com esse e-mail/login ou ocorreu um erro. Tente novamente."];
      basic_redir($config_url, rollback: true);
  }
  ```
  **Este catch já cobre a violação de UNIQUE do login** — quando o segundo INSERT
  concorrente falhar na constraint nova, cai aqui, faz rollback e mostra a mensagem
  de conflito. **Nenhuma mudança de PHP é necessária** (ver Scope). A única entrega
  é a migration.

### Convenção de migration a seguir

Migrations em `migrations/` são numeradas (`NNN_desc.sql`), idempotentes e uma
transação por arquivo. O runner (`site/cgi-bin/run_migrations.php`) roda em ordem
numérica e registra em `migrations_log`. Para um `ALTER ... ADD` idempotente, o
padrão do repo é checar `information_schema.STATISTICS` e montar o DDL condicional
com `PREPARE`/`EXECUTE`. Exemplar exato a copiar:
`migrations/035_unique_customer_mail_blocked_customers.sql` (leia o arquivo inteiro
antes de escrever).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Rodar migrations | `docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php` | exit 0, sem erro |
| Checar duplicados de login (pré-flight) | ver Step 1 | 0 linhas |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Testes manager | `cd manager && php app/inc/lib/vendor/bin/phpunit` | todos passam |

## Scope

**In scope** (os únicos arquivos a criar/modificar):
- `migrations/036_unique_login_users.sql` (criar)

**Out of scope** (NÃO tocar):
- `manager/app/inc/controller/config_controller.php` — o catch genérico existente
  (linhas 360-364) já trata a violação de UNIQUE como conflito. Adicionar um catch
  específico é redundante e fora de escopo — o SQLSTATE nem chega ao controller
  (o `localPDO` normaliza qualquer `PDOException` em `RuntimeException` genérica),
  então não há como distinguir "duplicate-key" de outros erros aqui de qualquer jeito.
- `migrations/002_create_table_users.sql` — migrations passadas são imutáveis; a
  mudança vem como migration nova.
- Qualquer alteração no `userConflictExists()`.

## Git workflow

- Branch: `advisor/028-unique-login`
- Commit único; mensagem em PT-BR, Conventional Commits, ex.:
  `feat: UNIQUE em users.login fecha race de criação de usuário admin`
- Não fazer push nem abrir PR salvo instrução do operador.

## Steps

### Step 1: Pré-flight — confirmar que não há login duplicado ativo

Um `ADD UNIQUE` falha e derruba a migration se já existirem logins não-nulos
duplicados. Rode antes de escrever a migration:

```bash
docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e \
"SELECT login, COUNT(*) c FROM users WHERE login IS NOT NULL AND login <> '' GROUP BY login HAVING c > 1;"
```

(Se as credenciais/DB name diferirem, pegue-as de `docker/docker-compose.yml` ou do
`kernel.php`.)

**Verify**: a query retorna **0 linhas**. Se retornar qualquer linha → **STOP**
(ver STOP conditions): há duplicados que precisam ser deduplicados manualmente
pelo dono antes de aplicar o UNIQUE.

### Step 2: Escrever a migration 036

Crie `migrations/036_unique_login_users.sql`, seguindo o padrão idempotente de
`035_unique_customer_mail_blocked_customers.sql`. A DDL alvo:

```sql
ALTER TABLE `users` ADD UNIQUE KEY `login_UNIQUE` (`login`)
```

Estrutura obrigatória (comentário explicando o porquê + checagem em
`information_schema.STATISTICS` para idempotência, guardando o índice pelo nome
`login_UNIQUE`):

```sql
-- TODOS.md #1 / Plano 028: users.login não tinha UNIQUE no banco (só mail tinha,
-- migration 002). userConflictExists() checava login via SELECT antes do INSERT,
-- mas dois criares concorrentes com o mesmo login (mails diferentes) podiam ambos
-- passar antes de qualquer commit — mesma race que a migration 035 fechou para
-- blocked_customers.customer_mail. login é VARCHAR(255) DEFAULT NULL: múltiplos
-- NULL continuam permitidos (MySQL trata NULL como distinto no UNIQUE), só logins
-- não-nulos iguais passam a colidir. O segundo INSERT concorrente falha na
-- constraint e o catch de config_controller::criar (linhas 360-364) trata como
-- conflito.
--
-- Idempotência: checagem em information_schema (mesmo padrão de 035).

SET @uniq_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'login_UNIQUE'
);
SET @ddl := IF(
    @uniq_exists = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `login_UNIQUE` (`login`)',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

**Verify**: `git diff --stat` mostra só `migrations/036_unique_login_users.sql` como
arquivo novo.

### Step 3: Aplicar e confirmar o índice

```bash
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php
```

**Verify**: rode e confirme que o índice existe:

```bash
docker exec infinnityimportacao mysql -uroot -proot infinnityimportacao -e "SHOW INDEX FROM users WHERE Key_name='login_UNIQUE';"
```
→ deve listar exatamente 1 linha (Column_name=`login`, Non_unique=0).

### Step 4: Confirmar idempotência

Rode o runner de novo:
```bash
docker exec infinnityimportacao php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php
```
**Verify**: exit 0, sem erro (o `IF` faz `DO 0` na 2ª vez porque o índice já existe;
e o `migrations_log` de qualquer forma pula a re-execução).

## Test plan

Não há teste PHP novo obrigatório — a garantia é a constraint no banco, coberta
pelos Steps 3–4. Rode a suíte existente só para confirmar que nada quebrou (o
UNIQUE não deve afetar nenhum fixture que crie usuários com login único):

- `cd manager && php app/inc/lib/vendor/bin/phpunit` → todos passam.
- **Atenção**: se `UserCreateActionTest.php` ou `UsersModelTest.php` criarem dois
  usuários com o **mesmo** login não-nulo no mesmo banco, o novo UNIQUE fará o
  segundo falhar. Se algum teste passar a falhar por isso, é um teste que dependia
  da ausência do UNIQUE — **STOP e reporte** (não altere o teste por conta própria;
  o dono decide se o fixture muda).

## Done criteria

Todas devem valer:

- [ ] `migrations/036_unique_login_users.sql` existe e segue o padrão de idempotência.
- [ ] `SHOW INDEX FROM users WHERE Key_name='login_UNIQUE'` retorna 1 linha com Non_unique=0.
- [ ] Runner roda 2x sem erro (idempotente).
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpunit` → todos passam.
- [ ] `git status` não mostra nenhum arquivo modificado fora do escopo.
- [ ] Linha de status deste plano atualizada em `plans/README.md`.

## STOP conditions

Pare e reporte (não improvise) se:

- O Step 1 achar logins não-nulos duplicados — o dono precisa deduplicar antes.
- Um teste existente passar a falhar por conta do novo UNIQUE (fixture com login
  duplicado) — não edite o teste; reporte.
- O `run_migrations.php` falhar por qualquer motivo além de "índice já existe".
- Você concluir que o fix exige tocar `config_controller.php` — não deveria; se
  parecer necessário, reporte em vez de editar.

## Maintenance notes

- Para quem revisar o PR: confirmar que a migration é idempotente (checagem em
  `information_schema`) e que **nenhum arquivo PHP foi tocado** — o catch existente
  já cobre a violação.
- Follow-up deferido: distinguir "login duplicado" de "mail duplicado" na mensagem
  de erro exigiria expor o SQLSTATE através do `localPDO`, que hoje normaliza tudo
  em `RuntimeException` genérica. Fora de escopo — a mensagem atual ("e-mail/login")
  já é suficiente.
