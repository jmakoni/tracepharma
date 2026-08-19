# TracePharma architecture

## Databases

- **Central** MariaDB (`tracepharma`): tenants, domains, platform `admins`, Horizon/jobs/cache/session for central.
- **Tenant** MariaDB (`tenant_{domain_with_underscores}`): tenant `users`, Spatie permissions. Central keeps activitylog/permission tables for later admin use.

Tenancy: `stancl/tenancy` with `InitializeTenancyByDomain` on the App panel.

## Panels

| Panel | Host | Guard | Model |
|---|---|---|---|
| Admin | `ADMIN_DOMAIN` (default `admin2.internal.vatengi.com`) | `admin` | `App\Models\Admin` |
| App | Tenant domains (e.g. `demo2.internal.vatengi.com`) | `web` | `App\Models\User` |

## Feature gating

`TenantProfile` → `TenantFeatures` → Filament `canAccess()` / navigation. Optional tenant setting `access.job_roles_enabled` further restricts via Spatie `nav.*` capabilities (`JobRoleAccess`). Spatie roles are seeded per profile; personas only enforce when that flag is on. Enabling job roles in Organization → Access runs `TenantRoleSeeder` automatically; **Owner** always retains Organization Settings access as an escape hatch.

## Lean rules

Thin helpers only (`TenantFeatures`, `TenantDatabaseName`). Simple Actions for one-shot work (`SeedMasterData`). No service layer for Phase 0–2. Later business logic goes in simple Actions.

## Phase 2 master data (tenant DB)

Products, Trading Partners, Sites, Read Points, Devices, Location Devices (GLN/SGLN locations), ATP Licenses — Filament App resources under **Master Data**, gated by `TenantFeatures::supportsMasterData()`.

- `devices` = physical hardware (scanners, printers); `location_devices` = GLN/SGLN-bearing locations. Both are site-scoped.
- ATP licensing lives on `atp_licenses` / `catalog_atp_licenses` (site-scoped), not on trading partners.
- FDA WDD/3PL staging (`fda_wdd_3pl_staging`) promotes into site-scoped `catalog_atp_licenses` via `tracepharma:promote-fda-wdd-3pl-to-sites` (match/create `catalog_sites` by facility address under the matched partner).
- FDA product data is central-only (`fda_products` + related tables); tenant/catalog products link to it via `fda_product_id`.
- Manufacturer/labeler identity is FK-only, never free text: `fda_products.catalog_trading_partner_id` and `catalog_products.catalog_trading_partner_id` point at `catalog_trading_partners`; tenant `products.trading_partner_id` is an optional **manufacturer** mirror (labeler → tenant manufacturer partner), not the sole receive-from link. `App\Support\PartnerSlug` is only used to *resolve* a partner during import/backfill, never stored as a display string.
- Product identity is **NDC11-primary** (`products.ndc11`, derived from `package_ndc` / `ndc` via `App\Support\Gs1\Ndc`); GTIN is optional when present on catalog. Find-or-create and dedupe prefer NDC11, then `catalog_product_id`, then GTIN.
- Receive-from assortment is `trading_partner_product` (unique `trading_partner_id` + `product_id`): which partners this tenant expects to receive a given product from (wholesaler, distributor, 3PL, or manufacturer direct). The same product can attach to multiple partners via the pivot with per-partner authorization (`authorization_status`, `authorized_at`, optional `partner_item_number`, `uom_code`, `units_per_case`, `is_primary`).
- A trading partner's headquarters `Site`/`CatalogSite` is created by `App\Actions\MasterData\CreateHqSiteForTradingPartner` (no DB triggers), invoked from the `afterCreate()` hook on the partner's create page.
- **One headquarters per owner.** Saving a site with `is_headquarters` set demotes its siblings: `Site` scopes that to the owning trading partner (or the organization when the site carries none), `CatalogSite` to the `catalog_trading_partner_id`. The catalog invariant matters because a tenant facility with no catalog row of its own inherits the licenses of its partner's catalog HQ; where legacy rows still leave two, `CopyCatalogAtpLicensesToTenantSite` takes the lowest id so the inherited set does not change with row order.

