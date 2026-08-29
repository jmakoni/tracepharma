---
title: Fda Products
parent: registry
order: 30
group: Registry
---

# Fda Products

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaProducts\FdaProductResource`

## When to use

Browse platform FDA product registry rows (packages, ingredients, routes) used to enrich tenant catalogs.

## Prerequisites

- Admin registry access.
- Imports completed for the relevant NDC/GTIN set.

## Steps

1. Open **FDA products**. Open the page and use Help for live UI.
2. Search by NDC, name, or labeler.
3. Open a product; review packages and related managers.
4. Use match / import tools when tenant linkage is wrong.

## Related pages

- [fda-organizations.md](../registry/fda-organizations) — labeler organizations
- [../operations/fda-imports.md](../operations/fda-imports) — product import runs
- [match-review.md](../registry/match-review) — organization match issues that block product linkage
- [../../app/master-data/products.md](../../app/master-data/products.md) — tenant product UI

## Notes

- This is the platform registry, not the tenant `FdaProductResource` in the App panel.
- Tenant product association happens in the App panel after registry data is healthy.
