#!/usr/bin/env bash
# Roda as migrations com a role dona do schema (DB_MIGRATION_*), num container descartavel.
# Uso: docker/scripts/migrate.sh [argumentos do artisan migrate, ex.: --seed | --pretend]
# Para outros comandos artisan (ex.: transportadora:configurar) use docker/scripts/artisan.sh
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
exige_docker
carrega_env

if [[ "${1:-}" == "--artisan" ]]; then
    shift
    # shellcheck disable=SC2086
    set -- $1
    cmd=(php artisan "$@")
else
    cmd=(php artisan migrate --force --database=migrator "$@")
fi

ef="$(env_file_para migrate)"
trap 'rm -f "$ef"' EXIT
docker run --rm --network "$REDE" --env-file "$ef" \
    --read-only --tmpfs /tmp:rw,mode=1777 \
    --tmpfs /var/www/html/bootstrap/cache:rw,uid=82,gid=82,mode=0755 \
    --tmpfs /var/www/html/storage/framework:rw,uid=82,gid=82,mode=0755 \
    --tmpfs /var/www/html/storage/logs:rw,uid=82,gid=82,mode=0755 \
    --cap-drop ALL --security-opt no-new-privileges \
    "$IMG_APP" "${cmd[@]}"
