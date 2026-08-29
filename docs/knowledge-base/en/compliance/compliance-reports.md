---
title: Compliance Reports
parent: compliance
order: 30
group: Compliance
---

# Compliance Reports

Filament classes:

- `App\Filament\App\Pages\ComplianceReports`
- `App\Filament\App\Pages\Analytics`
- `App\Filament\App\Pages\HqRollup`

## When to use

Run compliance report hubs, operational analytics, or multi-site HQ rollups for DSCSA evidence and management reviews.

## Prerequisites

- Report permissions for the tenant.
- For HQ rollup: multi-site / chain profile with rollup enabled.

## Steps

1. **Compliance reports** — open the hub; pick a report, set filters, run or download. Open the page and use Help for live UI.
2. **Analytics** — explore charts and trends for volume, exceptions, and integration health.
3. **HQ rollup** — compare sites on readiness and backlog metrics.
4. Share outputs with leadership or attach to inspection packs as needed.

## Related pages

- [leadership-dscsa-pack.md](../compliance/leadership-dscsa-pack) — executive summary
- [recall-and-inspection.md](../compliance/recall-and-inspection) — inspection pack export
- [../integrations/integration-health.md](../integrations/integration-health) — feed health detail

## Notes

- Large date ranges may queue asynchronously — wait for completion before re-running.
- HQ rollup reflects linked sites only; orphaned sites will not appear.
