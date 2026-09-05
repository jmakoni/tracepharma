# FDA establishments

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource`

## When to use

Inspect FDA establishment records linked to organizations for registration / facility identity.

## Prerequisites

- Admin registry access.
- Organization context often helps disambiguation.

## Steps

1. Open **FDA establishments**. Open the page and use Help for live UI.
2. Search and open an establishment.
3. Confirm organization linkage and key identifiers.
4. Escalate mismatches to match review or re-import.

## Related pages

- [fda-organizations.md](fda-organizations.md) — parent organizations
- [fda-wdd.md](fda-wdd.md) — wholesaler facility licenses
- [../operations/fda-imports.md](../operations/fda-imports.md) — import runs
- [match-review.md](match-review.md) — unresolved matches

## Notes

- Establishments and WDD facilities are related but not identical concepts — do not conflate IDs.
- Prefer import corrections over one-off UI edits when bulk data is wrong.
