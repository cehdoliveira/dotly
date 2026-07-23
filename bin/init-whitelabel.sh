#!/bin/bash
# Protótipo (spike) de instanciação de marca nova.
#
# Le nome da marca + URLs de producao (site e manager), gera os DOIS
# kernel.php (site/app/inc e manager/app/inc) a partir dos *.example
# preenchendo os pontos de toque por-marca. Inventario completo em
# toda constante nao listada la como "Marca (script)"
# permanece com o valor default do example.
#
# NUNCA inventa segredos: DB_PASS, mail_from_pwd, mail_from_mail e
# mail_from_user ficam com o placeholder do example e sao avisados no final.
# APP_VERSION nao sofre substituicao — ja vem correto do example (plano 025).
#
# Uso:
#   bin/init-whitelabel.sh --name "Minha Marca" --site-url "https://minhamarca.com.br" \
#       --manager-url "https://manager.minhamarca.com.br"
#   bin/init-whitelabel.sh                 # modo interativo (prompts)
#
# Flags opcionais:
#   --root <dir>   raiz do repo (default: raiz do git atual)
#   --force        sobrescreve kernel.php existente (default: aborta com erro)
#   --site-hosts "a.com,www.a.com"      hosts extras p/ ALLOWED_HOSTS do site (default: host da --site-url)
#   --manager-hosts "m.a.com"           idem para o manager
set -e

ROOT=""
BRAND_NAME=""
SITE_URL=""
MANAGER_URL=""
SITE_HOSTS=""
MANAGER_HOSTS=""
FORCE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --name) BRAND_NAME="$2"; shift 2 ;;
        --site-url) SITE_URL="$2"; shift 2 ;;
        --manager-url) MANAGER_URL="$2"; shift 2 ;;
        --root) ROOT="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --site-hosts) SITE_HOSTS="$2"; shift 2 ;;
        --manager-hosts) MANAGER_HOSTS="$2"; shift 2 ;;
        *) echo "Flag desconhecida: $1" >&2; exit 1 ;;
    esac
done

if [ -z "$ROOT" ]; then
    ROOT="$(git rev-parse --show-toplevel)"
fi

if [ -z "$BRAND_NAME" ]; then
    read -rp "Nome da marca (ex.: Minha Marca): " BRAND_NAME
fi
if [ -z "$SITE_URL" ]; then
    read -rp "URL de producao do site (ex.: https://minhamarca.com.br): " SITE_URL
fi
if [ -z "$MANAGER_URL" ]; then
    read -rp "URL de producao do manager (ex.: https://manager.minhamarca.com.br): " MANAGER_URL
fi

if [ -z "$BRAND_NAME" ] || [ -z "$SITE_URL" ] || [ -z "$MANAGER_URL" ]; then
    echo "Nome da marca, URL do site e URL do manager sao obrigatorios." >&2
    exit 1
fi

SITE_EXAMPLE="$ROOT/site/app/inc/kernel.php.example"
MANAGER_EXAMPLE="$ROOT/manager/app/inc/kernel.php.example"
SITE_KERNEL="$ROOT/site/app/inc/kernel.php"
MANAGER_KERNEL="$ROOT/manager/app/inc/kernel.php"

for f in "$SITE_EXAMPLE" "$MANAGER_EXAMPLE"; do
    if [ ! -f "$f" ]; then
        echo "Arquivo nao encontrado: $f" >&2
        exit 1
    fi
done

if [ "$FORCE" -ne 1 ]; then
    for f in "$SITE_KERNEL" "$MANAGER_KERNEL"; do
        if [ -f "$f" ]; then
            echo "$f ja existe — abortando para nao sobrescrever silenciosamente." >&2
            echo "Remova o arquivo ou rode novamente com --force." >&2
            exit 1
        fi
    done
fi

# Slug: minusculas, sem acento, so [a-z0-9_], sem underscore duplicado/nas pontas.
slugify() {
    printf '%s' "$1" \
        | iconv -f utf-8 -t ascii//TRANSLIT 2>/dev/null \
        | tr '[:upper:]' '[:lower:]' \
        | sed -e 's/[^a-z0-9]/_/g' -e 's/_\+/_/g' -e 's/^_//' -e 's/_$//'
}

# Escapa \, & e # para uso seguro como substituicao em `sed -e "s#...#...#"`.
escape_repl() {
    printf '%s' "$1" | sed -e 's/[\&#]/\\&/g'
}

# Extrai host (sem esquema/porta/caminho) de uma URL.
host_of() {
    printf '%s' "$1" | sed -E 's#^[a-zA-Z]+://##; s#/.*$##'
}

SLUG="$(slugify "$BRAND_NAME")"
if [ -z "$SLUG" ]; then
    echo "Nao foi possivel derivar um slug do nome da marca '$BRAND_NAME'." >&2
    exit 1
fi

