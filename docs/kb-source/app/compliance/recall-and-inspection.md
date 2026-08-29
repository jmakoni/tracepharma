# Recall and inspection

Filament classes:

- `App\Filament\App\Pages\RecallClosureDashboard`
- `App\Filament\App\Pages\InspectionDayReadinessPage`
- `App\Filament\App\Pages\InspectionPack`
- `App\Filament\App\Pages\SiteRecallReconciliation`

## When to use

Drive recall closure across sites, prepare for an inspection day, export an inspection pack, or reconcile on-hand vs recalled lots at a site.

## Prerequisites

- Recall or inspection scope defined (lots, NDCs, date range, sites).
- Site-level users for reconciliation; HQ/compliance for pack export.

## Steps

1. **Recall closure** — open the dashboard; track open vs closed sites and remaining EPCs. Open the page and use Help for live UI.
2. **Site recall** — at each site, reconcile on-hand against recall criteria; quarantine or decommission per SOP.
3. **Inspection day readiness** — review checklist scores (exceptions, ATP, ingest, tracing backlog).
4. **Inspection pack** — generate/download the ZIP bundle for auditors.

## Related pages

- [quarantine.md](quarantine.md) — hold inventory during recall
- [compliance-reports.md](compliance-reports.md) — exportable compliance reports
- [leadership-dscsa-pack.md](leadership-dscsa-pack.md) — executive DSCSA snapshot
- [tracing-and-fda3911.md](tracing-and-fda3911.md) — tracing and Form 3911

## Notes

- Site reconciliation and HQ closure dashboards answer different questions; close both for end-to-end recall readiness.
- Inspection pack contents reflect current tenant data at generation time — regenerate after major remediations.
