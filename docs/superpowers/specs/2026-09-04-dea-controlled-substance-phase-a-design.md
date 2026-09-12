# DEA / controlled-substance Phase A (ATTP parity)

Date: 2026-09-04  
Status: Phase A implemented  
Scope: Phase A only (serialization-aware DEA). Not CSOS / Form 222 / ARCOS / SOM.

## Problem

TracePharma already stores:

- Central [`fda_products.dea_schedule`](../../../app/Models/Fda/FdaProduct.php) (openFDA)
- Tenant [`Site.dea_number`](../../../app/Models/Site.php) / [`TradingPartner.dea_number`](../../../app/Models/TradingPartner.php)

Operators never see schedule on receive/ship. Inbound EPCIS that names a party as `DEA:XX000007` does not resolve. A session of CII–CV product can complete with a blank destination DEA number.

Industry: SAP ATTP treats DEA as an **alternate location identifier** that maps to GLN. It does **not** run 222/CSOS/ARCOS. Phase A copies that ATTP shape.

## Goals

1. Surface DEA schedule (CII–CV) on tenant product views and floor HUDs when `Product.fda_product_id` is set.
2. Resolve inbound EPCIS location strings that use a DEA registration as an alternate ID to existing site/partner **GLN** rows. Persist GLN internally. Never store DEA as `bizLocation`.
3. Warn (do not hard-block) when a receive/ship/transfer session contains scheduled product and the relevant party lacks a `dea_number`.
4. Add a warning exception so the signal is durable and correctable like `UNKNOWN_GLN`.

## Non-goals

- DEA-certified CSOS, Form 222 / e222, certificate lifecycle (21 CFR 1311)
- ARCOS 333 generation or upload
- Suspect Order Monitoring
- Vault / cage / perpetual CS inventory
- Dual-control password gate on CII ship (Phase B)
- Hard outbound block when destination DEA is missing (Phase B)
- Using DEA as EPCIS `bizLocation` / SGLN (already rejected in [`2026-08-23-collect-gln-portal-on-send-design.md`](2026-08-23-collect-gln-portal-on-send-design.md))
- Denormalizing `dea_schedule` onto tenant `products` (join via `fda_product_id`)
- Schedule I / CI (existing `deaScheduleLabel()` returns null)

## Identity rules

### Product schedule

| Source | Rule |
|--------|------|
| `FdaProduct.dea_schedule` | Canonical raw value from openFDA |
| Display | [`FdaRegistryStatus::deaScheduleLabel()`](../../../app/Support/Fda/FdaRegistryStatus.php) → `CII` / `CIII` / `CIV` / `CV` or null |
| Tenant product | `$product->fdaProduct?->dea_schedule` only. No new product column. |
| Unlinked product | No schedule chip. Do not invent schedule from NDC at scan time. |

A session “contains scheduled product” when any confirmed/expected line’s EPC GTIN maps to a tenant `Product` whose linked FDA listing has a non-null `deaScheduleLabel()`.

### Party DEA number

| Party | Field | When it is required for the warning |
|-------|--------|-------------------------------------|
| Receive-from (seller) | `TradingPartner.dea_number` else seller site `dea_number` | Inbound / receive session with scheduled product |
| Ship-to (customer) | Destination site `dea_number` else `TradingPartner.dea_number` | Outbound session with scheduled product |
| Transfer destination | Destination site `dea_number` | Transfer session with scheduled product |
| Own ship-from | Organization facility `dea_number` | Outbound/transfer with scheduled product (warn if our site is blank too) |

Normalize DEA numbers for lookup: uppercase, strip spaces/hyphens. Do not invent checksum validation in Phase A (free-text `maxLength(20)` stays).

### Alternate identifier (ATTP registration-type pattern)

Inbound location / party strings that are **not** a 13-digit GLN may be a DEA registration.

Accepted shapes after trim:

| Input | Parsed registration |
|-------|---------------------|
| `DEA:AB1234567` | `AB1234567` |
| `dea/AB1234567` | `AB1234567` |
| `urn:epc:id:dea:AB1234567` (if seen) | `AB1234567` |
| Bare `AB1234563` that fails GLN length/check and matches a stored `dea_number` | that number |

Resolution order in [`ResolveGlnToMasterData`](../../../app/Actions/Epcis/ResolveGlnToMasterData.php) (new pre-step or sibling `ResolveLocationToMasterData`):

1. Existing GLN / SGLN / device / read-point ladder (unchanged).
2. If GLN path empty **and** a DEA registration was parsed: `Site.dea_number` then `TradingPartner.dea_number` (normalized).
3. Return the **same** payload shape (`gln` = the site/partner **GLN**, plus ids). If the matched row has no GLN, return ids but leave `gln` empty and still emit `UNKNOWN_GLN` / unmatched-GLN as today when a GLN is required.

Internal event/document fields continue to store GLN/SGLN only.

## HUD

No product-grouped chips exist today. Phase A adds a single **session-level** security chip, not per-scan labels.

