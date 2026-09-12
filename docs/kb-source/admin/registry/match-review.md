# FDA organization match review

Filament classes:

- `App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource`

## When to use

Resolve ambiguous or proposed matches between incoming FDA data and existing organization records.

## Prerequisites

- Match review queue populated by imports or matching jobs.
- Admin permission to accept/reject matches.

## Steps

1. Open **Match reviews**. Open the page and use Help for live UI.
2. Open a candidate; compare identifiers and names.
3. Accept, reject, or defer with notes.
4. Re-run dependent imports/links after bulk decisions.

## Related pages

- [fda-organizations.md](fda-organizations.md) — organization master
- [../operations/fda-imports.md](../operations/fda-imports.md) — imports that create candidates
- [../operations/wdd-3pl-staging.md](../operations/wdd-3pl-staging.md) — unmatched WDD rows
- [../platform/analytics.md](../platform/analytics.md) — match aging metrics

## Notes

- Wrong accepts poison downstream ATP and product linkage — when unsure, defer.
- Aging match queues inflate platform analytics risk indicators.
