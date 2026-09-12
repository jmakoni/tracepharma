---
title: Pack
parent: workflows
order: 20
group: Operations
---

# Pack

- **Slug / URL:** `/pack-workstation`
- **Filament:** `App\Filament\App\Pages\PackWorkstation`
- **Who:** `supportsPacking()` and `NavShip`; uses `HidesForPharmacySimplifiedNav`
- **Produces:** `packing` + `in_progress` (AggregationEvent ADD); may also emit commissioning for new parent SSCC

## When to use

After Unpack, scan loose bottles or child units onto a new or pre-issued parent SSCC. Confirm pack authors aggregation ADD and commissions the parent label when applicable.

## Prerequisites

- Commission site selected in site chooser (locked on first scan).
- Children on hand at site; unpack first if still aggregated under another parent.
- SSCC company prefix configured in organization settings for new labels.

## Steps (with screenshots)

1. Open **Pack** from Operations nav.

![Pack workstation](media/pack/01-entry.png)

![Pack workstation (full)](media/pack/02-full.png)

2. Scan an issued parent SSCC **or** start fresh — system previews next SSCC from tenant prefix.
3. Scan child SGTINs/SSCCs into the children list.
4. **Confirm pack** — generates SSCC label batch / aggregation ADD as needed.

## Authored EPCIS checklist

- [ ] AggregationEvent **ADD** parent SSCC → child EPCs
- [ ] `bizStep`: `urn:epcglobal:cbv:bizstep:packing`
- [ ] `disposition`: `urn:epcglobal:cbv:disp:in_progress`
- [ ] Parent SSCC commissioned if newly issued
- [ ] Label batch record linked when SSCC newly generated

## Related pages

- [unpack.md](../workflows/unpack) — prerequisite break
- [break-pack.md](../workflows/break-pack) — alternate break-and-repack path
- [outbound-shipping.md](../workflows/outbound-shipping) — ship packed SSCC

## Notes / known quirks

- Scanning an already-generated parent SSCC continues an in-progress pack session.
- Pack child locks prevent concurrent pack/unpack on same EPCs.
- Success toasts do not currently show biz_step label (see [findings.md](../findings)).