SITE_HOST="$(host_of "$SITE_URL")"
MANAGER_HOST="$(host_of "$MANAGER_URL")"

if [ -z "$SITE_HOSTS" ]; then SITE_HOSTS="$SITE_HOST"; fi
if [ -z "$MANAGER_HOSTS" ]; then MANAGER_HOSTS="$MANAGER_HOST"; fi
SITE_HOSTS="$(printf '%s' "$SITE_HOSTS" | tr -d '[:space:]')"
MANAGER_HOSTS="$(printf '%s' "$MANAGER_HOSTS" | tr -d '[:space:]')"

E_BRAND_NAME="$(escape_repl "$BRAND_NAME")"
E_SLUG="$(escape_repl "$SLUG")"
E_SITE_URL="$(escape_repl "$SITE_URL")"
E_MANAGER_URL="$(escape_repl "$MANAGER_URL")"
E_SITE_HOSTS="$(escape_repl "$SITE_HOSTS")"
E_MANAGER_HOSTS="$(escape_repl "$MANAGER_HOSTS")"

cp "$SITE_EXAMPLE" "$SITE_KERNEL"
sed -i \
    -e "s#define(\"mail_from_name\", \"dotly\");#define(\"mail_from_name\", \"${E_BRAND_NAME}\");#" \
    -e "s#define(\"cAppKey\", \"dotly_site_session\");#define(\"cAppKey\", \"${E_SLUG}_site_session\");#" \
    -e "s#define(\"cTitle\", \"dotly\");#define(\"cTitle\", \"${E_BRAND_NAME}\");#" \
    -e "s#define(\"ALLOWED_HOSTS\", \"dotly.local\");#define(\"ALLOWED_HOSTS\", \"${E_SITE_HOSTS}\");#" \
    -e "s#define(\"SITE_CANONICAL_URL\", \"http://dotly.local\");#define(\"SITE_CANONICAL_URL\", \"${E_SITE_URL}\");#" \
    -e "s#define(\"REDIS_PREFIX\", \"dotly:site:\");#define(\"REDIS_PREFIX\", \"${E_SLUG}:site:\");#" \
    -e "s#define(\"KAFKA_TOPIC_EMAIL\", \"dotly_site_emails\");#define(\"KAFKA_TOPIC_EMAIL\", \"${E_SLUG}_site_emails\");#" \
    -e "s#define(\"KAFKA_CONSUMER_GROUP\", \"dotly-site-email-worker-group\");#define(\"KAFKA_CONSUMER_GROUP\", \"${E_SLUG}-site-email-worker-group\");#" \
    "$SITE_KERNEL"

cp "$MANAGER_EXAMPLE" "$MANAGER_KERNEL"
sed -i \
    -e "s#define(\"mail_from_name\", \"dotly Manager\");#define(\"mail_from_name\", \"${E_BRAND_NAME} Manager\");#" \
    -e "s#define(\"cAppKey\", \"dotly_manager_session\");#define(\"cAppKey\", \"${E_SLUG}_manager_session\");#" \
    -e "s#define(\"cTitle\", \"dotly Manager\");#define(\"cTitle\", \"${E_BRAND_NAME} Manager\");#" \
    -e "s#define(\"ALLOWED_HOSTS\", \"manager.dotly.local\");#define(\"ALLOWED_HOSTS\", \"${E_MANAGER_HOSTS}\");#" \
    -e "s#define(\"MANAGER_CANONICAL_URL\", \"http://manager.dotly.local\");#define(\"MANAGER_CANONICAL_URL\", \"${E_MANAGER_URL}\");#" \
    -e "s#define(\"REDIS_PREFIX\", \"dotly:manager:\");#define(\"REDIS_PREFIX\", \"${E_SLUG}:manager:\");#" \
    -e "s#define(\"KAFKA_TOPIC_EMAIL\", \"dotly_manager_emails\");#define(\"KAFKA_TOPIC_EMAIL\", \"${E_SLUG}_manager_emails\");#" \
    -e "s#define(\"KAFKA_CONSUMER_GROUP\", \"dotly-manager-email-worker-group\");#define(\"KAFKA_CONSUMER_GROUP\", \"${E_SLUG}-manager-email-worker-group\");#" \
    "$MANAGER_KERNEL"

echo "Gerado: $SITE_KERNEL"
echo "Gerado: $MANAGER_KERNEL"
echo
echo "ATENCAO — preencha manualmente antes de subir para producao:"
echo "  - DB_HOST/DB_NAME/DB_USER/DB_PASS (devem bater com docker/.env)"
echo "  - mail_from_mail, mail_from_user, mail_from_pwd (credenciais SMTP reais)"
echo "  - logo.svg e favicon.svg em site/public_html/assets/img/ e manager/public_html/assets/img/ (e tokens de cor em assets/css/main.css — ver README > Personalização)"
echo "  - cor primaria da marca (CSS de site/ e manager/)"
