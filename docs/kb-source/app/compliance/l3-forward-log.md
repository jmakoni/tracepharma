# L3 forward log

Filament classes:

- `App\Filament\App\Pages\L3ForwardLog`

## When to use

Audit Level-3 (enterprise serialization / partner hub) forward attempts: successes, retries, and failures for outbound EPCIS or labeling handoffs.

## Prerequisites

- L3 / outbound forward integrations configured for the tenant.
- Integrations or labeling operations access.

## Steps

1. Open **L3 forward log**. Open the page and use Help for live UI.
2. Filter by status, partner, time range, or correlation id.
3. Open a failed row; review error payload and related outbound document/job.
4. Retry or remediate connection config, then confirm a successful forward.

## Related pages

- [../integrations/integration-health.md](../integrations/integration-health.md) — overall integration health
- [../operations/outbound-epcis.md](../operations/outbound-epcis.md) — outbound EPCIS documents
- [../operations/epcis-jobs.md](../operations/epcis-jobs.md) — job queue status
- [../settings/labeling.md](../settings/labeling.md) — SSCC / printer setup when forwards are label-related

## Notes

- The log is observational; fixing credentials or endpoint config happens under Connections.
- Duplicate retries can create partner noise — coordinate with the receiver before bulk replay.
