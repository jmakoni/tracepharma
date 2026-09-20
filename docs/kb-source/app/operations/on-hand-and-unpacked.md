---
title: On-hand and unpacked
parent: operations
order: 35
group: Operations
---

# On-hand and unpacked

Filament classes:

- `App\Filament\App\Pages\OnHandList`
- `App\Filament\App\Pages\UnpackedItems`

## When to use

View **last-seen custody** at a site (not a WMS stock balance). Use lot rollups, serial lists, near-expiry, quarantine holds, and multi-paste investigate. Manage items that were unpacked (children available after aggregation break) on Unpacked items.

## Prerequisites

- Site selected.
- Inventory events (receive, commission, unpack) already processed.

## Steps

1. Open **On-hand** (Operations Hub or sidebar).
2. Choose **Site** (and **Principal** when enabled).
3. Use **Scan / lookup** to open Asset Tracking for one identifier.
4. Work tabs:
   - **Lots** — product × lot counts (SGTIN / SSCC); open Serials for a lot; export lots CSV / audit pack from header actions.
   - **Serials** — searchable paginated custody list; row Trace → Asset Tracking.
   - **Near-expiry** — FEFO window 30/60/90; Quarantine when permitted.
   - **Holds** — open quarantine holds on on-hand stock.
   - **Investigate** — paste many identifiers; classify on-hand / other site / not found.
5. Open **Unpacked items** for children after unpack / break-pack.
6. Continue with ship, transfer, pack, or decommission as needed.

## Related pages

- [../compliance/expiry-worklist.md](../compliance/expiry-worklist) — near-expiry on-hand (Compliance nav)
- [../compliance/quarantine.md](../compliance/quarantine) — hold inventory
- [epcis-jobs.md](../operations/epcis-jobs) — delayed inventory updates
- [../master-data/sites-and-devices.md](../master-data/sites-and-devices) — site context
- [../workflows/asset-tracking.md](../workflows/asset-tracking) — unit dossier / timeline

## Notes

- Lists can lag briefly after large ingest jobs — refresh after jobs complete.
- Unpacked items are not automatically saleable at parent SSCC level; follow pack/ship SOPs.
- On-hand does not invent quantities; state changes only via EPCIS workflows.
