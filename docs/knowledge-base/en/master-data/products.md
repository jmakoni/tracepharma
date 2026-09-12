---
title: Products
parent: master-data
order: 25
group: Master Data
---

# Products

Filament classes:

- `App\Filament\App\Resources\Products\ProductResource`
- `App\Filament\App\Resources\FdaProducts\FdaProductResource`

## When to use

Maintain tenant product catalog and link FDA product reference data.

## Prerequisites

- Master-data create/edit permissions.
- GTIN / NDC identifiers and packaging hierarchy known.

## Steps

1. Open **Product directory** from Operations Hub Directories (`/products`), or **Products** in nav; create or edit tenant products. Open the page and use Help for live UI.
2. Open **FDA Products** from Hub Directories (`/fda-products`) to browse registry-backed reference rows and associate where supported (partner-first authorize path).
3. See [../operations/principals.md](../operations/principals) for Logistics 3PL principals.
4. Verify GTINs appear correctly on commission and outbound flows (commission is manufacturer/prepackager only).

## Related pages

- [trading-partners.md](../master-data/trading-partners) — partner master
- [sites-and-devices.md](../master-data/sites-and-devices) — sites that stock products
- [../operations/principals.md](../operations/principals) — Logistics 3PL principals
- [../settings/labeling.md](../settings/labeling) — SSCC / label ranges
- [../compliance/expiry-worklist.md](../compliance/expiry-worklist) — expiry by product/lot

## Notes

- Prefer Hub Directories for floor operators; sidebar may stay partner-scoped.
- Prefer linking FDA reference data over free-typing NDC/GTIN when possible.
- Product changes can affect open commission sessions — coordinate with operations.
