#!/bin/bash
set -e

ENABLE_CRON="${ENABLE_CRON:-false}"

echo "Executando composer install nas pastas lib..."

# Instalar dependências do composer para site
if [ -f "/var/www/dotly/site/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do site..."
    cd /var/www/dotly/site/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ -f "/var/www/dotly/manager/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do manager..."
    cd /var/www/dotly/manager/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

echo "Composer install concluído!"

# Garantir permissão de escrita nos diretórios de upload
chown -R www-data:www-data /var/www/dotly/manager/public_html/assets/upload/ /var/www/dotly/site/public_html/assets/upload/ 2>/dev/null || true
chmod 775 /var/www/dotly/manager/public_html/assets/upload/ /var/www/dotly/site/public_html/assets/upload/ 2>/dev/null || true

# Host HTTP de cada ambiente derivado do proprio kernel.php (1o item de
# ALLOWED_HOSTS). Necessario porque os scripts CLI (workers e dispatch_emails)
# precisam de um HTTP_HOST que passe na validacao anti-Host-Injection do kernel
# E gere links de e-mail (cFrontend) com o dominio real. Brand-agnostic.
SITE_HTTP_HOST=$(php -r '$_SERVER["HTTP_HOST"]=""; require "/var/www/dotly/site/app/inc/kernel.php"; $h=explode(",", constant("ALLOWED_HOSTS")); echo trim($h[0]);' 2>/dev/null || true)
MANAGER_HTTP_HOST=$(php -r '$_SERVER["HTTP_HOST"]=""; require "/var/www/dotly/manager/app/inc/kernel.php"; $h=explode(",", constant("ALLOWED_HOSTS")); echo trim($h[0]);' 2>/dev/null || true)
echo "[entrypoint] SITE_HTTP_HOST=${SITE_HTTP_HOST:-<vazio>} MANAGER_HTTP_HOST=${MANAGER_HTTP_HOST:-<vazio>}"

# Instalar crontab e iniciar cron apenas no container app
if [ "$ENABLE_CRON" = "true" ]; then
    if [ -f "/etc/cron.txt" ]; then
        echo "Instalando crontab..."
        # cron NAO herda o env do container; dispatch_emails.php (site) precisa do
        # CLI_HTTP_HOST. Injeta como variavel de ambiente da crontab. Inofensivo
        # para run_migrations.php, que nao le HTTP_HOST.
        { echo "CLI_HTTP_HOST=${SITE_HTTP_HOST}"; cat /etc/cron.txt; } | crontab - || true
    fi

    echo "Iniciando cron..."
    service cron start || cron || true
else
    echo "Cron desabilitado para este container."
fi

if [ "$#" -gt 0 ]; then
    echo "Executando comando customizado: $*"
    exec "$@"
fi

# Iniciar PHP-FPM em background
echo "Iniciando PHP-FPM..."
php-fpm -D

# Iniciar Kafka Email Workers em background, com supervisão simples:
# se o worker morrer, reinicia sozinho em vez de parar de enviar e-mail
# em silêncio.
echo "Iniciando Kafka Email Workers (com supervisão)..."
supervise_worker() {
    local script="$1" log="$2" host="$3"
    local backoff=5
    local started
    while true; do
        started=$(date +%s)
        CLI_HTTP_HOST="$host" php "$script" >> "$log" 2>&1
        local exit_code=$?
        # Rodou por mais de 1 minuto antes de morrer: nao era um crash-loop,
        # volta o backoff ao minimo em vez de deixar acumulado.
        if [ $(( $(date +%s) - started )) -ge 60 ]; then
            backoff=5
        fi
        echo "[entrypoint] worker $script morreu (exit $exit_code); reiniciando em ${backoff}s" >> "$log"
        sleep "$backoff"
        backoff=$(( backoff < 60 ? backoff * 2 : 60 ))
    done
}
# DOIS workers, DUAS faixas de e-mail — ambos necessarios (nao remover):
#   - SITE worker  -> topico do site. Recebe TODO e-mail de PEDIDO: order_paid e
#     order_shipped passam pela email_queue (DB, tabela unica) e SO o dispatcher
#     do site (cron dispatch_emails.php) produz no topico do site. Por isso ate
#     o order_shipped disparado no manager e entregue por este worker.
#   - MANAGER worker -> topico do manager. Recebe os e-mails de ADMIN que o
#     config_controller produz DIRETO no Kafka (credenciais de novo usuario,
#     redefinicao de senha) — nao passam pela email_queue.
# Remover o worker do manager quebraria (em silencio) credencial/reset de admin.
supervise_worker /var/www/dotly/manager/cgi-bin/kafka_email_worker.php /var/log/kafka_email_worker_manager.log "$MANAGER_HTTP_HOST" &
supervise_worker /var/www/dotly/site/cgi-bin/kafka_email_worker.php /var/log/kafka_email_worker_site.log "$SITE_HTTP_HOST" &
echo "Kafka Email Workers supervisionados iniciados"

# Iniciar Nginx (criar diretório de logs se não existir)
echo "Iniciando Nginx..."
mkdir -p /var/log/nginx
exec nginx -g "daemon off;"
