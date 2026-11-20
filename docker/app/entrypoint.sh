#!/usr/bin/env sh
#
# Container entrypoint.
#
# 1. Blocks until PostgreSQL accepts connections (bounded). Compose health checks
#    cover the local case; this covers managed databases in staging/production.
# 2. In production, compiles the framework caches so the image stays immutable
#    and all configuration comes from the environment.
# 3. Hands off to the requested command (php-fpm, artisan, ...).

set -eu

wait_for_postgres() {
    host="${DB_HOST:-}"
    [ -n "$host" ] || return 0

    port="${DB_PORT:-5432}"
    user="${DB_USERNAME:-postgres}"
    attempts="${WAIT_FOR_DB_ATTEMPTS:-30}"

    i=0
    until pg_isready -q -h "$host" -p "$port" -U "$user"; do
        i=$((i + 1))
        if [ "$i" -ge "$attempts" ]; then
            echo "entrypoint: postgres at ${host}:${port} not reachable after ${attempts} attempts" >&2
            exit 1
        fi
        echo "entrypoint: waiting for postgres at ${host}:${port} (${i}/${attempts})"
        sleep 1
    done
}

warm_caches() {
    [ "${APP_ENV:-production}" = "production" ] || return 0

    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan event:cache --no-interaction
    php artisan view:cache --no-interaction
}

wait_for_postgres
warm_caches

exec "$@"
