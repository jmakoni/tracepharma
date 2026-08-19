#!/usr/bin/env bash
# Bridge a vatengi-era central DB onto the current TracePharma schema
# without replaying the full renamed migration history.
# Usage: scripts/deploy/reconcile-central-schema.sh /var/www/html/tracepharma-{marketing,stage,prod}
set -euo pipefail

TARGET="${1:-}"
SOURCE_DIR="${SOURCE_DIR:-/dpool/tracepharma}"
PHP_FILE="${SOURCE_DIR}/scripts/deploy/reconcile-central-schema.php"
if [[ -z "${TARGET}" || ! -d "${TARGET}" || ! -f "${TARGET}/.env" ]]; then
    echo "Usage: $0 /var/www/html/tracepharma-stage|tracepharma-prod|tracepharma-marketing" >&2
    exit 1
fi
if [[ ! -f "${PHP_FILE}" ]]; then
    echo "Missing ${PHP_FILE}" >&2
    exit 1
fi

echo "==> Reconciling central schema in ${TARGET}"
sudo -u www-data bash -lc "cd '${TARGET}' && php artisan tinker --execute=\"require '${PHP_FILE}';\""
echo "==> Central schema reconcile complete for ${TARGET}"
