#!/usr/bin/env bash
# Roda a suite Pest dentro da rede frete-net, contra os containers Postgres (banco frete_test) e Redis (db 1).
# Uso: docker/scripts/test.sh [argumentos do pest, ex.: --filter Mascaramento | tests/Unit]
# Requer a stack no ar (docker/scripts/up.sh) com CRIAR_BANCO_TESTE=1 na primeira subida do Postgres.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
exige_docker
carrega_env

existe_container "$C_POSTGRES" || die "Postgres nao esta no ar. Rode docker/scripts/up.sh antes."
existe_container "$C_REDIS" || die "Redis nao esta no ar. Rode docker/scripts/up.sh antes."

log "Build da imagem de teste (alvo test)"
docker build -q -f "$RAIZ/docker/app/Dockerfile" --target test -t "$IMG_TEST" "$RAIZ" >/dev/null

ef="$(env_file_para migrate)"
trap 'rm -f "$ef"' EXIT

# Os testes NUNCA tocam o banco/redis de desenvolvimento: banco frete_test e Redis db 1 (prefixo proprio).
docker run --rm --network "$REDE" --env-file "$ef" \
    -e APP_ENV=testing -e DB_DATABASE=frete_test -e REDIS_DB=1 -e REDIS_CACHE_DB=1 -e CACHE_PREFIX=teste_ \
    -e RUN_OPTIMIZE=0 -e TRANSPORTADORAS_MODO=mock -e VIACEP_MODO=mock -e LOG_CHANNEL=null \
    -v /dev/null:/var/www/html/.env:ro -v "$RAIZ/tests:/var/www/html/tests:ro" -v "$RAIZ/phpunit.xml:/var/www/html/phpunit.xml:ro" \
    --entrypoint sh "$IMG_TEST" -c '
        set -e
        php artisan migrate:fresh --database=migrator --force --no-interaction >/dev/null
        exec vendor/bin/pest --colors=always "$@"
    ' pest "$@"
