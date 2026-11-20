# syntax=docker/dockerfile:1.7
#
# Vendra API image.
#
# Stages:
#   base   - PHP-FPM runtime with the extensions the application needs. Shared by all other stages.
#   vendor - Production Composer dependencies, built in isolation so the layer is cached by lock file.
#   dev    - base + coverage driver + relaxed opcache. Source code is bind-mounted by Compose.
#   prod   - Immutable, non-root image with vendor and an optimised autoloader.
#   web    - nginx with the compiled public/ directory, used in front of prod.

ARG PHP_VERSION=8.3
ARG NGINX_VERSION=1.27

# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer/cache

# install-php-extensions resolves build dependencies per extension and removes
# them afterwards, keeping the layer small and the list declarative.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN set -eux; \
    apk add --no-cache \
        bash \
        curl \
        fcgi \
        git \
        postgresql16-client \
        tzdata \
        unzip; \
    install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        zip; \
    rm -rf /tmp/pear "${COMPOSER_HOME}"

COPY docker/php/php.ini       /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini   /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/www.conf      /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD REQUEST_METHOD=GET SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# ---------------------------------------------------------------------------
FROM base AS dev

RUN set -eux; \
    install-php-extensions pcov; \
    rm -rf /tmp/pear

COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

ENV APP_ENV=local

# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=production

COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .

RUN set -eux; \
    composer dump-autoload --optimize --classmap-authoritative --no-dev; \
    chown -R www-data:www-data storage bootstrap/cache; \
    rm -rf /tmp/composer

USER www-data

# ---------------------------------------------------------------------------
FROM nginx:${NGINX_VERSION}-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public
