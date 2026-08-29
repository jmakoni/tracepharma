# Products and principals

Filament classes:

- `App\Filament\App\Resources\Products\ProductResource`
- `App\Filament\App\Resources\FdaProducts\FdaProductResource`
- `App\Filament\App\Resources\Principals\PrincipalResource`

## When to use

Maintain tenant product catalog, link FDA product reference data, and manage principals (brand owners / responsible parties) used in labeling and EPCIS.

## Prerequisites

- Master-data create/edit permissions.
- GTIN / NDC identifiers and packaging hierarchy known.

## Steps

1. Open **Products**; create or edit tenant products. Open the page and use Help for live UI.
2. Use **FDA products** to browse registry-backed reference rows and associate where supported.
3. Maintain **Principals** for ownership / labeling attribution.
4. Verify GTINs appear correctly on commission and outbound flows.

## Related pages

- [trading-partners.md](trading-partners.md) — partner master
- [sites-and-devices.md](sites-and-devices.md) — sites that stock products
- [../settings/labeling.md](../settings/labeling.md) — SSCC / label ranges
- [../compliance/expiry-worklist.md](../compliance/expiry-worklist.md) — expiry by product/lot

## Notes

- Prefer linking FDA reference data over free-typing NDC/GTIN when possible.
- Product changes can affect open commission sessions — coordinate with operations.
