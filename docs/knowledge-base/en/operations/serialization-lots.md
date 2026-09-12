---
title: Serialization Lots
parent: operations
order: 21
group: Operations
---

# Serialization Lots

Filament classes:

- `App\Filament\App\Resources\SerializationLots\SerializationLotResource`

## When to use

Review lots ingested from a Guardian/UniSeries L1–L3 line at lot close: material
(NDC), lot/expiry, processed times, and pallet/case/unit counts, plus the full
`LotControlData` bag and a container-type hierarchy summary.

## Prerequisites

- Manufacturer tenant with commissioning enabled.
- Guardian lot-close inbound accepted at least one feed (see
  `docs/integrations/guardian-lot-close.md`).

## Steps

1. Open **Serialization Lots**.
2. Search by lot number, NDC, product name, or unit GTIN.
3. Open a lot to see the overview, **Lot Control Data**, and **Hierarchy** tabs.
4. Follow the EPCIS document link to see the projected commissioning/aggregation events.

## Related pages

- [epcis-jobs.md](../operations/epcis-jobs) — ingest/queue status
- [../exceptions/inbound-epcis.md](../exceptions/inbound-epcis) — the projected EPCIS document

## Notes

- Read-only — lot rows are written only by the Guardian lot-close ingest job.
- The Hierarchy tab shows container-type counts only; it never loads per-container field JSON.
