# Customer onboarding

Filament classes:

- `App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource`

## When to use

Track platform customer onboarding cases from signup through tenant go-live.

## Prerequisites

- Admin access to onboarding queue.
- Linked demo request or sales handoff when applicable.

## Steps

1. Open **Customer onboardings**. Open the page and use Help for live UI.
2. Open a case; advance checklist stages and capture blockers.
3. Provision or link the tenant when ready ([tenants.md](tenants.md)).
4. Close when production handoff criteria are met.

## Related pages

- [tenants.md](tenants.md) — tenant provisioning
- [demo-requests.md](demo-requests.md) — demo intake
- [../platform/analytics.md](../platform/analytics.md) — onboarding funnel metrics
- [../platform/mail-templates.md](../platform/mail-templates.md) — customer emails

## Notes

- Onboarding records are operational CRM-lite for the platform team, not tenant Filament users.
- Keep blockers explicit so SLA widgets stay accurate.
