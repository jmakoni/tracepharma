---
title: Fda Imports
parent: operations
order: 25
group: Operations
---

# Fda Imports

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaImportRuns\FdaImportRunResource`

## When to use

Monitor FDA data import jobs (organizations, establishments, products, WDD) including status, errors, and coverage.

## Prerequisites

- Admin access to import runs.
- Source files / pipelines configured for the environment.

## Steps

1. Open **FDA import runs**. Open the page and use Help for live UI.
2. Filter by type and status; open a run for logs and counts.
3. Investigate failed runs; fix source or mapping; re-queue if supported.
4. Confirm registry resources and match review after successful imports.

## Related pages

- [../registry/fda-organizations.md](../registry/fda-organizations) — org registry
- [../registry/fda-products.md](../registry/fda-products) — product registry
- [../registry/match-review.md](../registry/match-review) — post-import matches
- [wdd-3pl-staging.md](../operations/wdd-3pl-staging) — WDD staging aftermath

## Notes

- Partial success can leave mixed generations — check counts before declaring green.
- Import health widgets on the admin dashboard summarize recent runs.
