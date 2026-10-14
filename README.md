# TracePharma

Multi-tenant pharmacy EPCIS / DSCSA platform — receiving, EPCIS ingest, SSCC labeling, exceptions, asset tracking, and VRS shell are live.

## Stack

Laravel 13, Filament 5, stancl/tenancy, Spatie Permission + Activitylog, Horizon, Scout/Meilisearch, Tailwind 4 + DaisyUI 5.

See [docs/package-decisions.md](docs/package-decisions.md) and [docs/architecture.md](docs/architecture.md).

Operator workflow knowledge base (screenshots + CBV biz_step oracle): [docs/workflows/README.md](docs/workflows/README.md).

**In-app help (Guava Filament Knowledge Base):**
- Tenant App: user menu → **Operator help**, or `https://{tenant-domain}/help` (markdown under `docs/knowledge-base/`).
- Admin: user menu → **Admin help**, or `https://{admin-domain}/help` (markdown under `docs/admin-knowledge-base/`).
- Linked pages/resources show a **Help** control (`HasKnowledgeBase`) across workflows, compliance, integrations, master data, settings, exceptions, and admin registry/ops.
- Author sources: `docs/workflows/` (floor SOPs) + `docs/kb-source/app|admin/` (clustered articles). Publish with `php scripts/sync-knowledge-base-docs.php`.
- Marketing / public guides stay on the marketing Blade site (not Guava).

## Local setup

```bash
cp .env.example .env   # or use existing .env
composer install
php artisan key:generate
# MariaDB: create DB `tracepharma` and user (see .env)
php artisan migrate
php artisan tracepharma:setup-demo
npm install && npm run build
```

### Demo hosts (Phase 1)

Demo login credentials are set locally — do not commit. Nginx: add **exact** `server_name` vhosts for the Admin and App hosts pointing at `public/` (do not change an existing wildcard that serves another app).

## Handheld (Android TWA)

Warehouse floor is a Trusted Web Activity around existing `/floor` pages. Laravel serves the Web App Manifest and Digital Asset Links; the thin Android wrapper is `android/floor-twa/`.

- Chooser (QA): `https://floor.internal.vatengi.com/handheld` — operators type a tenant host such as `demo2.internal.vatengi.com`.
- Do not provision test tenants on `*.tracepharma.io`.
- Device QA and Play/MDM notes: [docs/handheld/](docs/handheld/README.md).

## Tests

Tests only use `*_test` databases:

```bash
composer test
```

## Scope note

Shipped surfaces and remaining gaps are tracked in [docs/roadmap-status.md](docs/roadmap-status.md). Still partial: VRS is a shell, OCI is manual evidence, and exception signals must match the case.
