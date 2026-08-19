# TracePharma

Multi-tenant pharmacy EPCIS / DSCSA platform — receiving, EPCIS ingest, SSCC labeling, exceptions, asset tracking, and VRS shell are live.

## Stack

Laravel 13, Filament 5, stancl/tenancy, Spatie Permission + Activitylog, Horizon, Scout/Meilisearch, Tailwind 4 + DaisyUI 5.

See [docs/package-decisions.md](docs/package-decisions.md) and [docs/architecture.md](docs/architecture.md).

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

| URL | Login |
|---|---|
| `https://admin2.internal.vatengi.com` | `admin@tracepharma.test` / `password` |
| `https://demo2.internal.vatengi.com` | `owner@demo.test` / `password` |

Nginx: add **exact** `server_name` vhosts for `admin2` and `demo2` pointing at `public/` (do not change the existing `*.internal.vatengi.com` wildcard that serves another app).

## Tests

Tests only use `*_test` databases:

```bash
composer test
```

## Scope note

Operational surfaces in place: inbound EPCIS, receiving sessions, SSCC labeling/commissioning, exceptions/quarantine, asset tracking, Verify Product (VRS shell), inbound integrations. Gaps and the locked next workstream (Transferring + outbound connections) are tracked in [docs/roadmap-status.md](docs/roadmap-status.md).
