#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "Tenant-context Cache::lock() call sites (should be empty outside central/OpenFDA jobs):"
if rg 'Cache::lock\(' app/ \
  --glob '*.php' \
  --glob '!app/Jobs/ImportFdaDatasetJob.php' \
  --glob '!app/Support/OpenFda/*'; then
  exit 1
fi

echo "(none — OK)"
echo
echo "EpcisCacheLock adoption under app/:"
rg -c 'EpcisCacheLock::(store\(\)->lock|lock)\(' app/ --glob '*.php' | awk -F: '{sum += $2} END {print sum + 0}'
