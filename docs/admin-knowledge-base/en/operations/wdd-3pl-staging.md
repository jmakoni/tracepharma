---
title: Wdd 3pl Staging
parent: operations
order: 30
group: Operations
---

# Wdd 3pl Staging

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\FdaWdd3plStagingResource`
- `App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\FdaWdd3plUnmatchedResource`

## When to use

Work staged WDD/3PL import rows and resolve unmatched facilities before promoting to the live registry.

## Prerequisites

- Staging populated by WDD import pipelines.
- Admin registry / operations access.

## Steps

1. Open **WDD 3PL staging**; review pending rows. Open the page and use Help for live UI.
2. Open **Unmatched** list; decide link, create, or discard.
3. Promote or correct until unmatched backlog is acceptable.
4. Verify facilities/licenses in [../registry/fda-wdd.md](../registry/fda-wdd).

## Related pages

- [fda-imports.md](../operations/fda-imports) — import run status
- [../registry/fda-wdd.md](../registry/fda-wdd) — live facilities/licenses
- [../registry/match-review.md](../registry/match-review) — org match issues
- [../platform/analytics.md](../platform/analytics) — unmatched aging

## Notes

- Leaving unmatched rows open causes ATP gaps for tenants relying on registry licenses.
- Prefer consistent matching rules over ad-hoc creates.
