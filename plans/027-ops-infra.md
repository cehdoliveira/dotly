# Plan 027: Higiene de infra — cron dos e-mails, OPcache, README, supervisão, pins e .htaccess morto

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 95cfe57..HEAD -- docker/ bin/ site/public_html/.htaccess manager/public_html/.htaccess .github/workflows/ci.yml`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3 (exceto o item 1, que é P2 — afeta UX de e-mail do cliente)
- **Effort**: M (soma de itens S independentes)
- **Risk**: LOW/MED (OPcache e supervisão mudam comportamento do container)
- **Depends on**: none
- **Category**: dx + docs
- **Planned at**: commit `95cfe57`, 2026-07-17

## Why this matters

Seis itens de operação, todos pequenos e independentes, que juntos tiram atrito real: (1) o e-mail de "pagamento confirmado" pode demorar até 1h porque o cron do dispatcher roda de hora em hora; (2) OPcache desligado = recompilar todo o PHP a cada request; (3) não existe README/AGENTS.md commitado — clone limpo não tem doc nenhuma (o CLAUDE.md é gitignored); (4) os workers Kafka de e-mail rodam em background sem supervisão — se caírem, e-mail para em silêncio; (5) `kafka:latest`/`kafka-ui:latest` sem pin quebram reprodutibilidade; (6) os `.htaccess` são config Apache morta num deploy nginx, com headers que CONTRADIZEM o `default.conf` (CORS `*`, X-Frame-Options divergente) — um mantenedor que "endurecer" ali não muda nada.

## Current state

- `docker/interface/crontab:31,34`:

```
*/5 * * * * flock -n /tmp/infinnityimportacao_migrate.lock php /var/www/infinnityimportacao/site/cgi-bin/run_migrations.php >> /var/log/migrations.log 2>&1
0 * * * * flock -n /tmp/infinnityimportacao_dispatch_emails.lock php /var/www/infinnityimportacao/site/cgi-bin/dispatch_emails.php >> /var/log/dispatch_emails.log 2>&1
```

- `docker/interface/php.ini:1795` — `;opcache.enable=1` (bloco `[opcache]` todo comentado).
- `.gitignore:34` ignora `CLAUDE.md`; `:41-42` já whitelistam `!README.md` e `!AGENTS.md`, mas nenhum dos dois existe.
- `docker/interface/entrypoint.sh:50-55` — 2 workers Kafka lançados com `&`, PIDs capturados e nunca monitorados; só o nginx roda em foreground. `docker/docker-compose.yml:2-32` — serviço `infinnityimportacao` SEM `restart:` nem `healthcheck:` (mysql/redis/kafka têm `restart: always`).
- `docker/docker-compose.yml:82` `apache/kafka:latest`; `:123` `provectuslabs/kafka-ui:latest` (contraste: `mysql:8.0`, `redis:7.2-alpine` pinados).
- `site/public_html/.htaccess` e `manager/public_html/.htaccess` — diretivas Apache (`Header always set Access-Control-Allow-Origin "*"`, `X-Frame-Options "SAMEORIGIN"`, rewrites). O container roda nginx (`docker/interface/default.conf`), que já seta headers mais estritos (X-Frame-Options DENY etc.) e nunca lê `.htaccess`.
- Fonte de verdade da doc local: `CLAUDE.md` na raiz (gitignored) — setup Docker, cp do kernel.php, comandos de teste, regra das cópias byte-idênticas, hooks.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Rebuild stack | `docker compose -f docker/docker-compose.yml up -d --build` | containers sobem |
| PHPUnit (2 envs) | `docker exec -e HTTP_HOST=localhost infinnityimportacao php /var/www/infinnityimportacao/<env>/app/inc/lib/vendor/bin/phpunit -c /var/www/infinnityimportacao/<env>/phpunit.xml` | verde |
| Health do site | `curl -s -o /dev/null -w "%{http_code}" -H "Host: infinnityimportacao.local" http://localhost/` | 200 |

## Scope

**In scope**:
- `docker/interface/crontab`, `docker/interface/php.ini`, `docker/interface/entrypoint.sh`, `docker/docker-compose.yml`
- `site/public_html/.htaccess`, `manager/public_html/.htaccess` (deletar)
- `README.md` (novo, na raiz)

