# API tokens

Filament classes:

- `App\Filament\App\Pages\ApiTokens`

## When to use

Issue and revoke tenant API tokens for partners, PMS bridges, or internal automation calling TracePharma APIs.

## Prerequisites

- Integrations or admin role allowed to manage tokens.
- Least-privilege scopes agreed with the consumer.

## Steps

1. Open **API tokens**. Open the page and use Help for live UI.
2. Create a token with a clear name, scopes, and expiry if offered.
3. Copy the secret once; store it in the partner vault (not chat or tickets).
4. Revoke compromised or unused tokens immediately.

## Related pages

- [connections.md](connections.md) — non-token connection auth
- [integration-health.md](integration-health.md) — failure spikes after rotation
- [pms-and-wholesaler-packs.md](pms-and-wholesaler-packs.md) — PMS / WMS checklists
- [partner-onboarding.md](partner-onboarding.md) — share onboarding artifacts securely

## Notes

- Tokens are shown once at creation — treat loss as revoke-and-reissue.
- Prefer short-lived or rotated tokens for production partners.
