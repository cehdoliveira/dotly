#!/bin/bash
# Preflight que converte degradacao fail-open silenciosa em erro visivel antes
# de subir a marca.
#
# E' um script de DIAGNOSTICO, SOMENTE LEITURA: nao corrige nada, nao escreve
# arquivo, nao sobe/derruba container. Cada checagem imprime UMA linha com
# prefixo fixo [OK], [AVISO] ou [FALHA]:
#   [FALHA] = a marca vai se comportar errado (config ausente/invalida, infra
#             obrigatoria fora do ar).
#   [AVISO] = degradacao conhecida e documentada (fail-open: Redis fora,
#             rdkafka ausente, etc.) — o app continua rodando, so muda de
#             comportamento em silencio.
#
# Ao final imprime um resumo "X ok, Y avisos, Z falhas" e sai com:
#   exit 0  -> nenhuma [FALHA]
#   exit 2  -> pelo menos uma [FALHA] (mesmo codigo que init-whitelabel.sh usa
#              para validacao falhada)
#   exit 1  -> so para erro de uso (flag desconhecida, --root invalido)
#
# NUNCA imprime o valor de nenhuma constante do kernel (segredo ou nao) — so o
# nome da constante/placeholder e o caminho do arquivo. O output deste script
# vai para terminal, log e print de tela.
#
# Categorias de checagem:
#   Locais (sempre rodam): kernel.php presente, placeholder de segredo
#     residual, docker/.env presente, placeholder de host no nginx,
#     ALLOWED_HOSTS/CANONICAL_URL vazios, DB_NAME/DB_USER divergentes do
#     docker/.env, nenhum token de gateway preenchido, SESSION_LIFETIME
#     ausente, sync guard lib/model (bin/check-shared-sync.sh).
#   Runtime (puladas com --skip-docker ou se o container nao estiver
#     rodando): extensoes PHP obrigatorias/opcionais, banco alcancavel, fuso
#     do MySQL vs PHP, migrations_log com falha, gateway habilitado sem
#     credencial, UPLOAD_DIR gravavel.
#
# Uso:
#   bin/doctor.sh                  # tudo (local + runtime se o container 'app' estiver de pe)
#   bin/doctor.sh --skip-docker    # so checagens locais
#   bin/doctor.sh --root <dir> --container <nome>
#
# Flags:
#   --root <dir>        raiz do repo (default: raiz do git atual)
#   --container <nome>  nome do container app (default: app)
#   --skip-docker       pula checagens de runtime (Step 3)
#   -h, --help           mostra esta ajuda
set -u

ROOT=""
CONTAINER="app"
SKIP_DOCKER=0

show_help() {
    sed -n '2,42p' "$0" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --root) ROOT="$2"; shift 2 ;;
        --container) CONTAINER="$2"; shift 2 ;;
        --skip-docker) SKIP_DOCKER=1; shift ;;
        -h|--help) show_help; exit 0 ;;
        *) echo "Flag desconhecida: $1" >&2; exit 1 ;;
    esac
done

if [ -z "$ROOT" ]; then
    ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"
fi
if [ -z "$ROOT" ] || [ ! -d "$ROOT" ]; then
    echo "Nao foi possivel determinar a raiz do repo (--root invalido ou fora de um repo git)." >&2
    exit 1
fi

OK_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

ok()   { echo "[OK] $1";     OK_COUNT=$((OK_COUNT + 1)); }
warn() { echo "[AVISO] $1";  WARN_COUNT=$((WARN_COUNT + 1)); }
fail() { echo "[FALHA] $1";  FAIL_COUNT=$((FAIL_COUNT + 1)); }

# Le uma constante do kernel.php SEM executa-lo (o kernel valida Host, abre
# sessao e conecta no Redis). Cobre valor entre aspas (string) e sem aspas
# (numero/bool) — testado contra ALLOWED_HOSTS, REDIS_PORT e REDIS_ENABLED.
kernel_const() {   # $1 = arquivo, $2 = nome da constante
    grep -oP 'define\(\s*"'"$2"'"\s*,\s*"?\K[^",)]*' "$1" 2>/dev/null | head -1
}

echo "== bin/doctor.sh - preflight da marca =="
echo "Raiz: $ROOT"
echo

# ===================================================================
# Checagens locais (sem Docker)
# ===================================================================
echo "-- Arquivos e configuracao --"