**Out de scope** (NÃO tocar):
- `docker/interface/default.conf` (exceto se o plano 018 ainda não rodou — não misture; os headers dele são a fonte de verdade e ficam como estão).
- `Dockerfile` build-time (mover o composer install para build multi-stage foi avaliado e DEFERIDO — mexe no workflow de bind-mount de dev; registrado em Maintenance).
- `bin/test.sh` (bug conhecido do workdir — item separado, não misturar).
- `kernel.php*`, `composer.*`.

## Git workflow

- Branch: `advisor/027-ops-infra`
- Commits em PT-BR, Conventional Commits — 1 commit por item (facilita revert seletivo).
- Não abrir PR nem push sem instrução do operador.

## Steps

### Step 1: Dispatcher de e-mails a cada 5 minutos

`docker/interface/crontab:34`: troque `0 * * * *` por `*/5 * * * *` (o `flock -n` já impede sobreposição).

**Verify**: rebuild; `docker exec infinnityimportacao crontab -l | grep dispatch` (ou inspecione o arquivo instalado conforme o Dockerfile monta o crontab) → `*/5`.

### Step 2: OPcache

Em `docker/interface/php.ini`, descomente/adicione no bloco `[opcache]`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

`validate_timestamps=1` + `revalidate_freq=2` mantém o workflow de bind-mount de dev funcionando (edições aparecem em ≤2s). Num deploy imutável de produção o operador pode zerar `validate_timestamps` — deixe essa nota como comentário no próprio ini.

**Verify**: rebuild; `docker exec infinnityimportacao php -r "var_dump(function_exists('opcache_get_status') && opcache_get_status() !== false);"` — atenção: CLI pode reportar false com `enable_cli=0`; o teste definitivo é via FPM: `curl` na home 2x e `docker exec infinnityimportacao php-fpm -tt 2>&1 | grep -i opcache` ou um script temporário via web. Critério mínimo: home responde 200 e edição num arquivo PHP de view aparece no browser em ≤5s (bind-mount vivo).

### Step 3: README.md commitado

Crie `README.md` na raiz cobrindo (resuma do CLAUDE.md local — NÃO copie a seção de guidelines comportamentais, só o factual):

- O que é (whitelabel PHP 8.4 + MySQL 8, framework próprio LEGGO, 2 ambientes site/manager).
- Setup: `docker compose -f docker/docker-compose.yml up -d --build`, `cp` dos 2 `kernel.php` a partir dos `.example`, `git config core.hooksPath .githooks`, hosts locais (`infinnityimportacao.local`, `manager.infinnityimportacao.local`).
- Comandos: PHPStan por ambiente, PHPUnit via docker exec (com o comando COMPLETO que funciona — o `bin/test.sh` tem bug conhecido de workdir), migrations.
- Regras de arquitetura: cópias byte-idênticas de `lib/`/`model/` (+ o guard), transação única por request, soft-delete, dispatcher só GET/POST.
- Ponteiro para `plans/README.md` (backlog/histórico).

**Verify**: `git ls-files README.md` → listado (a whitelist do .gitignore já permite). Um `docker compose` + os comandos do README funcionam copiados literalmente (teste você mesmo cada um).

### Step 4: Supervisão dos workers + restart do app

1. `docker/docker-compose.yml`, serviço `infinnityimportacao`: adicione `restart: unless-stopped` e um healthcheck simples:

```yaml
    healthcheck:
      test: ["CMD-SHELL", "curl -sf -H 'Host: infinnityimportacao.local' http://localhost/ >/dev/null || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
```

(confirme que `curl` existe na imagem: `docker exec infinnityimportacao which curl`; se não existir, use `php -r` com `file_get_contents` no test.)
2. `entrypoint.sh`: troque o lançamento fire-and-forget dos 2 workers por um loop supervisor simples por worker:

```bash
supervise_worker() {
    local script="$1" log="$2"
    while true; do
        php "$script" >> "$log" 2>&1
        echo "[entrypoint] worker $script morreu (exit $?); reiniciando em 5s" >> "$log"
        sleep 5
    done
}
supervise_worker /var/www/infinnityimportacao/manager/cgi-bin/kafka_email_worker.php /var/log/kafka_email_worker_manager.log &
supervise_worker /var/www/infinnityimportacao/site/cgi-bin/kafka_email_worker.php /var/log/kafka_email_worker_site.log &
```

