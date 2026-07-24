# Dotly

Whitelabel PHP 8.4 + MySQL 8.0 sobre um framework próprio — **LEGGO**
(não é Laravel/Symfony) — rodando em Docker. Um único codebase serve dois
ambientes:

- **site** — frontend público.
- **manager** — painel administrativo.

Os dois ambientes diferem por `kernel.php`, controllers, rotas, listas e views.
O framework compartilhado (`app/inc/lib/` e `app/inc/model/`) é idêntico byte a
byte entre `manager/` e `site/` — `bin/check-shared-sync.sh` bloqueia o commit
se divergirem.

## Whitelabel — instanciando uma nova marca

Para iniciar um novo projeto a partir deste whitelabel, rode o script
`bin/init-whitelabel.sh` e ele instancia a marca em segundos.

1. Clone o repo.
2. Rode o script:
   ```bash
   bin/init-whitelabel.sh \
       --name "Minha Marca" \
       --site-url "https://minhamarca.com.br" \
       --manager-url "https://manager.minhamarca.com.br" \
       --primary-color "#1a73e8" \
       --admin-email "admin@minhamarca.com.br" \
       --admin-name "Admin"
   ```
   Apenas `--name`, `--site-url` e `--manager-url` são obrigatórios. Os demais
    têm defaults brand-neutral.
3. Preencha manualmente **ANTES** de subir o stack:
   - `docker/.env`: renomeie de `docker/.env.example`, ajuste
     `MYSQL_DATABASE` e `MYSQL_USER` (o script sugere `db_${SLUG}` e
     `user_${SLUG}` no output final) e defina `MYSQL_PASSWORD`.
   - `site/app/inc/kernel.php` e `manager/app/inc/kernel.php`: ajuste
     `DB_PASS` (credencial real — NUNCA committada), credenciais SMTP
     (`mail_from_mail`, `mail_from_host`, `mail_from_port`, `mail_from_user`,
     `mail_from_pwd`). Os valores de marca (`mail_from_name`, `cTitle`,
     `cAppKey`, etc.) foram preenchidos pelo script.
   - Copie `logo.svg` e `favicon.svg` da marca para
     `site/public_html/assets/img/` e `manager/public_html/assets/img/`
     (sobrescrevem os defaults do vendor).
   - Preencha as constantes dos gateways de pagamento (`MP_ACCESS_TOKEN`,
     `PAGBANK_TOKEN`, `INFINITEPAY_HANDLE`, etc.) — são fail-closed até
     preenchidas.
4. Suba o stack:
   ```bash
   docker compose -f docker/docker-compose.yml up -d --build
   ```
5. Rode as migrations:
   ```bash
   docker exec app php /var/www/app/site/cgi-bin/run_migrations.php
   ```
6. Commite (nova marca instanciada) e faça deploy.

### O que o script substitui automaticamente

| Grupo | Target | Descrição |
|-------|--------|-----------|
| 1. kernel.php (site + manager) | 8 constantes por env | `mail_from_name`, `cAppKey`, `cTitle`, `ALLOWED_HOSTS`, `SITE_CANONICAL_URL` / `MANAGER_CANONICAL_URL`, `REDIS_PREFIX`, `KAFKA_TOPIC_EMAIL`, `KAFKA_CONSUMER_GROUP` — derivadas do `--name` e das URLs |
| 2. Cor de marca | `site/` e `manager/` `main.css` + 2 templates de email | `#2e2b6e` e `#5855b0` (case-insensitive) trocadas pelo `--primary-color` |
| 3. nginx | `docker/interface/default.conf` | Placeholders `__SITE_HOSTS__` e `__MANAGER_HOSTS__` substituídos pelos hosts reais da marca |
| 4. Admin seed | `migrations/002_create_table_users.sql` | `admin@example.com` e `Admin` trocados se `--admin-email` / `--admin-name` forem passados |

### O que é manual (segredos/UI da marca)

