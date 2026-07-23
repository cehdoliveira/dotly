# Dotly

Whitelabel PHP 8.4 + MySQL 8.0 sobre um framework próprio — **LEGGO**
(não é Laravel/Symfony) — rodando em Docker. Um único codebase serve dois
ambientes:

- **site** — `dotly.local` — frontend público.
- **manager** — `manager.dotly.local` — painel administrativo.

Os dois ambientes diferem apenas por `kernel.php`, controllers, rotas e views.
O framework compartilhado (`app/inc/lib/` e `app/inc/model/`) é idêntico
byte a byte entre `manager/` e `site/`.

## Setup

```bash
# 1. kernel.php é gitignored — copie o exemplo em cada ambiente
cp manager/app/inc/kernel.php.example manager/app/inc/kernel.php
cp site/app/inc/kernel.php.example site/app/inc/kernel.php
# edite os dois kernel.php com as credenciais/constantes locais

# 2. hosts locais — adicione ao /etc/hosts (ou equivalente)
#   127.0.0.1 dotly.local
#   127.0.0.1 manager.dotly.local

# 3. suba o stack
docker compose -f docker/docker-compose.yml up -d --build

# 4. habilite os git hooks (pre-commit: PHPStan + shared-sync; pre-push: PHPUnit)
git config core.hooksPath .githooks
```

Composer vive em `app/inc/lib/` de cada ambiente, **não** na raiz do repo.

## Comandos

```bash
# PHPStan (nível 4), por ambiente — roda no host
cd manager && php app/inc/lib/vendor/bin/phpstan analyse
cd site    && php app/inc/lib/vendor/bin/phpstan analyse

# PHPUnit — roda dentro do container, precisa de kernel.php + DB viva
# (bin/test.sh tem um bug conhecido de working directory; use o comando
# completo abaixo, que passa -c explícito)
docker exec -e HTTP_HOST=localhost dotly php /var/www/dotly/site/app/inc/lib/vendor/bin/phpunit -c /var/www/dotly/site/phpunit.xml
docker exec -e HTTP_HOST=localhost dotly php /var/www/dotly/manager/app/inc/lib/vendor/bin/phpunit -c /var/www/dotly/manager/phpunit.xml

# Migrations (também rodam automaticamente via cron a cada 5min no container)
docker exec dotly php /var/www/dotly/site/cgi-bin/run_migrations.php

# Instanciar uma nova marca (whitelabel) a partir dos .example
bin/init-whitelabel.sh --name "Marca" --site-url "https://x.com" --manager-url "https://manager.x.com"
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
  60s. Mesmo mecanismo já usado em `checkout_controller::cep()` (30/60s) e
  `track_order_controller::search()` (5/300s): usa Redis quando disponível,
  cai para fallback em filesystem (`flock`) quando não — só é fail-open se
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
  replay via UNIQUE em `pix_charges.transaction_nsu` (migration 042). Nenhum
  efeito irreversível (estoque, pedido, status) ocorre antes da
  reconfirmação — estoque é movimentado só no checkout, nunca no webhook.
  Não "consertar" o `return true` adicionando validação de assinatura que o
  PSP não oferece.