### Assortment vs manufacturer identity

- **Receive-from** is the `trading_partner_product` pivot (unique `trading_partner_id` + `product_id`): which partners the tenant expects to receive a given product from, with per-partner authorization and optional SKU/UOM/units/case/primary flags.
- **Manufacturer** is a separate optional FK on `products.trading_partner_id` — labeler mirror only, not a substitute for the pivot. Compliance rollups require both authorized pivots and a resolved manufacturer (or a single manufacturer receive-from).

### ATP licensing (catalog → tenant)

> **What ATP readiness is and is not.** The FDA WDD/3PL report is a **license listing that registrants self-report**; the FDA republishes it without adjudicating it. Together with licenses typed in by hand, it is all `SiteAtpReadiness` has. A `Ready` badge therefore means *our records show a license in force for the receiving state* — it is **not** FDA approval, FDA verification, or proof of licensure, and no UI copy may say it is. A facility that leaves the report was **dropped from the FDA listing**, which is not the same as a state revoking its license. Confirm with the state board before onboarding a new partner. Shared wording lives in `App\Support\MasterData\AtpDisclosure`.

- Central **source of truth:** `catalog_atp_licenses` (site-scoped on `catalog_sites`). FDA WDD/3PL staging promotes into catalog sites/licenses via `tracepharma:promote-fda-wdd-3pl-to-sites` (also available as `--promote` on `tracepharma:import-fda-wdd-3pl`).
- **License identity is the site plus the state/number pair** in both databases: unique on `(catalog_site_id, license_state, license_number)` and `(site_id, license_state, license_number)`. The FDA promote upserts on that key; when a license lands on a new site, the partner's older copies are deleted (`licenses_relocated`) so one number is never attached to two of a partner's sites.
- **Delisted catalog licenses:** staging holds one full FDA snapshot per import, so a promote run also sets `catalog_atp_licenses.is_active = false` on rows it did not list (`licenses_delisted`) — a facility dropped from the FDA listing must not keep reading as Ready. Delisting records the FDA's silence, not a revocation. Rows are kept as history and turn active again when a later report lists them. The prune is scoped to the partners in that snapshot (within one, the report is the authority even over hand-entered rows), skipped on `--dry-run`, and skipped entirely when the run upserted nothing, so an empty or half-loaded staging table delists nothing. Only active catalog licenses are copied to tenant sites, so a delisted license deactivates its tenant copies on the next sync.
- Tenant copy: `atp_licenses` on tenant `sites` — idempotent copy from catalog when a tenant site is linked to catalog (`catalog_site_id`) or HQ is created/matched for a catalog-linked partner. `CopyCatalogAtpLicensesToTenantSite` uses `updateOrCreate` on `(site_id, license_state, license_number)` so re-runs refresh expiration dates and metadata without duplicating rows. Called from `CreateHqSiteForTradingPartner` and `EnsureManufacturerPartnerFromCatalog` / wholesaler ensure paths.
- Each copied row records `atp_licenses.catalog_atp_license_id`. When the catalog counterpart disappears (for example a facility dropped from the FDA file after the staging truncate), sync sets `is_active = false` instead of deleting, and reactivates the row if the catalog license returns. Licenses entered by hand have no catalog id and are never pruned. `SiteAtpReadiness`, relation-manager badges, and readiness filters all count active licenses only.
- **Eligible sites for resync** are partner sites with a `catalog_site_id`, partner HQ sites of catalog-linked partners, and any partner site already carrying a catalog-stamped license (`atp_licenses.catalog_atp_license_id`) — chain branches that stand in on HQ licenses, whose copies would otherwise never be refreshed or pruned.
- **Organization facilities are excluded** from catalog copy and from `SyncTenantAtpLicensesFromCatalog::eligibleSites()`: our own docks' licenses are tenant regulatory records, so a partner's catalog data can never make them look licensed. `tracepharma:clean-org-facility-atp` repairs facilities that an earlier ingest stamped with a partner catalog site (deactivates catalog-synced licenses, clears the partner `catalog_site_id`).
- **The two ATP gates agree by construction:** the outbound send blocker (`ValidateOutboundShippingSend`) and the ingest soft warning (`RecordAtpSoftWarning`) both judge the facility the order or document names, and when none is named they judge the party as a whole, where one ready address clears it (`AtpReadinessGate::blocksParty()`). A party with no active address on record is never faulted by either; an unresolvable outbound ship-to GLN is refused outright instead.
- **Readiness statuses:** `Ready` requires every relevant license to have a future expiration. A license with no expiration date reads as `Unknown expiry` (warning, not ready) in both the site badge and the license status badge, since an undated license cannot be shown to be in force.
- **Honest labeling:** every surface that shows readiness or blocks on it carries `AtpDisclosure::SOURCE` (readiness panel, ATP Licenses table, Sites `ATP readiness` column tooltip) or `AtpDisclosure::SHORT` (outbound send blocker, ingest soft warning). Gate messages say a license is missing or expired **on record** rather than calling a partner unlicensed.
- **Tenant ATP sync:** After WDD promote (CLI `--promote` or Admin panel), `SyncTenantAtpLicensesFromCatalog` jobs copy refreshed catalog licenses to all eligible tenant sites. Belt-and-suspenders monthly refresh: `tracepharma:sync-tenant-atp-from-catalog` (scheduled 1st of month 05:00, after the 04:00 WDD import).

