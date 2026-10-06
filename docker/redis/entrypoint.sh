#!/bin/sh
# Gera o conf de runtime (senha + bind) e delega ao entrypoint oficial (que ajusta permissoes e
# derruba privilegios para o usuario redis).
set -eu

if [ -z "${REDIS_PASSWORD:-}" ]; then
    echo "[redis] REDIS_PASSWORD obrigatorio." >&2
    exit 1
fi
case "$REDIS_PASSWORD" in
    *\"*|*\\*|*' '*) echo "[redis] REDIS_PASSWORD nao pode conter aspas, barra invertida ou espaco." >&2; exit 1 ;;
esac

conf=/etc/redis-runtime/redis-runtime.conf
# Regerado a cada start (o IP do container muda ao reiniciar).
umask 077
{
    printf 'requirepass "%s"\n' "$REDIS_PASSWORD"
    # Restringe o bind ao loopback + IP(s) desta rede (nunca 0.0.0.0).
    ips="$(hostname -i 2>/dev/null | tr -s ' ' || true)"
    printf 'bind 127.0.0.1 -::1 %s\n' "$ips"
} > "$conf"
chown root:redis "$conf"
chmod 0640 "$conf"

# O oficial recebe o conf como primeiro argumento (nada de senha em argv).
exec docker-entrypoint.sh redis-server /usr/local/etc/redis/redis.conf "$@"
