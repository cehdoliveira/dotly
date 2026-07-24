#!/bin/bash
set -e

ENABLE_CRON="${ENABLE_CRON:-false}"

echo "Executando composer install nas pastas lib..."

# Instalar dependências do composer para site
if [ -f "/var/www/app/site/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do site..."
    cd /var/www/app/site/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ -f "/var/www/app/manager/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do manager..."
    cd /var/www/app/manager/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

echo "Composer install concluído!"

# Garantir permissão de escrita nos diretórios de upload
chown -R www-data:www-data /var/www/app/manager/public_html/assets/upload/ /var/www/app/site/public_html/assets/upload/ 2>/dev/null || true
chmod 775 /var/www/app/manager/public_html/assets/upload/ /var/www/app/site/public_html/assets/upload/ 2>/dev/null || true

# Host HTTP de cada ambiente derivado do proprio kernel.php (1o item de
# ALLOWED_HOSTS). Necessario porque os scripts CLI (workers e dispatch_emails)
# precisam de um HTTP_HOST que passe na validacao anti-Host-Injection do kernel
# E gere links de e-mail (cFrontend) com o dominio real. Brand-agnostic.
SITE_HTTP_HOST=$(php -r '$_SERVER["HTTP_HOST"]=""; require "/var/www/app/site/app/inc/kernel.php"; $h=explode(",", constant("ALLOWED_HOSTS")); echo trim($h[0]);' 2>/dev/null || true)
MANAGER_HTTP_HOST=$(php -r '$_SERVER["HTTP_HOST"]=""; require "/var/www/app/manager/app/inc/kernel.php"; $h=explode(",", constant("ALLOWED_HOSTS")); echo trim($h[0]);' 2>/dev/null || true)
echo "[entrypoint] SITE_HTTP_HOST=${SITE_HTTP_HOST:-<vazio>} MANAGER_HTTP_HOST=${MANAGER_HTTP_HOST:-<vazio>}"

# Substituir placeholders __SITE_HOSTS__ / __MANAGER_HOSTS__ no nginx config
# (em /etc/nginx/sites-available/app.conf) pelos hosts da marca derivados
# do kernel.php (primeiro item de ALLOWED_HOSTS, mesmo pattern das linhas
# 31-32). Necessario porque o default.conf e COPYado para a imagem
# (Dockerfile:36) e nao e bind-mounted; sem isto, o container app nasceria
# sem hostname da marca no server_name. Mantem o default.conf brand-neutral
# (placeholders preservados) — init-whitelabel.sh continua a substitui-los
# em commit para novas marcas; este bloco resolve o estado pós-build em
# runtime com os hosts derivados do kernel. Limitacao: pega so o primeiro
# host de ALLOWED_HOSTS (so um server_name por ambiente); suporte a multiplos
# hosts pode ser expandido aqui se necessario.
NGINX_APP_CONF="/etc/nginx/sites-available/app.conf"
if [ -f "$NGINX_APP_CONF" ]; then
    if [ -n "$SITE_HTTP_HOST" ] && [ -n "$MANAGER_HTTP_HOST" ]; then
        sed -i "s|__SITE_HOSTS__|${SITE_HTTP_HOST}|g" "$NGINX_APP_CONF"
        sed -i "s|__MANAGER_HOSTS__|${MANAGER_HTTP_HOST}|g" "$NGINX_APP_CONF"
        echo "[entrypoint] nginx server_name populado: site=${SITE_HTTP_HOST} manager=${MANAGER_HTTP_HOST}"
    else
        echo "[entrypoint] AVISO: SITE_HTTP_HOST/MANAGER_HTTP_HOST vazios — placeholders __*_HOSTS__ residuais em $NGINX_APP_CONF" >&2
    fi
    # Fail-fast se ainda ha placeholders residuais — server_name com hostname
    # __SITE_HOSTS__ lamber seria invalido e ACL ALLOWED_HOSTS do kernel
    # rejeitaria qualquer request HTTP real.
    if grep -q "__SITE_HOSTS__\|__MANAGER_HOSTS__" "$NGINX_APP_CONF"; then
        echo "[entrypoint] ERRO: placeholders __SITE_HOSTS__/__MANAGER_HOSTS__ residual em $NGINX_APP_CONF apos tentativa de substituicao" >&2
        exit 1
    fi
fi

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
supervise_worker /var/www/app/manager/cgi-bin/kafka_email_worker.php /var/log/kafka_email_worker_manager.log "$MANAGER_HTTP_HOST" &
supervise_worker /var/www/app/site/cgi-bin/kafka_email_worker.php /var/log/kafka_email_worker_site.log "$SITE_HTTP_HOST" &
echo "Kafka Email Workers supervisionados iniciados"

# Iniciar Nginx (criar diretório de logs se não existir)
echo "Iniciando Nginx..."
mkdir -p /var/log/nginx
exec nginx -g "daemon off;"