SITE_KERNEL="$ROOT/site/app/inc/kernel.php"
MANAGER_KERNEL="$ROOT/manager/app/inc/kernel.php"
SITE_KERNEL_OK=1
MANAGER_KERNEL_OK=1

if [ ! -f "$SITE_KERNEL" ]; then
    fail "site/app/inc/kernel.php ausente — copie de kernel.php.example ou rode bin/init-whitelabel.sh"
    SITE_KERNEL_OK=0
else
    ok "site/app/inc/kernel.php presente"
fi

if [ ! -f "$MANAGER_KERNEL" ]; then
    fail "manager/app/inc/kernel.php ausente — copie de kernel.php.example ou rode bin/init-whitelabel.sh"
    MANAGER_KERNEL_OK=0
else
    ok "manager/app/inc/kernel.php presente"
fi

# Placeholder de segredo remanescente. Nunca imprime o valor da constante —
# so o nome do placeholder e o arquivo onde foi encontrado.
check_placeholders() {   # $1 = arquivo, $2 = label do ambiente
    local file="$1" label="$2" found=0
    local placeholder
    for placeholder in 'SUA_SENHA_AQUI' 'SUA_APP_PASSWORD_AQUI' 'seu_email@exemplo.com'; do
        if grep -q -- "$placeholder" "$file" 2>/dev/null; then
            fail "$label: placeholder de segredo '$placeholder' ainda presente em $file — preencha a credencial real"
            found=1
        fi
    done
    if [ "$found" -eq 0 ]; then
        ok "$label: nenhum placeholder de segredo residual em $file"
    fi
}
[ "$SITE_KERNEL_OK" -eq 1 ] && check_placeholders "$SITE_KERNEL" "site"
[ "$MANAGER_KERNEL_OK" -eq 1 ] && check_placeholders "$MANAGER_KERNEL" "manager"

# docker/.env — o compose depende de MYSQL_*.
ENV_FILE="$ROOT/docker/.env"
if [ ! -f "$ENV_FILE" ]; then
    fail "docker/.env ausente — copie de docker/.env.example e preencha MYSQL_* (o compose depende disso)"
else
    ok "docker/.env presente"
fi

# Placeholders de host do nginx. O entrypoint.sh substitui em runtime, mas
# residuo no arquivo versionado indica marca ainda nao instanciada. So se
# aplica quando as checagens de runtime nao foram explicitamente desligadas.
NGINX_CONF="$ROOT/docker/interface/default.conf"
if [ "$SKIP_DOCKER" -ne 1 ]; then
    if [ -f "$NGINX_CONF" ] && grep -q '__SITE_HOSTS__\|__MANAGER_HOSTS__' "$NGINX_CONF"; then
        fail "docker/interface/default.conf ainda tem placeholders __SITE_HOSTS__/__MANAGER_HOSTS__ — marca nao instanciada (rode bin/init-whitelabel.sh)"
    else
        ok "docker/interface/default.conf sem placeholders de host residuais"
    fi
fi

# ALLOWED_HOSTS + CANONICAL_URL — canonical_url() e fail-closed: se os dois
# estiverem vazios, lanca excecao ao montar link de e-mail/webhook.
check_allowed_hosts() {   # $1 = arquivo, $2 = label, $3 = nome da constante canonica
    local file="$1" label="$2" canon_name="$3"
    local hosts canon
    hosts="$(kernel_const "$file" ALLOWED_HOSTS)"
    canon="$(kernel_const "$file" "$canon_name")"
    if [ -z "$hosts" ] && [ -z "$canon" ]; then
        fail "$label: ALLOWED_HOSTS e $canon_name vazios — canonical_url() e fail-closed e vai lancar excecao ao montar link de e-mail/webhook"
    else
        ok "$label: ALLOWED_HOSTS ou $canon_name preenchido"
    fi
}
[ "$SITE_KERNEL_OK" -eq 1 ] && check_allowed_hosts "$SITE_KERNEL" "site" "SITE_CANONICAL_URL"
[ "$MANAGER_KERNEL_OK" -eq 1 ] && check_allowed_hosts "$MANAGER_KERNEL" "manager" "MANAGER_CANONICAL_URL"

