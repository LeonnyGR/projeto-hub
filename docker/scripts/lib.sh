#!/usr/bin/env bash
# Funcoes e nomes compartilhados pelos scripts em docker/scripts. Use via: source "$(dirname "$0")/lib.sh"
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${ENV_FILE:-$RAIZ/.env.docker}"

REDE="frete-net"
IMAGE_TAG="${IMAGE_TAG:-local}"
IMG_APP="frete-app:${IMAGE_TAG}"
IMG_POSTGRES="frete-postgres:${IMAGE_TAG}"
IMG_REDIS="frete-redis:${IMAGE_TAG}"
IMG_TEST="frete-app-test:${IMAGE_TAG}"

C_POSTGRES="frete-postgres"
C_REDIS="frete-redis"
C_APP="frete-app"
C_SCHEDULER="frete-scheduler"

V_POSTGRES="frete-pg-data"
V_REDIS="frete-redis-data"
V_STORAGE="frete-storage"

log() { printf '\033[1;34m[frete]\033[0m %s\n' "$*" >&2; }
aviso() { printf '\033[1;33m[frete]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31m[frete] ERRO:\033[0m %s\n' "$*" >&2; exit 1; }

exige_docker() { command -v docker >/dev/null 2>&1 || die "docker nao encontrado"; }

# Carrega .env.docker no shell. Regra: valores simples (sem aspas, sem espacos), pois o mesmo
# arquivo tambem e lido por `docker --env-file`, que nao interpreta aspas.
carrega_env() {
    [[ -f "$ENV_FILE" ]] || die "$ENV_FILE nao existe (rode docker/scripts/up.sh para cria-lo)."
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
}

# Gera .env.docker a partir do exemplo, preenchendo __GERAR__ com segredos aleatorios.
garante_env() {
    if [[ -f "$ENV_FILE" ]]; then return 0; fi
    local exemplo="$RAIZ/.env.docker.example"
    [[ -f "$exemplo" ]] || die "$exemplo nao encontrado"
    command -v openssl >/dev/null 2>&1 || die "openssl necessario para gerar segredos"
    log "Criando $ENV_FILE com segredos aleatorios (chmod 600)."
    umask 077
    local linha
    : > "$ENV_FILE"
    while IFS= read -r linha || [[ -n "$linha" ]]; do
        if [[ "$linha" == APP_KEY=__GERAR__ ]]; then
            linha="APP_KEY=base64:$(openssl rand -base64 32)"
        elif [[ "$linha" == *=__GERAR__ ]]; then
            linha="${linha%=__GERAR__}=$(openssl rand -hex 24)"
        fi
        printf '%s\n' "$linha" >> "$ENV_FILE"
    done < "$exemplo"
    chmod 600 "$ENV_FILE"
}

# Escreve um env-file filtrado em arquivo temporario (chmod 600) e imprime o caminho.
# $1 = alvo: postgres | redis | app | migrate
# Cada container so recebe os segredos de que precisa.
env_file_para() {
    local alvo="$1" tmp
    tmp="$(mktemp)"; chmod 600 "$tmp"
    local base; base="$(grep -vE '^[[:space:]]*(#|$)' "$ENV_FILE")"
    case "$alvo" in
        postgres)
            grep -E '^(POSTGRES_DB|POSTGRES_USER|DB_USERNAME|DB_PASSWORD|DB_MIGRATION_USERNAME|DB_MIGRATION_PASSWORD|CRIAR_BANCO_TESTE)=' <<<"$base" > "$tmp"
            printf 'POSTGRES_PASSWORD=%s\n' "$POSTGRES_SUPERUSER_PASSWORD" >> "$tmp" ;;
        redis)
            grep -E '^REDIS_PASSWORD=' <<<"$base" > "$tmp" ;;
        app)
            grep -vE '^(POSTGRES_|CRIAR_BANCO_TESTE|APP_HOST_PORT|APP_HOST_BIND|APP_MEMORY|DB_MIGRATION_)' <<<"$base" > "$tmp" ;;
        migrate)
            grep -vE '^(POSTGRES_|CRIAR_BANCO_TESTE|APP_HOST_PORT|APP_HOST_BIND|APP_MEMORY)' <<<"$base" > "$tmp"
            printf 'RUN_OPTIMIZE=0\n' >> "$tmp" ;;
        *) die "alvo de env desconhecido: $alvo" ;;
    esac
    printf '%s' "$tmp"
}

garante_rede() {
    docker network inspect "$REDE" >/dev/null 2>&1 || { log "Criando rede $REDE"; docker network create "$REDE" >/dev/null; }
}

existe_container() { docker inspect "$1" >/dev/null 2>&1; }

remove_container() { existe_container "$1" && { log "Removendo container $1"; docker rm -f "$1" >/dev/null; } || true; }

# $1 = container, $2 = timeout (s)
aguarda_saudavel() {
    local nome="$1" limite="${2:-90}" t=0 estado
    while (( t < limite )); do
        estado="$(docker inspect -f '{{if .State.Running}}{{if .State.Health}}{{.State.Health.Status}}{{else}}running{{end}}{{else}}parado{{end}}' "$nome" 2>/dev/null || echo ausente)"
        case "$estado" in
            healthy|running) log "$nome: $estado"; return 0 ;;
            parado|ausente) docker logs --tail 40 "$nome" >&2 || true; die "$nome esta '$estado'" ;;
        esac
        sleep 2; t=$((t + 2))
    done
    docker logs --tail 30 "$nome" >&2 || true
    die "timeout aguardando $nome ficar saudavel"
}