### Catalog soft references (tenant DB)

- Tenant rows keep optional `catalog_trading_partner_id`, `catalog_site_id`, and `catalog_product_id` as **application-level links** to central catalog rows — no cross-database foreign keys; resolve and copy in Actions, not DB constraints.
- **Catalog deletes are restricted, not cascaded.** Central `catalog_trading_partner_id` / `catalog_site_id` foreign keys are nullOnDelete, so an unguarded delete would orphan catalog children rather than fail. `CatalogTradingPartnerReferences` refuses a partner delete while any `catalog_sites` or `catalog_products` row still belongs to it; `CatalogSiteReferences` refuses a site delete while any `catalog_atp_licenses` row still names it. Both are enforced by a model `deleting` hook, by `CatalogTradingPartnerPolicy` / `CatalogSitePolicy` (delete also needs the `catalog.manage` admin permission) and surfaced as a Filament notification before the action runs.
- **Dangling tenant links are not healed by the delete.** The admin panel runs on the central connection and cannot reach tenant databases, so a catalog partner or site that does get deleted leaves tenant `catalog_trading_partner_id` / `catalog_site_id` values pointing at nothing. Healing those needs a separate per-tenant pass; until then the tenant side degrades gracefully — `EnsureManufacturerPartnerFromCatalog` and `EnsureWholesalerPartnerFromCatalog` log a warning and return `null` on a missing catalog id, and `PartnerSiteCreate` reports a readable message instead of failing.

### FDA WDD/3PL ops (Admin + CLI)

