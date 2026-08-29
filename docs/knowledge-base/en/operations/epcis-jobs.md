---
title: Epcis Jobs
parent: operations
order: 30
group: Operations
---

# Epcis Jobs

Filament classes:

- `App\Filament\App\Resources\EpcisJobs\EpcisJobResource`

## When to use

Monitor queue jobs that parse, validate, archive, or requeue EPCIS documents and related work.

## Prerequisites

- Jobs created by ingest, outbound, archive, or requeue actions.
- Operations or integrations access.

## Steps

1. Open **EPCIS jobs**. Open the page and use Help for live UI.
2. Filter by status (failed, running, completed) and time.
3. Open a failed job; read the error and linked document.
4. Requeue or fix upstream config; confirm success.

## Related pages

- [../exceptions/inbound-epcis.md](../exceptions/inbound-epcis) — source documents
- [outbound-epcis.md](../operations/outbound-epcis) — outbound documents
- [../integrations/integration-health.md](../integrations/integration-health) — connection health
- [activity-log.md](../operations/activity-log) — user/system activity trail

## Notes

- Blind requeue without fixing the root cause burns retries and partner goodwill.
- Archive/requeue privileges may be restricted — use only when authorized.
