# Repack (transform)

- **Slug / URL:** `/repack-transform`
- **Filament:** `App\Filament\App\Pages\RepackTransformWorkstation`
- **Who:** **Prepackager profile only** — `supportsRepackTransform()` and `NavShip`. **403 on Drug Wholesaler demo2** (expected profile gating).
- **Produces:** TransformationEvent with `commissioning` biz step; output disposition `active`

## When to use

Prepackager MVP: author a **TransformationEvent** linking on-hand input SGTINs/SSCCs to new output SGTINs. Pack and Break & pack remain aggregation-only tools for all profiles.

Subheading: *Author a TransformationEvent (input → output SGTINs). Pack/BreakPack stay aggregation; original-link TI is deferred.*

## Prerequisites

- **Prepackager** tenant profile (not Drug Wholesaler).
- Site selected in form; inputs on hand at that site.
- Output defined via GTIN-14 + serial lines **or** full output SGTIN URNs.

## Steps (with screenshots)

1. On a **Prepackager** tenant, open **Repack (transform)** from Operations nav.

![Repack transform form](media/repack-transform/01-entry.png)

2. Select site; paste **Input EPC URNs** (one per line).
3. Define outputs: **Output GTIN-14** + **Output serials**, or **Output SGTIN URNs**.
4. Click **Author transformation** → `AuthorTransformationRepack`.

## Authored EPCIS checklist

- [ ] **TransformationEvent** (not AggregationEvent)
- [ ] `bizStep`: `urn:epcglobal:cbv:bizstep:commissioning` *(product quirk — not `repackaging`)*
- [ ] Input EPCs in `inputEPCList`; outputs in `outputEPCList`
- [ ] Output disposition: `active`
- [ ] Document notes transformation ID and input/output counts

## Related pages

- [pack.md](pack.md) — SSCC aggregation (all packing profiles)
- [break-pack.md](break-pack.md) — aggregation break/repack
- [commission.md](commission.md) — standalone ObjectEvent commissioning
- [cbv-biz-steps.md](cbv-biz-steps.md) — repack transform quirk documented

## Notes / known quirks

- Direct URL on **demo2 Drug Wholesaler** returns **403** — Prepackager-only feature.
- TransformationEvent uses **`commissioning`** biz step, not `repackaging`; do not “fix” in ops docs without product decision.
- Original-link TI preservation is explicitly deferred in UI copy.
