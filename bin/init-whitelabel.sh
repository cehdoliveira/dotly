#!/bin/bash
# Gerador de instancia whitelabel a partir dos .example e placeholders.
#
# Substitui no repo:
#   1. kernel.php (site + manager) — 8 constantes de brand/infra (pares de sed)
#   2. Cor de marca em 2 main.css + 2 templates de email (#2e2b6e/#5855b0 -> --primary-color)
#   3. Placeholders __SITE_HOSTS__/__MANAGER_HOSTS__ no nginx default.conf
#   4. Admin seed de migrations/002 (email + name) — se --admin-email/--admin-name
#
# NUNCA inventa segredos: DB_PASS, mail_from_pwd ficam como placeholder do
# kernel e sao avisados no final. DB_NAME/DB_USER NAO sao substituidos —
# script sugere db_${SLUG}/user_${SLUG} no output para o operador replicar
# no docker/.env manualmente.
#
# Idempotente: rodar 2x NAO double-substitui (sed casa o placeholder literal,
# que some apos primeiro run). Atalho: --force sobrescreve kernel.php existente.
#
# Validador integrado: aborta exit 2 se encontrar "dotly"/"_HOSTS__"/"#2e2b6e"
# residual em qualquer target apos as substituicoes.
#
# Uso:
#   bin/init-whitelabel.sh --name "Minha Marca" --site-url "https://minhamarca.com.br" \
#       --manager-url "https://manager.minhamarca.com.br" \
#       --primary-color "#1a73e8" --admin-email "admin@minhamarca.com.br"
#   bin/init-whitelabel.sh                 # modo interativo (prompts)
#
# Flags opcionais:
#   --root <dir>          raiz do repo (default: raiz do git atual)
#   --force               sobrescreve kernel.php existente (default: aborta com erro)
#   --site-hosts "a,b"    hosts extras p/ ALLOWED_HOSTS do site (default: host da --site-url)
#   --manager-hosts "m.a" idem para o manager
#   --primary-color "#XX" cor de marca (default: #2e2b6e — manter brand atual)
#   --admin-email "x@y"   login admin inicial no seed da migration (default: admin@example.com)
#   --admin-name "Nome"   nome exibido do admin no seed (default: Admin)
#   --no-validate         pula validacao residual (nao recomendado — apenas p/ debug)
set -e

ROOT=""
BRAND_NAME=""
SITE_URL=""
MANAGER_URL=""
SITE_HOSTS=""
MANAGER_HOSTS=""
FORCE=0
PRIMARY_COLOR=""
ADMIN_EMAIL=""
ADMIN_NAME=""
NO_VALIDATE=""

while [ $# -gt 0 ]; do
    case "$1" in
        --name) BRAND_NAME="$2"; shift 2 ;;
        --site-url) SITE_URL="$2"; shift 2 ;;
        --manager-url) MANAGER_URL="$2"; shift 2 ;;
        --root) ROOT="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --site-hosts) SITE_HOSTS="$2"; shift 2 ;;
        --manager-hosts) MANAGER_HOSTS="$2"; shift 2 ;;
        --primary-color) PRIMARY_COLOR="$2"; shift 2 ;;
        --admin-email)   ADMIN_EMAIL="$2";   shift 2 ;;
        --admin-name)    ADMIN_NAME="$2";    shift 2 ;;
        --no-validate) NO_VALIDATE=1; shift ;;
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

# Defaults derivados do --name/--site-url se flags opcionais faltarem
if [ -z "$PRIMARY_COLOR" ]; then PRIMARY_COLOR="#2e2b6e"; fi   # brand color original (vendor)
if [ -z "$ADMIN_EMAIL" ]; then ADMIN_EMAIL="admin@example.com"; fi   # generico; operador customiza
if [ -z "$ADMIN_NAME" ]; then ADMIN_NAME="Admin"; fi             # generico brand-neutral

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
    -e "s#define(\"mail_from_name\", \"App\");#define(\"mail_from_name\", \"${E_BRAND_NAME}\");#" \
    -e "s#define(\"cAppKey\", \"app_site_session\");#define(\"cAppKey\", \"${E_SLUG}_site_session\");#" \
    -e "s#define(\"cTitle\", \"App\");#define(\"cTitle\", \"${E_BRAND_NAME}\");#" \
    -e "s#define(\"ALLOWED_HOSTS\", \"localhost\");#define(\"ALLOWED_HOSTS\", \"${E_SITE_HOSTS}\");#" \
    -e "s#define(\"SITE_CANONICAL_URL\", \"http://localhost\");#define(\"SITE_CANONICAL_URL\", \"${E_SITE_URL}\");#" \
    -e "s#define(\"REDIS_PREFIX\", \"app:site:\");#define(\"REDIS_PREFIX\", \"${E_SLUG}:site:\");#" \
    -e "s#define(\"KAFKA_TOPIC_EMAIL\", \"app_site_emails\");#define(\"KAFKA_TOPIC_EMAIL\", \"${E_SLUG}_site_emails\");#" \
    -e "s#define(\"KAFKA_CONSUMER_GROUP\", \"app-site-email-worker-group\");#define(\"KAFKA_CONSUMER_GROUP\", \"${E_SLUG}-site-email-worker-group\");#" \
    "$SITE_KERNEL"

