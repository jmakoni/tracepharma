---
title: EPCIS hub settings
parent: operations
order: 20
group: Operations
---

# EPCIS hub settings

Filament classes:

- `App\Filament\Admin\Pages\EpcisHubSettings`

## When to use

Configure platform EPCIS hub options and review hub health for multi-tenant routing / connectivity.

## Prerequisites

- Platform admin rights.
- Understanding of environment-specific hub endpoints (stage vs prod).

## Steps

1. Open **EPCIS hub settings**. Open the page and use Help for live UI.
2. Review hub health widgets and configuration fields.
3. Update settings carefully; save and validate connectivity.
4. Coordinate with tenant integration owners after changes.

## Related pages

- [../tenants/tenants.md](../tenants/tenants) — tenants using the hub
- [../platform/analytics.md](../platform/analytics) — hub coverage metrics
- [../../app/integrations/integration-health.md](../../app/integrations/integration-health.md) — tenant-side health
- [fda-imports.md](../operations/fda-imports) — unrelated to hub but same ops area

## Notes

- Hub misconfiguration impacts many tenants at once — change windows matter.
- Do not copy stage secrets into prod (or vice versa).
