#!/usr/bin/env sh
#
# Runs once, when the PostgreSQL data volume is first initialised.
#
# Creates the application role and the test database. The application role is
# deliberately NOT a superuser and cannot bypass row-level security, so every
# query the app runs is subject to the tenant isolation policies. The superuser
# (POSTGRES_USER) is reserved for administration and the pgsql_admin connection.

set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE ROLE "$APP_DB_USER" LOGIN PASSWORD '$APP_DB_PASSWORD' NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB;
    ALTER DATABASE "$POSTGRES_DB" OWNER TO "$APP_DB_USER";
    CREATE DATABASE "${POSTGRES_DB}_test" OWNER "$APP_DB_USER";
EOSQL