cp "$MANAGER_EXAMPLE" "$MANAGER_KERNEL"
sed -i \
    -e "s#define(\"mail_from_name\", \"App\");#define(\"mail_from_name\", \"${E_BRAND_NAME}\");#" \
    -e "s#define(\"cAppKey\", \"app_manager_session\");#define(\"cAppKey\", \"${E_SLUG}_manager_session\");#" \
    -e "s#define(\"cTitle\", \"App\");#define(\"cTitle\", \"${E_BRAND_NAME}\");#" \
    -e "s#define(\"ALLOWED_HOSTS\", \"localhost\");#define(\"ALLOWED_HOSTS\", \"${E_MANAGER_HOSTS}\");#" \
    -e "s#define(\"MANAGER_CANONICAL_URL\", \"http://localhost\");#define(\"MANAGER_CANONICAL_URL\", \"${E_MANAGER_URL}\");#" \
    -e "s#define(\"REDIS_PREFIX\", \"app:manager:\");#define(\"REDIS_PREFIX\", \"${E_SLUG}:manager:\");#" \
    -e "s#define(\"KAFKA_TOPIC_EMAIL\", \"app_manager_emails\");#define(\"KAFKA_TOPIC_EMAIL\", \"${E_SLUG}_manager_emails\");#" \
    -e "s#define(\"KAFKA_CONSUMER_GROUP\", \"app-manager-email-worker-group\");#define(\"KAFKA_CONSUMER_GROUP\", \"${E_SLUG}-manager-email-worker-group\");#" \
    "$MANAGER_KERNEL"

# ===== Cor de marca em CSS e templates de email =====
# Substitui #2e2b6e (light/accent) e #5855b0 (dark/secondary) pela --primary-color
# em ambos os envs. Case-insensitive para tolerar #2E2B6E vs #2e2b6e.
E_PRIMARY="$(escape_repl "$PRIMARY_COLOR")"
for css in \
    "$ROOT/site/public_html/assets/css/main.css" \
    "$ROOT/manager/public_html/assets/css/main.css"
do
    [ -f "$css" ] || continue
    sed -i \
        -e "s/#2e2b6e/${E_PRIMARY}/gI" \
        -e "s/#5855b0/${E_PRIMARY}/gI" \
        "$css"
    echo "Substituido: $css"
done

for tpl in \
    "$ROOT/site/public_html/ui/mail/order_paid.php" \
    "$ROOT/manager/public_html/ui/mail/order_shipped.php"
do
    [ -f "$tpl" ] || continue
    sed -i \
        -e "s/#2e2b6e/${E_PRIMARY}/gI" \
        -e "s/#5855b0/${E_PRIMARY}/gI" \
        "$tpl"
    echo "Substituido: $tpl"
done

# ===== nginx server_name placeholders =====
NGINX_CONF="$ROOT/docker/interface/default.conf"
if [ -f "$NGINX_CONF" ]; then
    sed -i \
        -e "s/__SITE_HOSTS__/${E_SITE_HOSTS}/g" \
        -e "s/__MANAGER_HOSTS__/${E_MANAGER_HOSTS}/g" \
        "$NGINX_CONF"
    echo "Substituido: $NGINX_CONF"
fi

# ===== admin seed na migration 002 (login inicial) =====
# Estado pos-Plan-001 squash: migrations/002_create_table_users.sql seeda
#     'admin@example.com' / 'Admin' (brand-neutral defaults).
# Se o operador passa --admin-email/--admin-name, substitui in-place.
MIGRATION_USERS="$ROOT/migrations/002_create_table_users.sql"
if [ -f "$MIGRATION_USERS" ] && { [ -n "$ADMIN_EMAIL" ] || [ -n "$ADMIN_NAME" ]; }; then
    E_ADMIN_EMAIL="$(escape_repl "$ADMIN_EMAIL")"
    E_ADMIN_NAME="$(escape_repl "$ADMIN_NAME")"
    # Patterns ancorados por linha: casam SOMENTE a row do VALUES (linha
    # comecando por whitespace + 'admin@example.com'/'Admin' + virgula + EOL).
    # O comentario SQL "-- Seed brand-neutral: nome 'Admin' e email ..."
    # (adicionado pelo squash do Plan 001) NAO casa esse padrao e fica intacto,
    # preservando a doc de defaults para futuros operadores.
    sed -E -i \
        -e "s/^([[:space:]]+)'admin@example\.com',$/\\1'${E_ADMIN_EMAIL}',/" \
        -e "s/^([[:space:]]+)'Admin',$/\\1'${E_ADMIN_NAME}',/" \
        "$MIGRATION_USERS"
    echo "Substituido: $MIGRATION_USERS"