# DB_NAME/DB_USER do kernel vs MYSQL_DATABASE/MYSQL_USER do docker/.env — e' a
# divergencia que o README manda o operador replicar a mao. Nunca imprime os
# valores, so o fato de divergirem.
if [ "$SITE_KERNEL_OK" -eq 1 ] && [ -f "$ENV_FILE" ]; then
    K_DB_NAME="$(kernel_const "$SITE_KERNEL" DB_NAME)"
    K_DB_USER="$(kernel_const "$SITE_KERNEL" DB_USER)"
    E_DB_NAME="$(grep -oP '^MYSQL_DATABASE=\K.*' "$ENV_FILE" 2>/dev/null | head -1)"
    E_DB_USER="$(grep -oP '^MYSQL_USER=\K.*' "$ENV_FILE" 2>/dev/null | head -1)"
    if [ -n "$K_DB_NAME" ] && [ -n "$E_DB_NAME" ] && [ "$K_DB_NAME" != "$E_DB_NAME" ]; then
        warn "DB_NAME do kernel do site difere de MYSQL_DATABASE do docker/.env — replique manualmente (README, passo 3)"
    elif [ -n "$K_DB_USER" ] && [ -n "$E_DB_USER" ] && [ "$K_DB_USER" != "$E_DB_USER" ]; then
        warn "DB_USER do kernel do site difere de MYSQL_USER do docker/.env — replique manualmente (README, passo 3)"
    else
        ok "DB_NAME/DB_USER do kernel do site coerentes com docker/.env"
    fi
fi

# Nenhum token de gateway preenchido — checkout vai recusar todo PSP. So
# checa presenca, nunca validade. Defaults vazios aqui para as variaveis
# ficarem definidas mesmo se o kernel do site estiver ausente (usadas de novo
# nas checagens de runtime, mais abaixo).
MP_TOKEN=""
PAGBANK_TOKEN=""
INFINITEPAY_HANDLE=""
if [ "$SITE_KERNEL_OK" -eq 1 ]; then
    MP_TOKEN="$(kernel_const "$SITE_KERNEL" MP_ACCESS_TOKEN)"
    PAGBANK_TOKEN="$(kernel_const "$SITE_KERNEL" PAGBANK_TOKEN)"
    INFINITEPAY_HANDLE="$(kernel_const "$SITE_KERNEL" INFINITEPAY_HANDLE)"
    if [ -z "$MP_TOKEN" ] && [ -z "$PAGBANK_TOKEN" ] && [ -z "$INFINITEPAY_HANDLE" ]; then
        warn "nenhum token de gateway preenchido no kernel do site (MP_ACCESS_TOKEN/PAGBANK_TOKEN/INFINITEPAY_HANDLE) — checkout vai recusar todo PSP"
    else
        ok "pelo menos um token de gateway preenchido no kernel do site"
    fi
fi

# SESSION_LIFETIME ausente (so relevante depois de plans/014).
for pair in "$SITE_KERNEL:site:$SITE_KERNEL_OK" "$MANAGER_KERNEL:manager:$MANAGER_KERNEL_OK"; do
    kfile="${pair%%:*}"; rest="${pair#*:}"; label="${rest%%:*}"; kok="${rest#*:}"
    if [ "$kok" -eq 1 ]; then
        if [ -z "$(kernel_const "$kfile" SESSION_LIFETIME)" ]; then
            warn "$label: SESSION_LIFETIME ausente no kernel (plans/014) — timeout de inatividade nao aplicado pelo app"
        else
            ok "$label: SESSION_LIFETIME definido no kernel"
        fi
    fi
done

# Sync guard — reaproveita bin/check-shared-sync.sh. Aviso, nao falha: nao e
# problema de runtime da marca.
echo
echo "-- Sincronia lib/model --"
SYNC_OUT="$(mktemp)"
if bash "$ROOT/bin/check-shared-sync.sh" >"$SYNC_OUT" 2>&1; then
    ok "lib/ e model/ sincronizados entre site/ e manager/ (bin/check-shared-sync.sh)"
else
    warn "lib/ e model/ divergem entre site/ e manager/ (bin/check-shared-sync.sh)"
    cat "$SYNC_OUT"
fi
rm -f "$SYNC_OUT"

# ===================================================================
# Checagens de runtime (com o container)
# ===================================================================
echo
echo "-- Runtime (container '$CONTAINER') --"

RUN_DOCKER_CHECKS=0
if [ "$SKIP_DOCKER" -eq 1 ]; then
    echo "(puladas por --skip-docker)"