(nginx continua `exec` em foreground como PID 1 — sem mudança).

**Verify**: rebuild; `docker exec infinnityimportacao sh -c 'pkill -f kafka_email_worker.php; sleep 8; pgrep -fc kafka_email_worker.php'` → `2` (os dois voltaram sozinhos). `docker inspect --format '{{.State.Health.Status}}' infinnityimportacao` → `healthy` após ~1 min.

### Step 5: Pinar imagens

`docker-compose.yml`: `apache/kafka:latest` → pin na versão atualmente em uso (`docker exec kafka sh -c 'ls /opt/kafka/libs/ | grep -o "kafka_[0-9.]*-[0-9.]*" | head -1'` ou `docker inspect kafka --format '{{index .Config.Labels}}'` para descobrir; se nada funcionar, pin na minor estável mais recente da doc oficial). `provectuslabs/kafka-ui:latest` → `provectuslabs/kafka-ui:v0.7.2` (última release estável conhecida; confirme a tag existente no registry antes).

**Verify**: `docker compose -f docker/docker-compose.yml pull kafka kafka_ui && docker compose -f docker/docker-compose.yml up -d` → sobem; stack funcional (dispatcher manual roda sem erro de broker).

### Step 6: Remover .htaccess mortos

Delete `site/public_html/.htaccess` e `manager/public_html/.htaccess`.

**Verify**: `curl -sI -H "Host: infinnityimportacao.local" http://localhost/ | grep -i "x-frame-options"` → continua vindo do nginx (DENY). Home/manager 200. `git ls-files | grep htaccess` → vazio.

### Step 7: Suítes completas de sanidade

**Verify**: PHPUnit site e manager completos verdes com o stack rebuilt (OPcache ligado + sql_mode do plano 018 se já mergeado).

## Test plan

Sem testes de PHPUnit novos (mudanças de infra). As verificações são os comandos por step — em especial o kill/respawn dos workers (Step 4) e a latência de edição com OPcache (Step 2).

## Done criteria

- [ ] Crontab do dispatcher em `*/5`
- [ ] OPcache ativo via FPM; edição em bind-mount aparece em ≤5s
- [ ] `README.md` commitado; comandos nele executados e funcionais
- [ ] Workers ressuscitam após kill; container `healthy`; `restart: unless-stopped` no app
- [ ] Nenhuma tag `:latest` no docker-compose.yml (`grep -c ":latest" docker/docker-compose.yml` → 0)
- [ ] `.htaccess` deletados; headers de segurança continuam servidos pelo nginx
- [ ] PHPUnit verde nos 2 ambientes pós-rebuild
- [ ] `git status` limpo fora do escopo; linha atualizada em `plans/README.md`

## STOP conditions

- Algum serviço não subir após pin de imagem (incompatibilidade de versão do Kafka) — reverta o pin daquele serviço e reporte a tag testada.
- OPcache causar comportamento inconsistente detectável (view desatualizada além do `revalidate_freq`) — reporte com o cenário antes de desligar.
- A imagem não tiver `curl` nem alternativa PHP viável para o healthcheck.
- Este repo estiver deployado em Apache em algum ambiente (pergunta ao operador ANTES do Step 6 se houver qualquer sinal — ex. docs de deploy citando Apache).

## Maintenance notes

- Deferido conscientemente: mover `composer install` do entrypoint para build multi-stage do Dockerfile (imagem menor, boot mais rápido, deps pinadas em build) — exige repensar o bind-mount de dev; candidato a plano futuro se o deploy virar imagem imutável.
- Deferido: logrotate para `_data/logs` (os logs de worker/cron crescem sem teto — aceitável no volume atual; revisar se o disco apertar).
- O healthcheck testa o SITE; se um dia os hostnames mudarem (whitelabel novo via `bin/init-whitelabel.sh`), o `Host:` do healthcheck precisa acompanhar.
- Revisor: conferir que o supervisor loop não engole o exit do nginx (workers em background, nginx segue PID 1).
