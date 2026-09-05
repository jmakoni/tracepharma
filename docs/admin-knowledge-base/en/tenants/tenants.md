---
title: Tenants
parent: tenants
order: 30
group: Tenants
---

# Tenants

Filament classes:

- `App\Filament\Admin\Resources\Tenants\TenantResource`

## When to use

Create and administer TracePharma tenants (databases, profiles, feature flags, impersonation, compliance archive export).

## Prerequisites

- Platform admin access to the Admin panel.
- Tenant type / profile decided before create.

## Steps

1. Open **Tenants**. Open the page and use Help for live UI.
2. Create a tenant with type, domains, and seed options as required.
3. Edit settings, run impersonation for support (policy-controlled), or export compliance archives.
4. Deactivate carefully — confirm no live production traffic first.

## Related pages

- [customer-onboarding.md](../tenants/customer-onboarding) — onboarding queue
- [demo-requests.md](../tenants/demo-requests) — inbound demos that may become tenants
- [../operations/epcis-hub-settings.md](../operations/epcis-hub-settings) — hub settings affecting tenants
- [../platform/analytics.md](../platform/analytics) — tenant growth metrics

## Notes

- Prefer source-first deploy for app code; tenant live data (tokens, seeders) stays on the environment.
- Impersonation is audited — use only for authorized support.
