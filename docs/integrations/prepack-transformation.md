# Prepack transformation (repack)

Prepackager tenants can author a **TransformationEvent** that links input EPCs to output SGTINs (**Operations → Repack (transform)**).

## Pack vs transform

| Tool | EPCIS event | What it means |
|------|-------------|----------------|
| **Pack** / **Break & pack** | AggregationEvent | Containment hierarchy (SSCC ↔ children). Unchanged. |
| **Repack (transform)** | TransformationEvent | Lineage: inputs consumed into new output identities (`inputEPC` / `outputEPC`, `transformation_id`). |

Asset Trace includes a `transformation_links` payload for EPCs that participate in a TransformationEvent (counterpart URN / role / transformation id). Aggregation ancestor behavior is unchanged.

## Behavior notes

- Inputs must be **on hand** at the selected site (`ShippableEpcsAtSite`).
- Outputs may be new SGTIN URNs (created on ingest) or existing commissioned units.
- Inputs are **not** auto-decommissioned; disposition follows the TransformationEvent (`commissioning` / `active` for this MVP). Run Decommission separately if inputs must leave inventory.
- Authored documents use `authored_kind=transformation` (outbound internal; no partner transmission unless a trading partner is attached).

## Deferred

- Original-link TI / multi-hop “repack product” GTM packaging
- Heavy scan UX (this page is a simple form, not PackWorkstation redesign)
