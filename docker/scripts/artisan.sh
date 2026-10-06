#!/usr/bin/env bash
# Roda um comando artisan num container descartavel, com o ambiente do .env.docker (role de banco do dia a dia).
# Aceita digitacao interativa (por exemplo, confirmacoes de comandos).
#
# Exemplos:
#   docker/scripts/artisan.sh cliente:create "B2C"
#   docker/scripts/artisan.sh cliente:list
#   docker/scripts/artisan.sh transportadora:configurar jadlog
#   docker/scripts/artisan.sh cliente:rotate-key <id-do-cliente>
#   docker/scripts/artisan.sh list
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
exige_docker
carrega_env

[[ $# -gt 0 ]] || die "Informe o comando artisan. Ex.: docker/scripts/artisan.sh cliente:create \"B2C\""

ef="$(env_file_para app)"
trap 'rm -f "$ef"' EXIT

# -t so quando ha terminal (permite digitar senha escondida); -i sempre (aceita entrada por pipe)
tty=(-i); [[ -t 0 ]] && tty=(-it)

docker run --rm "${tty[@]}" --network "$REDE" --env-file "$ef" -e RUN_OPTIMIZE=0 -e LOG_LEVEL=warning \
    --read-only --tmpfs /tmp:rw,mode=1777 \
    --tmpfs /var/www/html/bootstrap/cache:rw,uid=82,gid=82,mode=0755 \
    --tmpfs /var/www/html/storage/framework:rw,uid=82,gid=82,mode=0755 \
    --tmpfs /var/www/html/storage/logs:rw,uid=82,gid=82,mode=0755 \
    -v "${V_STORAGE}:/var/www/html/storage/app" \
    --cap-drop ALL --security-opt no-new-privileges \
    "$IMG_APP" php artisan "$@"
