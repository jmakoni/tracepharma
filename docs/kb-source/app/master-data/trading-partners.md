# Trading partners

Filament classes:

- `App\Filament\App\Resources\TradingPartners\TradingPartnerResource`

## When to use

Create and maintain trading partners (suppliers, customers, 3PLs) including GLN, contacts, and ATP-related attributes.

## Prerequisites

- Partner legal name and identifiers (GLN, DEA, licenses as applicable).
- Master-data edit rights.

## Steps

1. Open **Trading Partners** from Operations Hub Directories (`/trading-partners`), or nav. Partner-first: sites and products hang off the partner. Open the page and use Help for live UI.
2. Create or edit the partner; capture locations and contacts.
3. Attach licenses / ATP data used by readiness checks.
4. Link to inbound/outbound connections when integrating.

## Related pages

- [../compliance/atp-readiness.md](../compliance/atp-readiness.md) — ATP gap review
- [../integrations/partner-onboarding.md](../integrations/partner-onboarding.md) — invite and kit
- [../integrations/connections.md](../integrations/connections.md) — technical endpoints
- [customer-portal.md](customer-portal.md) — customer portal links

## Notes

- Do **not** overwrite or rename seeded demo partners; create QA partners only with prefix `QA Cursor ` if needed.
- Duplicate GLNs cause matching and ATP confusion — search before creating.
- Soft-disable partners you no longer trade with instead of hard-deleting history.
