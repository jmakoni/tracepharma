---
title: FDA establishments
parent: registry
order: 20
group: Registry
---

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

- [fda-organizations.md](../registry/fda-organizations) — parent organizations
- [fda-wdd.md](../registry/fda-wdd) — wholesaler facility licenses
- [../operations/fda-imports.md](../operations/fda-imports) — import runs
- [match-review.md](../registry/match-review) — unresolved matches

## Notes

- Establishments and WDD facilities are related but not identical concepts — do not conflate IDs.
- Prefer import corrections over one-off UI edits when bulk data is wrong.
