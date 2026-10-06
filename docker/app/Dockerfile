# syntax=docker/dockerfile:1.7
# Imagem unica para web, worker e scheduler (so o comando muda).
#   web       : CMD padrao (supervisord -> nginx :8080 + php-fpm 127.0.0.1:9000)
#   worker    : php artisan queue:work --tries=3 --max-time=3600
#   scheduler : php artisan schedule:work
# Alvos: runtime (padrao, producao) | test (runtime + dependencias de dev; so para docker/scripts/test.sh)

ARG PHP_IMAGE=php:8.3.35-fpm-alpine3.24
ARG COMPOSER_IMAGE=composer:2.10.3

# ---------------------------------------------------------------- vendor (producao)
FROM ${COMPOSER_IMAGE} AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# As extensoes (pdo_pgsql, redis, intl...) existem so no runtime: ignorar aqui. O PHP alvo e fixado em composer.json (platform.php).
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader \
        --prefer-dist --ignore-platform-req='ext-*'
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------------------------------------------------------------- vendor (dev, para testes)
FROM ${COMPOSER_IMAGE} AS vendor-dev
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader \
        --prefer-dist --ignore-platform-req='ext-*'
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
RUN composer dump-autoload --optimize --no-scripts

# ---------------------------------------------------------------- base php (extensoes)
FROM ${PHP_IMAGE} AS base
ARG PHPREDIS_VERSION=6.3.0

# Runtime: libs compartilhadas + nginx + supervisor. Build: removido no mesmo RUN.
RUN set -eux; \
    apk add --no-cache libpq icu-libs nginx supervisor; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl opcache pcntl; \
    pecl install "redis-${PHPREDIS_VERSION}"; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -f /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/pecl /usr/local/bin/pear \
          /usr/local/bin/peardev /usr/local/bin/docker-php-ext-* /usr/local/bin/docker-php-source; \
    rm -rf /tmp/pear /var/cache/apk/* /usr/local/lib/php/doc /usr/local/lib/php/test

# Configuracoes. Sem tuning de usuario/grupo no pool: o container roda inteiro como www-data.
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-frete.ini
COPY docker/app/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/app/nginx.conf /etc/nginx/nginx.conf
COPY docker/app/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chmod=0755 docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh

# Remove os pools/confs padrao da imagem (docker.conf/www.conf/zz-docker.conf) para valer so o nosso.
RUN rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.default \
          /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
          /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

# ---------------------------------------------------------------- runtime (producao)
FROM base AS runtime
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data artisan composer.json ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data storage ./storage

# storage/ e bootstrap/cache gravaveis por www-data; diretorios do nginx/supervisor tambem (rodamos sem root).
RUN set -eux; \
    mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache \
             /var/lib/nginx/tmp /var/lib/nginx/logs /run/nginx; \
    chown -R www-data:www-data storage bootstrap/cache /var/lib/nginx /run/nginx; \
    chmod -R ug+rwX storage bootstrap/cache

USER www-data
EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]

# ---------------------------------------------------------------- test (NAO usar em producao)
FROM runtime AS test
USER root
COPY --chown=www-data:www-data --from=vendor-dev /app/vendor ./vendor
USER www-data