| Item | Onde | Nota |
|------|------|------|
| `DB_PASS` | `site/` e `manager/` `kernel.php` | Placeholder `SUA_SENHA_AQUI` — preencher com credencial real |
| SMTP creds | `site/` e `manager/` `kernel.php` | `mail_from_mail`, `mail_from_host`, `mail_from_port`, `mail_from_user`, `mail_from_pwd` |
| Logo e favicon | `site/` e `manager/` `public_html/assets/img/` | Copiar `logo.svg` + `favicon.svg` sobre os defaults |
| Gateways de pagamento | `site/` e `manager/` `kernel.php` | `MP_ACCESS_TOKEN`, `PAGBANK_TOKEN`, `INFINITEPAY_HANDLE` — fail-closed até preenchidas |
| `MYSQL_DATABASE`, `MYSQL_USER` | `docker/.env` | O script sugere `db_${SLUG}` e `user_${SLUG}` no output; replicar no `.env` |

O script nunca inventa segredos: `DB_PASS`, `mail_from_pwd` e demais ficam como
placeholder. `DB_NAME` e `DB_USER` no kernel não são substituídos — o operador
decide os valores.

**Idempotência:** rodar o script 2x com a mesma marca é seguro — o `sed` casa o
placeholder literal, que some após o primeiro run. Em `kernel.php` o `cp`
example -> kernel descarta o estado anterior. Re-run com `--admin-email` ou
`--admin-name` diferentes não atualiza a migration 002 (o placeholder original
já foi substituído); para trocar o seed, faça
`git checkout -- migrations/002_create_table_users.sql` e rode de novo.

**Validador:** ao final, o script verifica se há residuais da marca original,
`__SITE_HOSTS__` / `__MANAGER_HOSTS__` ou `#2e2b6e` / `#5855b0` nos targets.
Se encontrar, aborta com `exit 2`. Use `--no-validate` apenas para debug.

## Setup (desenvolvendo no produto base)

1. Copie os exemplos de kernel em cada ambiente:
   ```bash
   cp site/app/inc/kernel.php.example site/app/inc/kernel.php
   cp manager/app/inc/kernel.php.example manager/app/inc/kernel.php
   ```
   Preencha com credenciais de desenvolvimento (o default
   `ALLOWED_HOSTS="localhost"` já responde em `http://localhost/` sem tocar
   `/etc/hosts`).
2. Copie as variáveis do Docker:
   ```bash
   cp docker/.env.example docker/.env
   ```
   Ajuste `MYSQL_PASSWORD` conforme necessário (os demais defaults batem
   com os `DB_NAME`/`DB_USER` do kernel).
3. Suba o stack:
   ```bash
   docker compose -f docker/docker-compose.yml up -d --build
   ```
4. Habilite os git hooks:
   ```bash
   git config core.hooksPath .githooks
   ```
   `pre-commit`: PHPStan nos dois ambientes + `bin/check-shared-sync.sh`
   (bloqueia commit se `lib/` ou `model/` divergirem entre site e manager).
   `pre-push`: PHPUnit nos dois ambientes no container; se o container `app`
   estiver fora, avisa e pula (não bloqueia).
5. Instale as dependências Composer (vive em `app/inc/lib/` de cada env,
   não na raiz do repo):
   ```bash
   composer install -d site/app/inc/lib
   composer install -d manager/app/inc/lib
   ```

## Comandos

```bash
# PHPStan (nível 4), por ambiente — roda no host
cd site && php app/inc/lib/vendor/bin/phpstan analyse
cd manager && php app/inc/lib/vendor/bin/phpstan analyse

# PHPUnit — roda dentro do container (precisa de kernel.php + DB viva)
# O -c explícito e obrigatório
docker exec app php /var/www/app/site/app/inc/lib/vendor/bin/phpunit -c /var/www/app/site/phpunit.xml
docker exec app php /var/www/app/manager/app/inc/lib/vendor/bin/phpunit -c /var/www/app/manager/phpunit.xml

# Migrations (também rodam automaticamente via cron a cada 5min no container)
docker exec app php /var/www/app/site/cgi-bin/run_migrations.php

# Verificação completa pré-merge (PHPStan host + PHPUnit no container, ambos envs)
bash bin/test.sh

# Sync guard — verifica se lib/ e model/ são byte-idênticos entre site/ e manager/
bash bin/check-shared-sync.sh

# Instanciar nova marca (whitelabel)
bin/init-whitelabel.sh \
    --name "Minha Marca" \
    --site-url "https://minhamarca.com.br" \
    --manager-url "https://manager.minhamarca.com.br" \
    --primary-color "#1a73e8" \
    --admin-email "admin@minhamarca.com.br" \
    --admin-name "Admin" \
    --site-hosts "minhamarca.com.br,www.minhamarca.com.br" \
    --manager-hosts "manager.minhamarca.com.br" \
    --force \
    --no-validate \
    --root /caminho/do/repo
# Flags obrigatórias: --name, --site-url, --manager-url
# Flags opcionais: --primary-color (default #2e2b6e), --admin-email, --admin-name,
#   --site-hosts, --manager-hosts, --force, --no-validate, --root
```

