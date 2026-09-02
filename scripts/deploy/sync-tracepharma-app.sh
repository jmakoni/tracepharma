#!/usr/bin/env bash
# In-place rsync of /dpool/tracepharma into a live nginx root.
# Usage: scripts/deploy/sync-tracepharma-app.sh /var/www/html/tracepharma-{marketing,stage,prod}
# SKIP_REFRESH=1  — code + composer + vite + config cache only (no migrate)
# SKIP_MIGRATE=1  — refresh caches but skip migrate / tenants:migrate
# Live vatengi-era DBs need scripts/deploy/reconcile-central-schema.sh first.
# Do not run a full tenants:migrate against renamed tenant histories.
# EPCIS upload limits: also install scripts/deploy/php/99-tracepharma-uploads.ini into
# /etc/php/8.5/fpm/conf.d/ and reload PHP-FPM (see docs/nginx for client_max_body_size).
set -euo pipefail

TARGET="${1:-}"
SOURCE_DIR="${SOURCE_DIR:-/dpool/tracepharma}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.5-fpm}"

if [[ -z "${TARGET}" || ! -d "${TARGET}" ]]; then
    echo "Usage: $0 /var/www/html/tracepharma-stage|tracepharma-prod|tracepharma-marketing" >&2
    exit 1
fi

if [[ ! -d "${SOURCE_DIR}" ]]; then
    echo "Missing SOURCE_DIR=${SOURCE_DIR}" >&2
    exit 1
fi

RSYNC_EXCLUDES=(
    --exclude .git
    --exclude .env
    --exclude .env.testing
    --exclude node_modules
    --exclude vendor
    --exclude storage/
    --exclude bootstrap/cache/*.php
)

echo "==> Syncing ${SOURCE_DIR} to ${TARGET}"
sudo rsync -a --delete "${RSYNC_EXCLUDES[@]}" "${SOURCE_DIR}/" "${TARGET}/"
sudo mkdir -p \
    "${TARGET}/storage/logs" \
    "${TARGET}/storage/framework/cache/data" \
    "${TARGET}/storage/framework/sessions" \
    "${TARGET}/storage/framework/views" \
    "${TARGET}/bootstrap/cache"
sudo chown -R www-data:www-data "${TARGET}"
sudo find "${TARGET}/storage" "${TARGET}/bootstrap/cache" -type d -exec chmod 775 {} +
sudo find "${TARGET}/storage" "${TARGET}/bootstrap/cache" -type f -exec chmod 664 {} +
sudo chmod 640 "${TARGET}/.env"
sudo rm -f "${TARGET}/bootstrap/cache/packages.php" "${TARGET}/bootstrap/cache/services.php" "${TARGET}/bootstrap/cache/config.php" "${TARGET}/bootstrap/cache/routes-v7.php" "${TARGET}/bootstrap/cache/events.php"

echo "==> composer install --no-dev in ${TARGET}"
sudo -u www-data bash -lc "cd '${TARGET}' && composer install --no-dev --optimize-autoloader --no-interaction --no-scripts"
sudo -u www-data bash -lc "cd '${TARGET}' && composer dump-autoload -o --no-interaction --no-scripts"
# Deploy uses --no-scripts; sticky-columns registers hasViews() without shipping views.
sudo -u www-data bash -lc "cd '${TARGET}' && bash scripts/ensure-filament-sticky-columns-views.sh" \
    || echo "!! ensure-filament-sticky-columns-views failed (continuing)"
sudo -u www-data bash -lc "cd '${TARGET}' && php artisan package:discover --ansi" \
    || echo "!! package:discover failed (continuing)"
sudo -u www-data bash -lc "cd '${TARGET}' && php artisan filament:assets" \
    || echo "!! filament:assets failed (continuing; Vite build still required)"

echo "==> npm ci && npm run build in ${TARGET}"
sudo -u www-data bash -lc "cd '${TARGET}' && npm ci && npm run build"
sudo chown -R www-data:www-data "${TARGET}/public/build"

if [[ "${SKIP_REFRESH:-0}" == "1" ]]; then
    echo "==> SKIP_REFRESH=1: config/route cache only (no migrate)"
    sudo -u www-data bash -lc "cd '${TARGET}' && php artisan config:clear && php artisan filament:optimize-clear && php artisan config:cache && php artisan route:cache && php artisan view:clear"
    sudo systemctl reload "${PHP_FPM_SERVICE}" 2>/dev/null || echo "!! Could not reload ${PHP_FPM_SERVICE}"
    echo "==> Code-only refresh complete for ${TARGET}"
    exit 0
fi

ROLE="full"
if [[ "${TARGET}" == *tracepharma-marketing ]]; then
    ROLE="marketing"
fi

if [[ "${SKIP_MIGRATE:-0}" != "1" ]]; then
    echo "==> migrate --pretend (${ROLE}) in ${TARGET}"
    sudo -u www-data bash -lc "cd '${TARGET}' && php artisan migrate --pretend --force"
    echo "==> migrate --force in ${TARGET}"
    sudo -u www-data bash -lc "cd '${TARGET}' && php artisan migrate --force"
    if [[ "${ROLE}" != "marketing" ]]; then
        echo "==> tenants:migrate in ${TARGET}"
        sudo -u www-data bash -lc "cd '${TARGET}' && php artisan tenants:migrate --force" \
            || echo "!! WARNING: some tenants failed to migrate; inspect ${TARGET}"
    fi
fi

echo "==> cache rebuild in ${TARGET}"
sudo -u www-data bash -lc "cd '${TARGET}' && php artisan config:clear && php artisan filament:optimize-clear && php artisan config:cache && php artisan route:cache"
if [[ "${ROLE}" == "marketing" ]]; then
    sudo -u www-data bash -lc "cd '${TARGET}' && php artisan view:clear"
else
    sudo -u www-data bash -lc "cd '${TARGET}' && php artisan view:clear && php artisan view:cache" \
        || echo "!! view:cache failed (continuing)"
fi

sudo systemctl reload "${PHP_FPM_SERVICE}" 2>/dev/null || echo "!! Could not reload ${PHP_FPM_SERVICE}"
echo "==> Refresh complete for ${TARGET} (role=${ROLE})"
