#!/bin/bash
# Falha se arquivos compartilhados entre manager/ e site/ divergirem.
# app/inc/lib e app/inc/model DEVEM ser identicos entre os dois ambientes.
# controller/, public_html/index.php, urls.php, kernel.php e ui/ NAO sao
# compartilhados (divergencia intencional) e portanto nao sao checados aqui.
#
# app/inc/main.php, app/inc/lists.php, cgi-bin/run_migrations.php e
# cgi-bin/kafka_email_worker.php tambem DEVEM ser identicos entre os dois
# ambientes: sao bootstrap/cron per-ambiente na estrutura de pastas, mas o
# conteudo e compartilhado, e nao moram em app/inc/lib nem app/inc/model.
#
# vendor/ e ignorado (gitignored / symlinked, fora do versionamento).
# tests/ e ignorado (os bootstraps diferem apenas por HTTP_HOST).
set -e

# Roda a partir da raiz do repositorio, independente de onde foi invocado.
cd "$(git rev-parse --show-toplevel)"

status=0
for sub in app/inc/lib app/inc/model; do
    if ! diff -rq --exclude=vendor --exclude=tests "manager/$sub" "site/$sub" > /dev/null; then
        echo "DRIFT em $sub entre manager/ e site/:"
        diff -rq --exclude=vendor --exclude=tests "manager/$sub" "site/$sub" || true
        status=1
    fi
done

# Arquivos compartilhados que NAO moram em app/inc/lib nem app/inc/model, mas
# cujo conteudo DEVE ser identico entre os dois ambientes. Sem esta checagem,
# um fix aplicado numa copia so (ex.: o timeout de sessao em main.php) passa no
# CI e vai pra producao valendo em um ambiente e nao no outro.
for file in app/inc/main.php app/inc/lists.php cgi-bin/run_migrations.php cgi-bin/kafka_email_worker.php; do
    if ! diff -q "manager/$file" "site/$file" > /dev/null; then
        echo "DRIFT em $file entre manager/ e site/:"
        diff -u "manager/$file" "site/$file" || true
        status=1
    fi
done
exit $status
