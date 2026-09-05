# FDA organizations

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource`

## When to use

Browse and maintain FDA organization registry rows (labels, relationships to establishments, products, WDD facilities).

## Prerequisites

- Admin registry access.
- Prefer import-backed updates over manual edits when possible.

## Steps

1. Open **FDA organizations**. Open the page and use Help for live UI.
2. Search by name or identifiers; open a record.
3. Review related establishments, products, and facilities via relation managers.
4. Route uncertain matches to match review rather than forcing links.

## Related pages

- [fda-establishments.md](fda-establishments.md) — establishments
- [fda-products.md](fda-products.md) — products
- [fda-wdd.md](fda-wdd.md) — WDD facilities and licenses
- [match-review.md](match-review.md) — organization match queue

## Notes

- Manual edits can be overwritten by later FDA imports — document intentional overrides.
- View-only modes may apply depending on admin role.
