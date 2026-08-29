---
title: Expiry Worklist
parent: compliance
order: 35
group: Compliance
---

# Expiry Worklist

Filament classes:

- `App\Filament\App\Pages\ExpiryWorklist`

## When to use

Prioritize on-hand lots approaching expiry for quarantine, return, or decommission.

## Prerequisites

- Products and lots with expiry dates in inventory.
- Site selected when working site-scoped lists.

## Steps

1. Open **Expiry worklist**. Open the page and use Help for live UI.
2. Filter by horizon (e.g. 30/60/90 days), site, product, or lot.
3. Drill into EPCs; take action via quarantine, return, or decommission workflows.
4. Mark reviewed items per local SOP if your process tracks completion outside the list.

## Related pages

- [quarantine.md](../compliance/quarantine) — hold near-expiry product
- [../operations/on-hand-and-unpacked.md](../operations/on-hand-and-unpacked) — current on-hand view
- [compliance-alerts.md](../compliance/compliance-alerts) — expiry risk alerts

## Notes

- Worklist is a planning surface; it does not itself change disposition.
- FEFO shipping policies may still allow saleable product until your cut-off — confirm local pharmacy/wholesale rules.
