---
title: On Hand And Unpacked
parent: operations
order: 35
group: Operations
---

# On Hand And Unpacked

Filament classes:

- `App\Filament\App\Pages\OnHandList`
- `App\Filament\App\Pages\UnpackedItems`

## When to use

View current on-hand EPCs at a site and manage items that were unpacked (children available after aggregation break).

## Prerequisites

- Site selected.
- Inventory events (receive, commission, unpack) already processed.

## Steps

1. Open **On-hand list**; filter by product, lot, or EPC. Open the page and use Help for live UI.
2. Drill into an EPC for custody / event history as offered.
3. Open **Unpacked items** to work children after unpack / break-pack.
4. Continue with ship, transfer, pack, or decommission as needed.

## Related pages

- [../compliance/expiry-worklist.md](../compliance/expiry-worklist) — near-expiry on-hand
- [../compliance/quarantine.md](../compliance/quarantine) — hold inventory
- [epcis-jobs.md](../operations/epcis-jobs) — delayed inventory updates
- [../master-data/sites-and-devices.md](../master-data/sites-and-devices) — site context

## Notes

- Lists can lag briefly after large ingest jobs — refresh after jobs complete.
- Unpacked items are not automatically saleable at parent SSCC level; follow pack/ship SOPs.
