# On-hand inventory module — design

Date: 2026-09-18  
Status: accepted (plan option 3)  
Related: On-hand inventory revamp plan (c9bcb022)

## Problem

[`OnHandList`](../../../app/Filament/App/Pages/OnHandList.php) is a flat 200-row EPC table (site + optional principal). Peers (TraceLink SOM, SAP ATTP, rfxcel, Systech, RxRescue) lead with **lot rollups + scan inquiry + urgency worklists + exports**, not infinite serial grids. Our KB already promises product/lot/EPC filter and custody drill that the UI does not deliver.

## Decision

Ship a **full custody inventory module** on `/on-hand`:

- Default landing: **product × lot × site rollup** with sgtin/sscc counts
- Omnipresent **scan/paste → Asset Tracking**
- Tabs: Lots | Serials | Near-expiry | Holds | Investigate
- CSV + **audit pack** export
- Position as *last-seen custody, not a second inventory system* — no WMS bins, qty edits, or invented stock

## Custody truth

Single source: [`ShippableEpcsAtSite`](../../../app/Support/Shipping/ShippableEpcsAtSite.php). New [`OnHandLotRollup`](../../../app/Support/Shipping/OnHandLotRollup.php) aggregates that query; no parallel inventory table.

## Rollup shape

Group key:

- SGTIN: `gtin14` + `lot_number` (from `epc_ilmd`; empty lot → `''`)
- SSCC-only (no gtin): synthetic product key `__sscc__` + lot (usually empty) → “Containers” bucket

Aggregates per group: `total`, `sgtin_count`, `sscc_count`, `min_expiry`, `max_expiry`.  
Chips: open quarantine hold on any member; near-expiry if any member expiry within selected window (default 90). Recall chip deferred (deep-link Find Recall only).

## Tabs

| Tab | Behavior |
|-----|----------|
| Lots (default) | Paginated rollup table; expand → Serials filtered to that GTIN/lot; export lot CSV |
| Serials | Filament table over shippable query; search; filters; row → Asset Tracking |
| Near-expiry | ExpiryWorklist semantics (30/60/90) at this site; quarantine via `QuarantineService` |
| Holds | Open quarantine holds intersecting on-hand at site; link Quarantine workstation |
| Investigate | Multi-line paste; classify on-hand / other-site / not-found / quarantined |

## Header

Site + principal (existing gates). Scan field submits to `AssetTrackingUrl` / `AssetTracking::getUrl(['scan' => …])`.

## Nav / Hub

- Label: **On-hand** (slug stays `on-hand`)
- Operations Hub directory card when `OnHandList::canAccess()`
- Keep `showsWholesaleOperationsNav()` gate
- UnpackedItems stays sibling

## Exports

1. CSV lot rollup (current site/principal filters)
2. CSV serials (current serial filters)
3. Audit pack: ZIP with `meta.json`, `lots.csv`, `serials.csv`, `holds.csv`

## Non-goals

- WMS bins / putaway / cycle count UI
- Qty editing without EPCIS events
- Merging UnpackedItems or ExpiryWorklist pages away
- Pharmacy simplified-nav exposure change

## Tests

1. Unit: `OnHandLotRollupTest` — grouping, principal, empty site, pack counts
2. Feature: `OnHandListTest` — access, tabs, scan redirect, quarantine, investigate, exports
3. Wave C smoke updated for new label