The WDD/3PL pipeline is a **license listing import**, not a partner authorization step: it records what registrants self-reported to the FDA. See the disclaimer under [ATP licensing](#atp-licensing-catalog--tenant) — admin headings and modal copy must not imply the import authorizes anyone.

- **Admin FDA Registry → WDD/3PL Staging:** list panel with header actions **Import WDD/3PL** (truncate + reload staging; optional fresh download) and **Promote to catalog** (upsert catalog sites/ATP licenses, then queue tenant ATP sync). Subheading states the self-reported provenance and shows staging/unpromoted/unmatched counts.
- **Import:** `tracepharma:import-fda-wdd-3pl` loads `fda_wdd_3pl_staging`; rows with no catalog partner match are skipped and counted as `skipped_unmatched`.
- **Promote guards live in the action**, so the Admin button and both commands are covered alike: an import run left without `completed_at` blocks the promote (`FdaStagingImportIncompleteException`), and a staging table holding under half the rows of the last completed import blocks it too (`FdaStagingCollapsedException`, measured by `FdaStagingSnapshotSize`) — a truncated download would otherwise read as a mass withdrawal of licenses. Both commands take `--force`; the Admin modal grows a **Promote anyway** toggle only when staging has collapsed. A `--dry-run` writes nothing and is never blocked on size.
- **Older reports never age a license:** the promote upsert compares `reporting_year` against the stored row. A lower year writes nothing but `is_active`, and within the same year an earlier `license_expiration_date` is ignored; only a newer report may shorten an expiration.
- **Unmatched triage:** unmatched facilities are persisted in central `fda_wdd_3pl_unmatched` (facility name, slug attempt, FDA type, row count, last seen, resolution). **Admin FDA Registry → WDD/3PL Unmatched** lists open rows with **Create organization** / **Link existing organization** actions to resolve triage without re-import; the link modal lists organizations of the same name family first and the create modal proposes the partner type from the recorded FDA type. Linking files a listing against an organization; it does not authorize the partner.
- **Triage feeds the next import:** `ImportFdaWdd3plStaging` reads resolved `fda_wdd_3pl_unmatched` rows (by facility name, parent name and `slug_attempt`) alongside the organization slug index, so a resolution taken today stages that facility's rows on the next weekly run without re-triage. Top-6 wholesaler entity names (`Cardinal Health 110, LLC`, `McKesson Corporation (Anchorage)`, `AmerisourceBergen Drug Corporation`) roll up through `MajorWholesalers::canonicalSlug()`.
- **CSV report:** when any rows are skipped (or `--report` is passed), a CSV is still written to `storage/app/fda/wdd_unmatched_{date}.csv` listing unmatched facility names and counts.
- **Schedule** (`routes/console.php`): the WDD/3PL chain runs **weekly on Sunday** — **04:00** `tracepharma:import-fda-wdd-3pl --fresh-download --promote`, **05:00** `tracepharma:sync-tenant-atp-from-fda` (the import already dispatches sync jobs; the later run is a safety refresh). License expirations and unmatched triage would otherwise sit on a month-old snapshot. DECRS stays monthly: 1st of month **03:30** — `tracepharma:import-fda-decrs --fresh-download`. Daily **03:00** — `tracepharma:recalc-fda-establishment-registration`; **07:00** — `compliance:alert-license-expiry`.

### Pure FDA registry (central)

Official FDA feeds load into `fda_organizations`, `fda_establishments` (+ operations), `fda_wdd_facilities` / `fda_wdd_licenses`, then map onto existing `catalog_*` rows. Tenant databases still hold only soft catalog IDs.

- **DECRS:** `tracepharma:import-fda-decrs` (`docs/fda-decrs.md`). Legal entity is `REGISTRANT_NAME` / `REGISTRANT_DUNS`. Skip blank FEI. `EXCLUSION_FLAG` `Y`/`N` → `exclusion_flag` 1/0.
- **WDD/3PL:** the existing staging import still feeds the Admin UI; the same command also upserts Pure FDA facilities/licenses.
- **Org match:** exact canonical/DUNS link; unique high fuzzy auto-link; ambiguous → `fda_organization_match_reviews`; novel names auto-create.
- **Site map:** FEI-linked site, then WDD-facility-linked site, then address fingerprint under the same organization, else create. One `catalog_sites` row may hold both `fda_establishment_id` and `fda_wdd_facility_id`.
- **`fda_products.catalog_trading_partner_id`** remains the catalog bridge; `fda_organization_id` is the pure-registry link.

## EPCIS ingest schema (tenant DB)

Tenant-only EPCIS 1.2 / GS1 US R1.3 tables under `database/migrations/tenant/` (no central event warehouse). There is **no** EPCIS `organizations` or duplicate `products` table — GLNs resolve to `trading_partners` / `sites` / `location_devices` / `read_points`, and serialized items link to tenant `products` via `epcs.product_id` (NDC11 / GTIN).

| Table | Role |
|---|---|
| `epcis_documents` | Message envelope, payload path (no `payload_raw` in v1), SBDH `sender_gln` / `receiver_gln`, DSCSA flags, status |
| `epcis_events` | Object/Aggregation/Transaction/Transformation/Association events |
| `epcs` | Instance identity (`epc_uri`) + materialized scan keys |
| `event_epcs` | Event ↔ EPC membership (roles: epcList, childEPC, parentID, …) |
| `aggregation_links` | Durable parent/child hierarchy (instance graph; not SKU pack links) |
| `event_parties` / `event_locations` / `event_biz_transactions` | Parties, locations, PO/ASN refs with GLN snapshots + optional master-data FKs |
| `epcis_unmatched_glns` | GLNs from SBDH / events that did not resolve to master data (deduped per document+context) |
| `epc_ilmd` | Lot / expiry / manufacturing ILMD per EPC |
| `transmission_mdns` | MDN / transmission ack tracking |
| `epcis_exceptions` | Ingest / compliance exceptions |

Models live in `App\Models\Epcis\`. Resolve helpers (no auto-create of partners/products):

- `App\Actions\Epcis\ResolveGlnToMasterData`
- `App\Actions\Epcis\ResolveProductFromIdentifier`
- `App\Actions\Epcis\MaterializeEpcKeys` / `EnsureEpcFromUri`
- `App\Actions\Epcis\ResolveEpcFromScan`
- `App\Actions\Epcis\IngestEpcisXmlDocument` (sync ingest) + `App\Jobs\IngestEpcisXmlJob` (queued upload path)

**Receiving UI** — Filament `EpcisDocumentResource` (slug `inbound-epcis`) lists catalog inbound documents (`received_via` in `filament_upload` | `https_webhook_hub` | `https_webhook` | `sftp_poll`). Header **Upload EPCIS** always stores `direction=inbound` + `received_via=filament_upload` then processes. CLI / untagged internal receives stay off this list. View page relation managers cover events, EPCs, exceptions, and unmatched GLNs.

**EPCIS hub (Systech / UniTrace)** — One Admin configures **demo**, **stage**, and **prod** hub edges (`EpcisHubSettings` → `platform_settings` / `EpcisHubPlatformConfig`). Default hosts: `admin2.internal.vatengi.com` (demo), `stage.tracepharma.io`, `prod.tracepharma.io`. Partners POST to the environment host: `POST https://{demo|stage|prod-host}/api/webhooks/epcis/hub/{provider}` with `X-Epcis-Hub-Token` (platform token for that host wins over env fallbacks). Router resolves SBDH receiver GLN → tenant, then requires `tenants.inbound_environment` to match the request host’s environment and `tenants.hub_providers` to include the provider. App **Register for hub routing** is shown only when the tenant is granted that provider for an env that has it enabled; hub URL on the connection view uses the tenant’s environment host. Per-connection webhooks remain available alongside the hub.

**EPCIS Jobs (Phases 1–2)** — Gated by `TRACEPHARMA_EPCIS_JOBS_ENABLED` / `config('tracepharma.epcis_jobs.enabled')`. Phase 1: `ScheduleOutboundEpcisTransmission` enqueues a tenant `epcis_jobs` ledger row + `TransmitEpcisJob` (queue `epcis`) instead of sync transmit; App **EPCIS Jobs** (Operations) supports cancel (queued), requeue (error/cancelled; shipping rebuilds XML via `BuildFullHistoryShippingEpcisXml`), and archive. Phase 2 attaches an `inbound_process` ledger to the existing `ProcessEpcisDocumentJob` (no second pipeline); requeue = reprocess + new receipt. Document catalogs (Inbound/Outbound EPCIS) stay separate. Flag off keeps sync transmit / direct process dispatch.

`EpcisXmlReader` extracts EPCClass vocabulary (`product_classes` with `idpat` + NDC-11) so ingest can attach `epcs.product_id` via NDC/GTIN. Partitioning deferred; date columns are indexed for archival.

### Receiving sessions

Receiving is live for Pharmacy / DrugWholesaler / Prepackager / Logistics3pl / DentalMedicalSupply (`TenantFeatures::supportsReceiving()`; Manufacturer/BuyingGroup stay gated off). Sessions are keyed by `receiving_sessions.session_kind`:

- **`inbound_asn`** — `App\Actions\Receiving\OpenReceivingSessionFromDocument` opens from a `parsed`/`validated` inbound `EpcisDocument`, seeding `receiving_scan_lines` with the document's root SSCC parents (`epcis_document_id` set).
- **`scan_first`** — `App\Actions\Receiving\OpenScanFirstReceivingSession` opens an ASN-free ledger (`epcis_document_id` null). Scans must resolve an existing EPC (no invent). TI soft/hard via `TenantSettings::requireTiForScanFirst`. `App\Support\Receiving\ResolveReceiveScanContext` supplies HUD chips (TI, quarantine, ASN match, in-transit transfer); confirm may persist `matched_epcis_document_id` without binding the session to that ASN.
- **`transfer_receive`** — `App\Actions\Receiving\OpenTransferReceivingSession` opens from an `in_transit` `TransferringSession`, seeding expected lines from confirmed transfer EPCs.

`App\Actions\Receiving\ConfirmReceivingScan` branches by kind (ASN parent→child / scan-first / transfer receive), gated by `App\Services\Receiving\ReceivingGate` (open quarantine holds / document-wide exceptions block ASN confirms). `App\Actions\Receiving\CompleteReceivingSession` likewise branches: ASN is usually already completed by confirm (`maybeCompleteSession`); scan-first requires manual complete then `GenerateReceivingEpcisEvents`; transfer_receive completes the linked transfer and runs `GenerateTransferringReceiveEpcisEvents`. The Filament HUD (`ViewReceivingSession` + its Blade view) is profile-aware via `App\Support\Receiving\ReceivingPolicy` — preferred scan level (pallet vs tote/case), whether "sealed pallet" auto-confirms children by default, and the on-screen scan/confirm copy all come from the tenant's `TenantProfile`.

**Active / History (site-scoped):** Receive and Transfer indexes use Active vs History tabs (no separate nav). Lists stay site-scoped via `SiteAccess` / `sites.access_all` (transfer: `from_site_id` OR `to_site_id`; receive: `site_id`). Soft default site filter uses `CurrentSite` when set. Transfer ↔ `transfer_receive` sessions deep-link on list and detail.

**Event generation (Phase 2):** when an ASN session completes (`maybeCompleteSession` → `CompleteReceivingSession` via `GenerateReceivingEpcisEvents`), or a scan-first session is manually completed, the platform emits an authored `EpcisDocument` (`direction=outbound` meaning we wrote the file — UI: **Generated receiving**) with an `ObjectEvent` (`OBSERVE`, `biz_step` receiving, `disposition` `in_progress`, `eventTime`/`recordTime`/`eventTimeZoneOffset`/`eventID`, required site `readPoint`/`bizLocation` SGLNs built from receive-site or org GLN + company prefix — complete fails loudly and reverts session status if SGLN cannot be built — and inbound `po`/`desadv` biz transactions when present). Confirmed EPCs only (including sealed auto-confirmed children for local custody; not TI/TS — `dscsa_affirm` stays false). Idempotent via `receiving_sessions.receiving_events_generated_at`. Payload write is outside the DB transaction (local disk fallback if preferred disk fails). Optional unpacking (`$unpack = true`, gated by `ReceivingPolicy::canUnpackAtReceive()` / `canUnpackAfterReceive()`) emits one `AggregationEvent` `DELETE` per parent and closes open `aggregation_links` — sealed intact hierarchy does not unpack. `App\Support\Receiving\ReceivingWorkflow` is a thin facade over policy/confirm/complete for callers that don't need the individual Actions.

VRS shell: Filament `VerifyProduct` page with Fake/Http clients (`App\Services\Vrs\*`). Verification history list + detail (`VerificationResource`, `ViewVerification`) shipped for VRS-gated tenant profiles.

### Scan search contract

Floor scans must hit the same row whether the system stored a Pure Identity URN or the operator scanned AI digits.

**SGTIN** — ingest `urn:epc:id:sgtin:…` materializes `gtin14`, `serial_number`, `ai_01_21` (`01`+GTIN+`21`+serial). Lookup via `ai_01_21` or `(gtin14, serial_number)`. Extra `(17)`/`(10)` soft-check `epc_ilmd` after identity match.

**SSCC** — ingest `urn:epc:id:sscc:…` materializes `sscc18` and `ai_00` (`00`+SSCC-18) using barcode order: extension digit + company prefix + serial body + check digit. Lookup accepts bare 18 digits, 20-digit `00`+SSCC, or `(00)…`.

GS1 helpers: `App\Support\Gs1\{Gtin,Sgtin,Sscc,ElementString,Ndc}`.

## OpenFDA NDC catalog backfill

Central-only import (not run by `setup-demo`):

```bash
php artisan tracepharma:import-openfda-ndc --stage=partners
php artisan tracepharma:import-openfda-ndc --stage=products
# or --stage=all
```

Downloads `drug-ndc-0001-of-0001.json.zip` into `storage/app/openfda/` (or pass `--path=` to a local json/zip). Stage 1 inserts unique manufacturers as `catalog_trading_partners` keyed by `slug` (`Str::slug(labeler_name)`, `partner_type=manufacturer`). Stage 2 upserts `fda_products` (+ children) and creates one `catalog_products` row per `package_ndc` with GTIN from UPC (single-package only) or `003`+NDC10+check (`App\Support\Gs1\Gtin`); both rows get `catalog_trading_partner_id` set by resolving the labeler's slug against `catalog_trading_partners` (left `null`, counted as `missing_partner`, if no partner matches — no free-text fallback). Re-run slug merges with `php artisan tracepharma:dedupe-catalog-partners`.

### Drugs@FDA package_ndc backfill

The NDC directory's per-product `packaging[]` array is sometimes incomplete — Drugs@FDA's `openfda.package_ndc` array can list additional package NDCs (e.g. `0116-4005-40`, `0116-4005-41`) for an application that never appear in the NDC directory. Run this after the NDC import to fill those gaps:

```bash
php artisan tracepharma:import-openfda-drugsfda
```

Downloads `drug-drugsfda-0001-of-0001.json.zip` into `storage/app/openfda/` (or pass `--path=` to a local json/zip; `--fresh-download` forces a re-download). For each Drugs@FDA result, matches `openfda.product_ndc` entries to existing `fda_products` rows (never creates new `FdaProduct` rows — the NDC import remains the source of truth for products) and, for every `openfda.package_ndc` string, upserts `fda_product_packaging` (`fda_product_id` always set to the matched product; existing `description` is preserved) plus a derived `catalog_products` row via `Gtin::forPackaging(null, $packageNdc)`. When an application has multiple `product_ndc` values, a `package_ndc` is attributed to the product_ndc it's prefixed with (`{product_ndc}-`); packages that can't be attributed to any matched product, or whose product_ndc has no matching `FdaProduct`, are skipped and counted under `skipped_no_fda_product`. See `App\Actions\OpenFda\ImportOpenFdaDrugsFdaPackages`.

Both importers write `catalog_products.strength` from every active ingredient, joined with `; ` (`5 mg/1; 160 mg/1`) via `App\Support\Catalog\IngredientStrength` — a combination product is not described by its first ingredient, and single-ingredient products keep the value they always had.

### Catalog maintenance after an import

Both importers write the catalog with `withoutSyncingToSearch()`, so a bulk import never depends on Meilisearch being up. Run these afterwards (each takes `--dry-run` where it writes):

```bash
php artisan catalog:dedupe-package-ndc   # one active row per package NDC
php artisan catalog:backfill-ndc11       # recompute the UNIQUE ndc11 owners
php artisan catalog:reindex-products     # push the catalog into the Scout index
```

`catalog:dedupe-package-ndc` groups rows by the canonical NDC-11 of their package NDC (so 4-4-2 / 5-3-2 / 5-4-2 spellings count as one package) and deactivates the twins left behind by the old GTIN-keyed upsert, keeping the row that publishes the NDC-encoded GTIN, else the lowest id — the same winner `catalog:backfill-ndc11` gives the NDC-11 to. Twins are deactivated, never deleted, since tenant products may reference them.

`catalog:reindex-products` is a thin `scout:import` equivalent that also drops rows that must no longer be searchable (deactivated duplicates), so one pass leaves the index matching the table. Catalog search itself falls back to SQL `LIKE` when the engine cannot answer (`App\Support\Catalog\CatalogProductSearch`): an unreachable index must never make the catalog look empty to someone receiving a shipment.

The index is also skipped, not just fallen back from, when the caller's base query narrows past the whole active catalog — `CatalogProductSearch::query(..., forceSql: true)`. Meilisearch is asked for the best matches catalog-wide and the SQL filter then discards them, so "packages of this FDA listing" or "catalog products this partner has not linked yet" would come back short or empty while the catalog holds matches. The two constrained callers (`AddFdaProductPackagesAction`, the partner `ProductsRelationManager`) pass the flag; unconstrained callers keep using the index.

### Deleting from the app catalog

Every tenant reads from the central catalog, so a delete there is felt in every tenant at once. The rule follows what the referencing rows can be shown to be:

- **Catalog partners and sites** can be audited before the delete, because the rows naming them (`catalog_sites`, `catalog_products`, `catalog_atp_licenses`) sit in the same database. `CatalogTradingPartnerReferences` / `CatalogSiteReferences` refuse the delete while any remain, the model `deleting` hook throws `DomainException`, and the Filament action reports the count instead of orphaning them.
- **Catalog products are never deleted.** Tenant `products.catalog_product_id` is a bare id in each tenant's own database — no foreign key, nothing central to consult — so no query could honestly clear the delete, and a tenant product whose catalog id stops resolving loses its manufacturer, NDC-11 and authorization trail. `CatalogProductDeletion` throws from the `deleting` hook (lift it with `CatalogProductDeletion::force()` for repair paths that know the row was never published), `CatalogProductPolicy::delete()` returns false so Filament shows no Delete or Delete-selected at all, and the catalog product screens offer Deactivate / Activate instead — the same retirement `catalog:dedupe-package-ndc` performs.
- **Catalog devices and location devices** have no central referrers to audit and, for location devices, no active flag to retire with, so `catalog.manage` is the gate. `CatalogDeleteAction` re-checks it when the delete runs and says why it was refused.

Admin catalog product writes (create, edit, deactivate, and `SeedCatalogMasterData`) go through `App\Support\Catalog\CatalogSearchSync`: the row is committed with Scout detached, then `SyncCatalogProductToSearchIndex` takes the ids to the index on the queue with retries. Scout indexes on `saved` inside the request, so without this a Meilisearch outage reports a failed save for a row that was in fact written — and the admin re-submits against changed data. A failed dispatch is reported and the save still succeeds; `catalog:reindex-products` is the backstop for index lag.

