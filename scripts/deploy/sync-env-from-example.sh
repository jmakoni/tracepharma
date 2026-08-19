#!/usr/bin/env bash
# Add missing keys from .env.example into a live deploy .env.
# Existing values (including secrets) are never overwritten, except FORCE_KEYS.
# Usage: scripts/deploy/sync-env-from-example.sh <marketing|stage|prod> <target.env>
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
EXAMPLE="${ROOT}/.env.example"
PROFILE="${1:?profile required (marketing|stage|prod)}"
TARGET="${2:?target .env path required}"

if [[ ! -f "${EXAMPLE}" ]]; then
  echo "Missing ${EXAMPLE}" >&2
  exit 1
fi

if [[ ! -f "${TARGET}" ]]; then
  echo "Missing ${TARGET}" >&2
  exit 1
fi

python3 - "${EXAMPLE}" "${TARGET}" "${PROFILE}" <<'PY'
import sys
from pathlib import Path

example_path, target_path, profile = sys.argv[1:4]

# Used only for keys that are absent from the live file.
PROFILE_DEFAULTS = {
    "marketing": {
        "APP_ENV": "production",
        "APP_DEBUG": "false",
        "APP_URL": "https://tracepharma.io",
        "LOG_LEVEL": "info",
        "CENTRAL_DOMAIN": "tracepharma.io",
        "MARKETING_DOMAIN": "tracepharma.io",
        "PLATFORM_BASE_DOMAIN": "tracepharma.io",
        "TENANT_ENVIRONMENT": "prod",
        "DB_DATABASE": "tracepharma_marketing",
        "QUEUE_CONNECTION": "database",
        "CACHE_STORE": "redis",
        "FILESYSTEM_DISK": "local",
        "SCOUT_QUEUE": "false",
        "MARKETING_DEMO_NOTIFY_EMAIL": "sales@tracepharma.io",
        "MARKETING_ONBOARDING_NOTIFY_EMAIL": "sales@tracepharma.io",
    },
    "stage": {
        "APP_ENV": "staging",
        "APP_DEBUG": "true",
        "APP_URL": "https://stage.tracepharma.io",
        "LOG_LEVEL": "info",
        "CENTRAL_DOMAIN": "stage.tracepharma.io",
        "MARKETING_DOMAIN": "tracepharma.io",
        "ADMIN_DOMAIN": "stage.tracepharma.io",
        "PLATFORM_BASE_DOMAIN": "tracepharma.io",
        "TENANT_ENVIRONMENT": "stage",
        "PAIR_SIBLING_DB_DATABASE": "tracepharma_prod",
        "DB_DATABASE": "tracepharma_stage",
        "QUEUE_CONNECTION": "redis",
        "CACHE_STORE": "redis",
        "FILESYSTEM_DISK": "local",
        "EPCIS_HUB_HOST_STAGE": "stage.tracepharma.io",
        "EPCIS_INBOUND_BUCKET": "tracepharma-stage",
    },
    "prod": {
        "APP_ENV": "production",
        "APP_DEBUG": "false",
        "APP_URL": "https://prod.tracepharma.io",
        "LOG_LEVEL": "info",
        "CENTRAL_DOMAIN": "prod.tracepharma.io",
        "MARKETING_DOMAIN": "tracepharma.io",
        "ADMIN_DOMAIN": "admin.tracepharma.io",
        "PLATFORM_BASE_DOMAIN": "tracepharma.io",
        "TENANT_ENVIRONMENT": "prod",
        "PAIR_SIBLING_DB_DATABASE": "tracepharma_stage",
        "DB_DATABASE": "tracepharma_prod",
        "QUEUE_CONNECTION": "redis",
        "CACHE_STORE": "redis",
        "FILESYSTEM_DISK": "s3",
        "EPCIS_HUB_HOST_PROD": "prod.tracepharma.io",
        "EPCIS_INBOUND_BUCKET": "tracepharma-prod",
        "SCOUT_QUEUE": "true",
    },
}

# Always set after merge so admin.tracepharma.io keeps binding to this tree.
FORCE_KEYS = {
    "prod": {"ADMIN_DOMAIN": "admin.tracepharma.io"},
}

if profile not in PROFILE_DEFAULTS:
    raise SystemExit(f"Unknown profile: {profile}")


def parse_env(text: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for line in text.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, value = stripped.split("=", 1)
        values[key.strip()] = value
    return values


example_text = Path(example_path).read_text()
target = Path(target_path)
existing_text = target.read_text()
existing = parse_env(existing_text)
defaults = parse_env(example_text)
profile_defaults = PROFILE_DEFAULTS[profile]
force = FORCE_KEYS.get(profile, {})

added: list[str] = []
for key, example_value in defaults.items():
    if key in existing:
        continue
    value = profile_defaults.get(key, example_value)
    added.append(f"{key}={value}")

forced: list[str] = []
new_text = existing_text
for key, value in force.items():
    if existing.get(key) == value:
        continue
    forced.append(f"{key}={value}")
    if key in existing:
        lines = []
        for line in new_text.splitlines():
            stripped = line.strip()
            if stripped and not stripped.startswith("#") and stripped.split("=", 1)[0].strip() == key:
                lines.append(f"{key}={value}")
            else:
                lines.append(line)
        new_text = "\n".join(lines)
        if not new_text.endswith("\n"):
            new_text += "\n"
        existing[key] = value
    else:
        added.append(f"{key}={value}")

if added:
    block = ["", "# --- Added from /dpool/tracepharma .env.example ---", *added, ""]
    if not new_text.endswith("\n"):
        new_text += "\n"
    new_text += "\n".join(block)
    if not new_text.endswith("\n"):
        new_text += "\n"

target.write_text(new_text)
print(f"Merged {target_path} ({profile}): added {len(added)} keys, forced {len(forced)} keys")
for line in added:
    print(f"  + {line.split('=', 1)[0]}")
for line in forced:
    print(f"  ! {line.split('=', 1)[0]}")
PY