elif ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$CONTAINER"; then
    warn "container '$CONTAINER' nao esta rodando — checagens de runtime puladas"
else
    RUN_DOCKER_CHECKS=1
fi

if [ "$RUN_DOCKER_CHECKS" -eq 1 ]; then
    EXT_LIST="$(docker exec "$CONTAINER" php -m 2>/dev/null)"

    for ext in pdo_mysql mbstring gd; do
        if echo "$EXT_LIST" | grep -qix "$ext"; then
            ok "extensao PHP obrigatoria '$ext' presente no container"
        else
            fail "extensao PHP obrigatoria '$ext' ausente no container '$CONTAINER' — reinstale (ver docker/interface/Dockerfile)"
        fi
    done

    if echo "$EXT_LIST" | grep -qix "redis"; then
        ok "extensao PHP 'redis' presente no container"
    else
        warn "extensao PHP 'redis' ausente no container — rate limit cai no fallback de arquivo (flock)"
    fi

    # rdkafka: prefere consultar EmailProducer::isAvailable() em vez de so
    # olhar `php -m`, para refletir exatamente a logica que a app usa.
    EMAIL_AVAILABLE="$(docker exec "$CONTAINER" php -r '
        require "/var/www/app/site/app/inc/lib/EmailProducer.php";
        echo EmailProducer::isAvailable() ? "1" : "0";
    ' 2>/dev/null)"
    if [ "$EMAIL_AVAILABLE" = "1" ]; then
        ok "EmailProducer::isAvailable() = true (rdkafka operante no container)"
    else
        warn "EmailProducer::isAvailable() = false (rdkafka ausente/indisponivel) — e-mail de admin (convite/reset) nao e enviado (plans/007)"
    fi

    # Banco alcancavel — SELECT 1 via PHP dentro do container, usando as
    # constantes do kernel do site. HTTP_HOST vazio como entrypoint.sh faz,
    # para passar no guard anti-Host-Injection do kernel. Nunca imprime
    # credencial nem string de conexao.
    DB_CHECK_OUT="$(docker exec "$CONTAINER" php -r '
        $_SERVER["DOCUMENT_ROOT"] = "/var/www/app/site/public_html/";
        $_SERVER["HTTP_HOST"] = "";
        require "/var/www/app/site/app/inc/kernel.php";
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_TIMEOUT => 3]
            );
            $stmt = $pdo->query("SELECT 1");
            echo $stmt ? "OK" : "FAIL";
        } catch (Throwable $e) {
            echo "FAIL";
        }
    ' 2>/dev/null)"
    if [ "$DB_CHECK_OUT" = "OK" ]; then
        ok "banco alcancavel a partir do container (SELECT 1 via kernel do site)"
    else
        fail "banco inalcancavel a partir do container — verifique DB_HOST/DB_NAME/DB_USER/DB_PASS do kernel e se o servico mysql esta de pe"
    fi

    # Fuso do MySQL vs PHP — diferenca > 60s indica timezone divergente
    # (datas de criacao deslocadas, ver plans/005).
    if [ "$DB_CHECK_OUT" = "OK" ]; then
        TZ_DIFF="$(docker exec "$CONTAINER" php -r '
            $_SERVER["DOCUMENT_ROOT"] = "/var/www/app/site/public_html/";
            $_SERVER["HTTP_HOST"] = "";
            require "/var/www/app/site/app/inc/kernel.php";
            try {
                $pdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_TIMEOUT => 3]
                );
                $mysqlNow = strtotime($pdo->query("SELECT NOW()")->fetchColumn());
                $phpNow = time();
                echo abs($mysqlNow - $phpNow);
            } catch (Throwable $e) {
                echo "-1";
            }
        ' 2>/dev/null)"
        if [ -z "$TZ_DIFF" ] || [ "$TZ_DIFF" -lt 0 ] 2>/dev/null; then
            warn "nao foi possivel comparar o fuso do MySQL com o do PHP (consulta falhou)"
        elif [ "$TZ_DIFF" -gt 60 ]; then
            fail "fuso do MySQL diverge do PHP em mais de 60s (diff=${TZ_DIFF}s) — datas de criacao vao ficar deslocadas (ver plans/005)"
        else
            ok "fuso do MySQL coerente com o do PHP (diff=${TZ_DIFF}s)"
        fi
    fi

    # migrations_log com linha status='failed'.
    if [ "$DB_CHECK_OUT" = "OK" ]; then
        FAILED_MIGRATIONS="$(docker exec "$CONTAINER" php -r '
            $_SERVER["DOCUMENT_ROOT"] = "/var/www/app/site/public_html/";
            $_SERVER["HTTP_HOST"] = "";
            require "/var/www/app/site/app/inc/kernel.php";
            try {
                $pdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_TIMEOUT => 3]
                );
                $stmt = $pdo->query("SELECT migration_name FROM migrations_log WHERE status=\x27failed\x27");
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo implode(",", $rows);
            } catch (Throwable $e) {
                echo "__ERROR__";
            }
        ' 2>/dev/null)"
        if [ "$FAILED_MIGRATIONS" = "__ERROR__" ]; then
            fail "tabela migrations_log inexistente ou inacessivel — rode as migrations (docker exec app php .../run_migrations.php)"
        elif [ -n "$FAILED_MIGRATIONS" ]; then
            fail "migration(s) com status='failed' em migrations_log: $FAILED_MIGRATIONS"
        else
            ok "nenhuma migration com status='failed' em migrations_log"
        fi
    fi

    # Gateways habilitados — nenhum habilitado quebra todo checkout.
    if [ "$DB_CHECK_OUT" = "OK" ]; then
        ENABLED_GATEWAYS="$(docker exec "$CONTAINER" php -r '
            $_SERVER["DOCUMENT_ROOT"] = "/var/www/app/site/public_html/";
            $_SERVER["HTTP_HOST"] = "";
            require "/var/www/app/site/app/inc/kernel.php";
            try {
                $pdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_TIMEOUT => 3]
                );
                $stmt = $pdo->query("SELECT slug FROM payment_gateways WHERE active=\x27yes\x27 AND enabled=\x27yes\x27");
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo implode(",", $rows);
            } catch (Throwable $e) {
                echo "__ERROR__";
            }
        ' 2>/dev/null)"
        if [ "$ENABLED_GATEWAYS" = "__ERROR__" ]; then
            fail "nao foi possivel consultar payment_gateways — tabela ausente ou banco inacessivel"
        elif [ -z "$ENABLED_GATEWAYS" ]; then
            fail "nenhum gateway habilitado em payment_gateways (active='yes' AND enabled='yes') — checkout vai falhar para todo comprador"
        else
            ok "gateway(s) habilitado(s) em payment_gateways: $ENABLED_GATEWAYS"
            # Gateway habilitado sem credencial correspondente no kernel.
            for slug in $(echo "$ENABLED_GATEWAYS" | tr ',' ' '); do
                case "$slug" in
                    mercadopago)
                        [ -z "$MP_TOKEN" ] && warn "gateway 'mercadopago' habilitado mas MP_ACCESS_TOKEN vazio no kernel do site — checkout falha so para esse PSP"
                        ;;
                    pagbank)
                        [ -z "$PAGBANK_TOKEN" ] && warn "gateway 'pagbank' habilitado mas PAGBANK_TOKEN vazio no kernel do site — checkout falha so para esse PSP"
                        ;;
                    infinitepay)
                        [ -z "$INFINITEPAY_HANDLE" ] && warn "gateway 'infinitepay' habilitado mas INFINITEPAY_HANDLE vazio no kernel do site — checkout falha so para esse PSP"
                        ;;
                esac
            done
        fi
    fi

    # UPLOAD_DIR gravavel dentro do container.
    UPLOAD_DIR_VALUE="$(kernel_const "$SITE_KERNEL" UPLOAD_DIR)"
    if [ -n "$UPLOAD_DIR_VALUE" ]; then
        if docker exec "$CONTAINER" test -w "$UPLOAD_DIR_VALUE" 2>/dev/null; then
            ok "UPLOAD_DIR gravavel dentro do container"
        else
            warn "UPLOAD_DIR nao gravavel dentro do container — upload de arquivo vai falhar"
        fi
    fi
fi

# ===================================================================
# Resumo
# ===================================================================
echo
echo "== Resumo: $OK_COUNT ok, $WARN_COUNT avisos, $FAIL_COUNT falhas =="

if [ "$FAIL_COUNT" -gt 0 ]; then
    exit 2
fi
exit 0
