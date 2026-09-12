#!/usr/bin/env bash
# zeeshantariq/filament-sticky-columns registers ->hasViews() but ships no
# resources/views (upstream PR #5). Empty dir keeps `php artisan view:cache` alive.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VIEWS_DIR="${ROOT}/vendor/zeeshantariq/filament-sticky-columns/resources/views"

if [[ ! -d "${ROOT}/vendor/zeeshantariq/filament-sticky-columns" ]]; then
    exit 0
fi

mkdir -p "${VIEWS_DIR}"
