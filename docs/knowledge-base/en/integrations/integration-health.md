---
title: Integration Health
parent: integrations
order: 35
group: Integrations
---

# Integration Health

Filament classes:

- `App\Filament\App\Pages\IntegrationHealth`

## When to use

See at-a-glance health of inbound/outbound connections, subscriptions, recent failures, and backlog.

## Prerequisites

- At least one connection, subscription, or API token configured.
- Integrations viewer permissions.

## Steps

1. Open **Integration health**. Open the page and use Help for live UI.
2. Scan status cards for degraded connections or rising error rates.
3. Drill into a failing connection, subscription, or document.
4. After remediation, confirm green status on the next refresh.

## Related pages

- [connections.md](../integrations/connections) — configure inbound/outbound endpoints
- [epcis-subscriptions.md](../integrations/epcis-subscriptions) — subscription management
- [../compliance/partner-ingest-quality.md](../compliance/partner-ingest-quality) — partner quality metrics
- [../compliance/l3-forward-log.md](../compliance/l3-forward-log) — L3 forward attempts

## Notes

- Health is a dashboard, not a queue worker — stuck jobs may also need EPCIS Jobs review.
- Transient partner outages can look like local failures; check partner status before rotating secrets.
