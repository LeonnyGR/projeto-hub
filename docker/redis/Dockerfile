FROM redis:7.4.11-alpine3.21

COPY redis.conf /usr/local/etc/redis/redis.conf
COPY --chmod=0755 entrypoint.sh /usr/local/bin/frete-redis-entrypoint.sh

# Diretorio (de root, sem sticky bit) onde o entrypoint grava senha/bind a cada start.
RUN mkdir -p /etc/redis-runtime && chmod 0755 /etc/redis-runtime

# Senha SEMPRE por variavel de ambiente em runtime (REDIS_PASSWORD).
VOLUME /data

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 \
    CMD REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --no-auth-warning ping 2>/dev/null | grep -q PONG || exit 1

ENTRYPOINT ["/usr/local/bin/frete-redis-entrypoint.sh"]
