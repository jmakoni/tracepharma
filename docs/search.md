# Tenant Scout / Meilisearch runbook

TracePharma uses Laravel Scout with **per-tenant index names**:

`{SCOUT_PREFIX}{tenant_uuid}_{table}` (for example `13fe9068-…_products`).

Index settings (filterable attributes) live in `config/scout.php` under `scout.meilisearch.index-settings`.

## Environment

| Variable | Purpose |
|----------|---------|
| `SCOUT_DRIVER` | Set to `meilisearch` in production |
| `SCOUT_PREFIX` | Optional shared-cluster prefix (empty in single-app deploys) |
| `SCOUT_QUEUE` | `true` in production so model syncs are queued |
| `MEILISEARCH_HOST` | Meilisearch HTTP URL (default `http://127.0.0.1:7700`) |
| `MEILISEARCH_KEY` | Master key when auth is enabled |

Local Meilisearch (optional):

```bash
docker compose -f docker-compose.meilisearch.yml up -d
```

## Deploy sequence

After Meilisearch is reachable and env is set:

```bash
php artisan tracepharma:scout-sync-index-settings --all-tenants
php artisan tracepharma:scout-reindex-all
```

Or combine settings sync + reindex in one pass:

```bash
php artisan tracepharma:scout-reindex-all --sync-settings
```

New tenants provisioned with `SCOUT_DRIVER=meilisearch` queue `ProvisionTenantScoutIndexes` on `TenantCreated` (empty indexes with correct settings).

## Commands

| Command | Purpose |
|---------|---------|
| `tracepharma:scout-sync-index-settings --tenant=` | Sync settings for one tenant |
| `tracepharma:scout-sync-index-settings --all-tenants` | Sync settings for every tenant |
| `tracepharma:scout-reindex --tenant=` | Import one tenant’s searchable models |
| `tracepharma:scout-reindex-all` | Reindex all tenants |
| `tracepharma:scout-health` | Meilisearch `/health` (+ optional `--tenant=` probe) |
| `tracepharma:scout-health --alert` | Health check; emails ops/admins on failure (hourly when scheduled) |

Stock `scout:sync-index-settings` is **not** tenant-aware — use `tracepharma:scout-sync-index-settings` instead.

## Health monitoring

When `SCOUT_DRIVER=meilisearch`, `routes/console.php` schedules:

`tracepharma:scout-health --alert` hourly.

Failures notify `OPS_ALERT_EMAIL` and platform admins (same pattern as `tracepharma:tenant-health-alert`).
