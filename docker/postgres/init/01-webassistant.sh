#!/bin/sh
# Crea el rol de la aplicación SIN privilegios de superusuario ni BYPASSRLS: así las políticas
# de Row Level Security (FORCE RLS) aíslan los tenants también para la propia aplicación.
set -eu
: "${WEBASSISTANT_DB_NAME:=webassistant}"
: "${WEBASSISTANT_DB_USER:=webassistant}"
: "${WEBASSISTANT_DB_PASSWORD:?Define WEBASSISTANT_DB_PASSWORD}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v app_user="$WEBASSISTANT_DB_USER" -v app_password="$WEBASSISTANT_DB_PASSWORD" -v app_db="$WEBASSISTANT_DB_NAME" <<'EOSQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user') \gexec
SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'app_db') \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'app_db') \gexec
EOSQL
echo "Rol ${WEBASSISTANT_DB_USER} y base ${WEBASSISTANT_DB_NAME} preparados (sin BYPASSRLS)."
