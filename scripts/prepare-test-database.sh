#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -f .env.testing ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env.testing
  set +a
fi

DB_DATABASE="${DB_DATABASE:-}"
if [[ -z "$DB_DATABASE" || "$DB_DATABASE" != *_test ]]; then
  echo "Refusing to prepare: DB_DATABASE must end with _test (got: ${DB_DATABASE:-empty})" >&2
  exit 1
fi

DB_USERNAME="${DB_USERNAME:-tracepharma}"
DB_PASSWORD="${DB_PASSWORD:-tracepharma}"
SOCKET_ARGS=()
if [[ -S /run/mysqld/mysqld.sock ]]; then
  SOCKET_ARGS=(--socket=/run/mysqld/mysqld.sock)
fi

mariadb "${SOCKET_ARGS[@]}" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan config:clear --ansi
php artisan migrate:fresh --force --env=testing --ansi

echo "Test database ${DB_DATABASE} prepared."