| Surface | Chip |
|---------|------|
| Receiving desktop + mobile HUD | `CII` / `CIII`… when session has scheduled product; append ` · No DEA on seller` when seller DEA blank |
| Outbound shipping HUD | same + ` · No DEA on ship-to` |
| Transfer HUD | same + ` · No DEA on destination` |
| Tenant Product infolist / table | DEA badge via `deaScheduleLabel()` (admin FDA table already has this) |
| Tenant FDA Products infolist | Show schedule (admin already does; tenant currently omits it) |

Chip color: danger for CII, warning for CIII–CV. Missing-DEA suffix uses warning.

Reuse receiving `chipHasTi` / context-chip pattern in:

- [`InteractsWithReceivingSessionHud`](../../../app/Filament/App/Resources/ReceivingSessions/Concerns/InteractsWithReceivingSessionHud.php)
- [`InteractsWithOutboundShippingSessionHud`](../../../app/Filament/App/Resources/OutboundShippingSessions/Concerns/InteractsWithOutboundShippingSessionHud.php)
- [`InteractsWithTransferringSessionHud`](../../../app/Filament/App/Resources/TransferringSessions/Concerns/InteractsWithTransferringSessionHud.php)

Shared helper (new): `App\Support\Fda\ScheduledProductPresence` — given session EPCs/GTINs, return highest schedule label + whether any scheduled line exists. One query path: distinct GTINs → tenant products → `fda_product_id` → central `dea_schedule`. Remember `fdaProduct()` is cross-connection: **no `whereHas` / join** across connections; load FDA rows by id list.

## Exceptions

| Code | Severity | Receive impact | When |
|------|----------|----------------|------|
| `SCHEDULED_PRODUCT_MISSING_DEA` | warning | Warning | Session/document has scheduled product and the relevant party DEA is blank |

Category: master-data / location (same family as `UNKNOWN_GLN`).

Emit:

- Inbound process/reprocess: after party enrichment, if document EPCs include scheduled GTINs and seller (or ship-to when we are the seller on outbound ingest — skip outbound ingest) lacks DEA. Mirror [`RecordAtpSoftWarning`](../../../app/Actions/Epcis/RecordAtpSoftWarning.php) / [`RecordDestinationGlnMismatch`](../../../app/Actions/Epcis/RecordDestinationGlnMismatch.php): operational hook, soft-signal clear + re-derive.
- Open / complete receive: re-check (same as destination GLN).
- Outbound send: **do not** add a `ValidateOutboundShippingSend` blocker in Phase A. Surface HUD chip + optional notification. Phase B may add a hard gate.

Correction profile (`ExceptionCorrectionProfile`):

- Family: `FAMILY_MASTER_DATA_LOCATION`
- Blurb: “This shipment includes DEA-scheduled product. Add the seller or destination DEA registration on the trading partner or site, then reprocess.”
- Correction: edit partner/site `dea_number` (existing forms). No new Filament action required if Edit Site / Edit Partner already exist from `UNKNOWN_GLN` patterns.

Seeder: [`ExceptionTypeSeeder`](../../../database/seeders/ExceptionTypeSeeder.php) + [`ExceptionReceiveImpactMap`](../../../app/Support/Exceptions/ExceptionReceiveImpactMap.php).

## Resolver change

Do **not** treat a 13-digit numeric DEA-looking string as a GLN. Only enter the DEA ladder when:

- the token has a `DEA` prefix, or
- after digit-stripping the value is **not** length 13, or
- length 13 fails GLN check digit **and** a `dea_number` match exists.

Index: add tenant indexes on `sites.dea_number` and `trading_partners.dea_number` (nullable, non-unique — same number can theoretically appear on a partner header and a site).

## Settings

None in Phase A. No tenant toggle. Always-on warning.

## Tests

- `FdaRegistryStatus::deaScheduleLabel` (II–V, junk → null)
- Scheduled presence helper: linked CII product vs unlinked GTIN
- `ResolveGlnToMasterData` / location resolver: `DEA:AB1234567` matches site, returns that site’s GLN
- Ingest fixture with `DEA:` read point / source → document `ship_from` GLN filled; no DEA stored on event location
- Ingest + receive: CII product + blank seller DEA → `SCHEDULED_PRODUCT_MISSING_DEA` warning; receive still opens
- CII + seller DEA present → no signal
- Outbound HUD / send: CII + blank ship-to DEA → chip/warning, send still allowed

## Success criteria

1. Floor user can see CII–CV on a session that has scheduled, FDA-linked product.
2. Partner EPCIS that identifies a location as `DEA:…` attaches to the existing GLN master-data row.
3. Missing party DEA on scheduled product is a visible warning exception; receive and send are not blocked.
4. No CSOS, ARCOS, or 222 objects exist in the schema.

## Follow-on (Phase B, not this spec)

- Hard outbound gate (ATP-style) when scheduled product and destination DEA blank
- Dual-control / RegulatoryCompliance reason on CII ship/transfer
- Optional receive-block tenant setting (same pattern as destination GLN Phase 2)
