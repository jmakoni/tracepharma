# TracePharma package decisions

Zero-copy greenfield under `/dpool/tracepharma`. No code was copied from vatengitracerx or other apps.

| Capability | Package | Decision |
|---|---|---|
| Admin / tenant UI | `filament/filament` ^5 | First-party Filament 5 panels |
| Profile UX | `jeffgreco13/filament-breezy` | My Profile for Admin + App |
| Multi-tenancy | `stancl/tenancy` ^3.10 | Database-per-tenant, domain identification |
| Permissions | `spatie/laravel-permission` ^8 | Roles on tenant users |
| Audit trail | `spatie/laravel-activitylog` ^5 | Who/what/when on regulated changes (wired later) |
| Queues | `laravel/horizon` + Redis | Async work for later EPCIS/VRS jobs |
| Search | `laravel/scout` + Meilisearch | Tenant Product/Partner/EPCIS indexes; see `docs/search.md` |
| API tokens | `laravel/sanctum` | Token mint UI + lean EPCIS inbound/list API stubs |
| PDF | `dompdf/dompdf` | In use for SSCC labels |
| Object / SFTP storage | Flysystem AWS S3 + SFTP | Inbound/outbound files (later) |
| CSS | Tailwind CSS 4 + DaisyUI 5 | App styling |

## Custom (no suitable package)

| Area | Approach | Phase |
|---|---|---|
| Master data (products, partners, sites, read points, devices, location devices, ATP licenses) | Custom tenant models + Filament resources | Phase 2 |
| Central app master-data catalogs (products, partners, sites, devices, location devices, ATP licenses) | Custom central models + Admin Filament; tenant forms prefill via `CatalogPrefill` (no package) | Phase 2 |
| HQ site auto-creation for a trading partner | `App\Actions\MasterData\CreateHqSiteForTradingPartner`, called from Filament page hooks (no DB triggers) | Phase 2 |
| EPCIS 1.2 tenant schema + scan resolve | Custom tenant tables (`epcis_*` / `epcs`) + `App\Actions\Epcis\*` + `App\Support\Gs1\{Sgtin,Sscc,ElementString}` | Schema + resolve shipped |
| EPCIS 1.2 parse/store pipeline | Custom Actions + Jobs (`IngestEpcisXmlDocument`, `IngestEpcisXmlJob`) | Shipped |
| VRS client | HTTP Action + Fake for tests; `VerifyProduct` page | Shipped ICP: clients + history detail + dispense-check + responder; async staged-scan verify later |
| GS1 identifier helpers | `App\Support\Gs1\*` (Gtin, Ndc, Sgtin, Sscc, ElementString) | In use |
| Tenant DB naming | `App\Support\TenantDatabaseName` | Phase 1 |
| Feature gating | `App\Support\TenantFeatures` | Phase 1 |
| Asset URLs under tenancy | `tenancy.filesystem.asset_helper_tenancy = false` so Filament/public CSS+JS are not rewritten to `/tenancy/assets/*` | Phase 1 fix |
