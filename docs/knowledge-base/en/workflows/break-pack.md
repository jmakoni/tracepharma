---
title: Break and pack
parent: workflows
order: 20
group: Operations
---

# Break and pack

- **Slug / URL:** `/break-pack-workstation`
- **Filament:** `App\Filament\App\Pages\BreakPackWorkstation`
- **Who:** `supportsPacking()` and `NavShip`
- **Produces:** `unpacking` + `in_progress`, then `packing` + `in_progress` (and commissioning for new parent SSCC)

## When to use

Break selected children from a source pallet under your org prefix and commission a **new** parent SSCC in one workstation — alternative to separate Unpack → Pack when reshipping under your company prefix.

Subheading: *Break children from a source pallet and commission a new parent SSCC under your organization company prefix.*

## Prerequisites

- Source parent on hand at site with reshippable children.
- Tenant SSCC settings (company prefix) configured.
- Same custody/ship gates as Unpack and Pack.

## Steps (with screenshots)

1. Open **Break & pack** from Operations nav.

![Break & pack workstation](media/break-pack/01-entry.png)

![Break & pack workstation (full)](media/break-pack/02-full.png)

2. Scan source parent SSCC; review open children and select subset.
3. Confirm break — `UnpackReceivingHierarchy` DELETE leg.
4. System generates new SSCC via `GenerateSsccLabelBatch` / `BreakPalletAndReship` and packs selected children.
5. New parent label available from SSCC Labels resource.

## Authored EPCIS checklist

- [ ] AggregationEvent **DELETE** (unpacking / `in_progress`) from source parent
- [ ] AggregationEvent **ADD** (packing / `in_progress`) to new parent SSCC
- [ ] Commissioning ObjectEvent for new parent if required by label flow
- [ ] Source document lineage preserved when `sourceDocumentId` present

## Related pages

- [unpack.md](../workflows/unpack) + [pack.md](../workflows/pack) — two-step equivalent
- [outbound-shipping.md](../workflows/outbound-shipping) — ship new pallet
- [commission.md](../workflows/commission) — standalone commissioning if parent not auto-commissioned

## Notes / known quirks

- Displays tenant name and company prefix in UI for operator confirmation.
- Operations Hub directory links to this page for shippable SSCC scans when appropriate.