fi

# ===== Validador pos-substituicao =====
# Aborta o script com non-zero exit se encontrar:
#   - "dotly"/"Dotly" em qualquer target que o script deveria ter limpo
#   - "__SITE_HOSTS__"/"__MANAGER_HOSTS__" residual (nginx)
#   - "#2e2b6e"/"#5855b0" residual (cores)
# Isto captura substituicoes que falharam silenciosamente (ex.: pattern nao casou,
# placeholder ja substituido por run anterior e agora inconsistente).
if [ "${NO_VALIDATE:-0}" -ne 1 ]; then
    RESIDUAL=0
    residual_check() {
        local pattern="$1" files="$2" label="$3"
        local hits
        hits=$(rg -c "$pattern" $files 2>/dev/null | wc -l)
        if [ "$hits" -gt 0 ]; then
            echo "ALERTA — residual de '$label' em $hits arquivo(s):" >&2
            rg -n "$pattern" $files 2>/dev/null | head -10 >&2
            RESIDUAL=1
        fi
    }

    residual_check "dotly" \
        "$SITE_KERNEL $MANAGER_KERNEL $ROOT/site/public_html/assets/css/main.css $ROOT/manager/public_html/assets/css/main.css $ROOT/site/public_html/ui/mail/order_paid.php $ROOT/manager/public_html/ui/mail/order_shipped.php $ROOT/docker/interface/default.conf $ROOT/migrations/002_create_table_users.sql" \
        "brand do vendor (dotly/Dotly)"

    residual_check "__SITE_HOSTS__|__MANAGER_HOSTS__" \
        "$ROOT/docker/interface/default.conf" \
        "placeholder de nginx hosts (script deveria ter substituido)"

    residual_check "#2e2b6e|#5855b0|#2E2B6E|#5855B0" \
        "$ROOT/site/public_html/assets/css/main.css $ROOT/manager/public_html/assets/css/main.css $ROOT/site/public_html/ui/mail/order_paid.php $ROOT/manager/public_html/ui/mail/order_shipped.php" \
        "cores de marca do vendor (#2e2b6e/#5855b0)"

    if [ "$RESIDUAL" -ne 0 ]; then
        echo
        echo "ERRO — Esquemas ainda contem residuais do vendor. Substituicao incompleta; aborta." >&2
        exit 2
    fi
    echo "Validacao: nenhum residual do vendor encontrado."
fi

echo "Gerado: $SITE_KERNEL"
echo "Gerado: $MANAGER_KERNEL"
echo
echo "ATENCAO — preencha manualmente antes de subir para producao:"
echo "  - DB_NAME (sugestao por convencao: db_${SLUG})"
echo "  - DB_USER (sugestao por convencao: user_${SLUG})"
echo "  - DB_PASS (credencial real — NUNCA commitada)"
echo "  - DB_HOST (servidor MySQL; ver docker/.env)"
echo "  - mail_from_mail, mail_from_user, mail_from_pwd (credenciais SMTP reais — NUNCA commitadas)"
echo
echo "  - copie logo.svg e favicon.svg para:"
echo "      site/public_html/assets/img/logo.svg      + favicon.svg"
echo "      manager/public_html/assets/img/logo.svg   + favicon.svg"
echo
echo "  - revise a cor da marca: trocamos '#2e2b6e'/'#5855b0' por '$PRIMARY_COLOR'"
echo "    nos CSS e templates de e-mail; verifique contraste sobre fundo branco"
echo "    (recomendado WCAG AA: https://webaim.org/resources/contrastchecker/)"
echo
echo "  - atualize docker/.env com MYSQL_DATABASE=db_${SLUG} e MYSQL_USER=user_${SLUG}"
echo "    (ou os defaults que voce preferir nos kernels acima)"
echo
echo "  - admin seed da migration usa mail='$ADMIN_EMAIL', name='$ADMIN_NAME'"
echo "    (troque manualmente se desejar outro login inicial)"