## Regras de arquitetura (não-óbvias)

- **`lib/` e `model/` são cópias idênticas.** Toda correção no framework
  compartilhado é aplicada nos dois lugares (`manager/app/inc/` e
  `site/app/inc/`). O `bin/check-shared-sync.sh` roda no pre-commit e
  bloqueia o commit se divergirem. Controllers, rotas, views e `kernel.php`
  são por ambiente e podem diferir.
- **Uma única transação global por request.** `localPDO` abre uma
  transação no início do request; `basic_redir($url)` faz commit,
  `basic_redir($url, rollback: true)` desfaz, e `localPDO::__destruct()`
  faz rollback de segurança se não houve redirect explícito. Controllers
  não chamam `commit()`/`rollback()` manualmente.
- **Soft-delete universal.** `active = 'yes'/'no'`. Nunca `DELETE FROM`.
- **O dispatcher só trata GET e POST.** PUT/PATCH/DELETE são ignorados
  silenciosamente.
- **CSRF com 10s de graça** em toda rota POST (inclusive logout) — tokens
  seguem válidos por 10s após o primeiro uso para sobreviver a F5 pós-submit.
  `checkout_controller::finalize()` tem uma guarda extra de duplo-submit por
  cima disso: reenviar o mesmo token dentro da janela de graça cria pedido +
  cobrança PIX duplicados (a rota não é idempotente), então tokens já
  finalizados ficam registrados na sessão e um reenvio redireciona pro
  pagamento já criado em vez de gerar outro.
- **Rate limit por IP nas rotas públicas mais sensíveis.**
  `checkout_controller::finalize()` (decrementa estoque e cria cobrança PIX
  real) usa `check_and_increment_rate_limit()` — 8 tentativas por IP a cada
  60s. Mesmo mecanismo em `checkout_controller::cep()` (30/60s) e
  `track_order_controller::search()` (5/300s): Redis quando disponível,
  fallback em filesystem (`flock`) quando não — só é fail-open se
  nem Redis nem filesystem estiverem disponíveis.
- **Job de expiração devolve estoque, e o webhook tem guarda de corrida
  simétrica.** `site/cgi-bin/expire_orders.php` (cron a cada 5min, mesmo
  padrão do `dispatch_emails.php`) chama `OrderExpirer::expireDueOrders()`
  para marcar `aguardando_pagamento` vencidos como `expirado` e devolver ao
  estoque as unidades reservadas no checkout — sem isso, carrinho
  abandonado prende estoque pra sempre ("estoque fantasma"). O webhook de
  pagamento só grava `pago` se `status <> 'expirado'` (UPDATE condicional);
  se o pedido já expirou entre o pagamento e a confirmação do PSP, o
  webhook não sobrescreve — o estoque já devolvido pode já ter sido vendido
  a outro comprador, então o caso vira log para reconciliação manual em vez
  de overselling silencioso.
- **Webhook do InfinitePay é público por design.** O PSP não publica
  assinatura de webhook — `InfinitePayGateway::verifyWebhook()` retorna
  `true` de propósito e NÃO é a camada de autenticação. A autenticidade vem
  da reconfirmação `confirmPayment()` (POST /payment_check, fail-closed) que
  o `webhook_controller` executa antes de qualquer escrita de negócio; antes
  dela só há leituras + rate limit por token de pedido (Redis) + guarda de
  replay via UNIQUE em `pix_charges.transaction_nsu` (migration 010). Nenhum
  efeito irreversível (estoque, pedido, status) ocorre antes da
  reconfirmação — estoque é movimentado só no checkout, nunca no webhook.
  Não "consertar" o `return true` adicionando validação de assinatura que o
  PSP não oferece.
