#!/usr/bin/env bash
# Sobe a stack (sem Compose): rede, imagens, Postgres, Redis, web e, opcionalmente, o scheduler.
#
# Uso: docker/scripts/up.sh [--scheduler] [--expose-postgres[=PORTA]] [--no-build]
#   --scheduler              sobe tambem o agendador (atualiza o rastreio das remessas)
#   --expose-postgres[=P]    DEV: publica o Postgres em 127.0.0.1:P (padrao 5433). Por padrao nada e publicado.
#   --no-build               nao reconstroi as imagens
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

SCHEDULER=0; BUILD=1; PG_PORTA=""
for a in "$@"; do
    case "$a" in
        --scheduler) SCHEDULER=1 ;;
        --all) SCHEDULER=1 ;;
        --no-build) BUILD=0 ;;
        --expose-postgres) PG_PORTA=5433 ;;
        --expose-postgres=*) PG_PORTA="${a#*=}" ;;
        -h|--help) sed -n '2,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "argumento desconhecido: $a" ;;
    esac
done

exige_docker
garante_env
carrega_env
garante_rede

if [[ "$BUILD" == 1 ]]; then
    log "Build das imagens (app, postgres, redis)"
    docker build -q -f "$RAIZ/docker/app/Dockerfile" --target runtime -t "$IMG_APP" "$RAIZ" >/dev/null
    docker build -q -t "$IMG_POSTGRES" "$RAIZ/docker/postgres" >/dev/null
    docker build -q -t "$IMG_REDIS" "$RAIZ/docker/redis" >/dev/null
fi

# ---------------------------------------------------------------- Postgres
if existe_container "$C_POSTGRES" && [[ "$(docker inspect -f '{{.State.Running}}' "$C_POSTGRES")" == "true" ]]; then
    log "$C_POSTGRES ja esta rodando"
else
    remove_container "$C_POSTGRES"
    ef="$(env_file_para postgres)"
    pub=()
    if [[ -n "$PG_PORTA" ]]; then
        aviso "DEV: Postgres publicado em 127.0.0.1:${PG_PORTA}"
        pub=(-p "127.0.0.1:${PG_PORTA}:5432")
    fi
    log "Subindo $C_POSTGRES"
    docker run -d --name "$C_POSTGRES" --network "$REDE" --restart unless-stopped \
        --env-file "$ef" -v "${V_POSTGRES}:/var/lib/postgresql/data" \
        --security-opt no-new-privileges "${pub[@]}" "$IMG_POSTGRES" >/dev/null
    rm -f "$ef"
fi

# ---------------------------------------------------------------- Redis
if existe_container "$C_REDIS" && [[ "$(docker inspect -f '{{.State.Running}}' "$C_REDIS")" == "true" ]]; then
    log "$C_REDIS ja esta rodando"
else
    remove_container "$C_REDIS"
    ef="$(env_file_para redis)"
    log "Subindo $C_REDIS"
    docker run -d --name "$C_REDIS" --network "$REDE" --restart unless-stopped \
        --env-file "$ef" -v "${V_REDIS}:/data" \
        --cap-drop ALL --cap-add SETUID --cap-add SETGID --cap-add CHOWN --cap-add DAC_OVERRIDE \
        --security-opt no-new-privileges "$IMG_REDIS" >/dev/null
    rm -f "$ef"
fi

aguarda_saudavel "$C_POSTGRES" 120
aguarda_saudavel "$C_REDIS" 60

# ---------------------------------------------------------------- App (web / scheduler)
# Rootfs somente-leitura; so /tmp, cache do Laravel, storage/framework e o volume de etiquetas sao gravaveis.
roda_app() { # $1 = nome, $2.. = args extras do docker run; comando via variavel CMD_APP (array)
    local nome="$1"; shift
    remove_container "$nome"
    local ef; ef="$(env_file_para app)"
    log "Subindo $nome"
    docker run -d --name "$nome" --network "$REDE" --restart unless-stopped \
        --env-file "$ef" \
        --memory "${APP_MEMORY:-1g}" \
        --read-only \
        --tmpfs /tmp:rw,nosuid,nodev,mode=1777 \
        --tmpfs /var/www/html/bootstrap/cache:rw,nosuid,nodev,uid=82,gid=82,mode=0755 \
        --tmpfs /var/www/html/storage/framework:rw,nosuid,nodev,uid=82,gid=82,mode=0755 \
        --tmpfs /var/www/html/storage/logs:rw,nosuid,nodev,uid=82,gid=82,mode=0755 \
        -v "${V_STORAGE}:/var/www/html/storage/app" \
        --cap-drop ALL --security-opt no-new-privileges \
        "$@"
    rm -f "$ef"
}

roda_app "$C_APP" -p "${APP_HOST_BIND:-127.0.0.1}:${APP_HOST_PORT:-8090}:8080" "$IMG_APP" >/dev/null

if [[ "$SCHEDULER" == 1 ]]; then
    roda_app "$C_SCHEDULER" \
        --health-cmd 'pgrep -f "artisan schedule:work" >/dev/null' --health-interval 30s --health-start-period 30s \
        "$IMG_APP" php artisan schedule:work >/dev/null
fi

aguarda_saudavel "$C_APP" 90
[[ "$SCHEDULER" == 1 ]] && aguarda_saudavel "$C_SCHEDULER" 90

log "Pronto. API em http://${APP_HOST_BIND:-127.0.0.1}:${APP_HOST_PORT:-8090}/up"
log "Proximo passo: docker/scripts/migrate.sh"
