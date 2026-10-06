# Postgres simulando o Supabase (RLS automatico, roles anon/authenticated/service_role sem privilegios).
FROM postgres:16.15-alpine3.24

# Nao-secretos. Senhas SEMPRE por variavel de ambiente em runtime (POSTGRES_PASSWORD, DB_PASSWORD, DB_MIGRATION_PASSWORD).
ENV POSTGRES_DB=frete \
    POSTGRES_USER=postgres_admin

# Roda uma unica vez, quando o volume de dados esta vazio.
COPY init/ /docker-entrypoint-initdb.d/
RUN chmod 0644 /docker-entrypoint-initdb.d/*.sql

# Dados em volume nomeado (definido em docker/scripts/up.sh).
VOLUME /var/lib/postgresql/data

HEALTHCHECK --interval=10s --timeout=3s --start-period=30s --retries=5 \
    CMD pg_isready -q -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" || exit 1

CMD ["postgres", "-c", "password_encryption=scram-sha-256", "-c", "log_connections=on", "-c", "log_disconnections=on"]
