#!/usr/bin/env bash
set -euo pipefail

# Only drops databases named tenant_test_* — never shared-host demo/prod tenant_* schemas.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -f .env.testing ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env.testing
  set +a
fi

if [[ "${APP_ENV:-}" != "testing" ]]; then
  echo "Refuse: APP_ENV must be testing" >&2
  exit 1
fi

MARKER="$ROOT/storage/framework/.purge_test_tenants"
if [[ "${PURGE_TEST_TENANTS:-}" != "1" && ! -f "$MARKER" ]]; then
  echo "Refuse: set PURGE_TEST_TENANTS=1 or create $MARKER" >&2
  exit 1
fi

DB_DATABASE="${DB_DATABASE:-}"
if [[ -z "$DB_DATABASE" || "$DB_DATABASE" != *_test ]]; then
  echo "Refuse: DB_DATABASE must end with _test" >&2
  exit 1
fi

DB_USERNAME="${DB_USERNAME:-tracepharma}"
DB_PASSWORD="${DB_PASSWORD:-tracepharma}"
SOCKET_ARGS=()
if [[ -S /run/mysqld/mysqld.sock ]]; then
  SOCKET_ARGS=(--socket=/run/mysqld/mysqld.sock)
fi

AUTH=(-u"$DB_USERNAME")
[[ -n "$DB_PASSWORD" ]] && AUTH+=(-p"$DB_PASSWORD")

mapfile -t DBS < <(mariadb "${SOCKET_ARGS[@]}" "${AUTH[@]}" -N -e "SHOW DATABASES LIKE 'tenant\\_test\\_%';" || true)
for db in "${DBS[@]:-}"; do
  [[ -z "$db" ]] && continue
  echo "Dropping ${db}"
  mariadb "${SOCKET_ARGS[@]}" "${AUTH[@]}" -e "DROP DATABASE IF EXISTS \`${db}\`;"
done

echo OK
