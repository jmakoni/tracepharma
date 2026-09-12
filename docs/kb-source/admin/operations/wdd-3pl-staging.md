# WDD / 3PL staging

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\FdaWdd3plStagingResource`
- `App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\FdaWdd3plUnmatchedResource`

## When to use

Work staged WDD/3PL import rows and resolve unmatched facilities so the next import can stage them into the FDA registry.

## Prerequisites

- Staging populated by WDD import pipelines.
- Admin registry / operations access.

## Steps

1. Open **WDD 3PL staging**; review pending rows. Open the page and use Help for live UI.
2. Open **Unmatched** list; decide link, create, or discard.
3. Resolve unmatched backlog (link or create organizations); re-import if needed.
4. Verify facilities/licenses in [../registry/fda-wdd.md](../registry/fda-wdd.md).

## Related pages

- [fda-imports.md](fda-imports.md) — import run status
- [../registry/fda-wdd.md](../registry/fda-wdd.md) — live facilities/licenses
- [../registry/match-review.md](../registry/match-review.md) — org match issues
- [../platform/analytics.md](../platform/analytics.md) — unmatched aging

## Notes

- Leaving unmatched rows open causes ATP gaps for tenants relying on registry licenses.
- Prefer consistent matching rules over ad-hoc creates.
