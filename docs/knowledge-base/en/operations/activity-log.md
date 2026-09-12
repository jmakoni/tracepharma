---
title: Activity Log
parent: operations
order: 20
group: Operations
---

# Activity Log

Filament classes:

- `App\Filament\App\Resources\ActivityLogs\ActivityLogResource`

## When to use

Audit who changed what in the tenant app — user actions, configuration edits, and notable system events.

## Prerequisites

- Permission to view activity logs (often admin / compliance).

## Steps

1. Open **Activity log**. Open the page and use Help for live UI.
2. Filter by user, subject type, or date range.
3. Open an entry for before/after or description detail.
4. Export or cite entries when preparing inspection evidence.

## Related pages

- [../settings/users.md](../settings/users) — who can act
- [../exceptions/exceptions.md](../exceptions/exceptions) — investigation activity also lives on exceptions
- [epcis-jobs.md](../operations/epcis-jobs) — job-level processing history
- [../compliance/recall-and-inspection.md](../compliance/recall-and-inspection) — inspection packs

## Notes

- Tenant activity log is separate from the Admin panel platform activity log.
- High-volume tenants should use narrow filters to keep queries responsive.
