#!/bin/sh
# Entrypoint unico para web, worker e scheduler.
# Caches do Laravel rodam AQUI (nao no build) porque dependem do ambiente do container.
set -eu

cd /var/www/html

# Diretorios de trabalho (podem ser tmpfs quando o container roda com --read-only).
mkdir -p /tmp/nginx storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY ausente: abortando." >&2
    exit 1
fi

if [ "${RUN_OPTIMIZE:-1}" = "1" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan event:cache --no-interaction
fi

exec "$@"
