# Inbound and outbound connections

Filament classes:

- `App\Filament\App\Resources\InboundConnections\InboundConnectionResource`
- `App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource`

## When to use

Create and maintain partner endpoints for receiving and sending EPCIS (or related) documents.

## Prerequisites

- Partner identifiers (GLN, credentials, URLs) from your trading partner.
- Integrations admin rights; secrets handled per tenant security policy.

## Steps

1. Open **Inbound connections** or **Outbound connections**. Open the page and use Help for live UI.
2. Create a connection: endpoint, auth, partner mapping, and enabled flags.
3. Save and run a smoke test / first document if available.
4. Monitor via Integration health; disable rather than delete when temporarily pausing.

## Related pages

- [integration-health.md](integration-health.md) — health overview
- [epcis-subscriptions.md](epcis-subscriptions.md) — push/pull subscriptions
- [api-tokens.md](api-tokens.md) — API auth for programmatic ingest
- [partner-onboarding.md](partner-onboarding.md) — partner invite kit

## Notes

- Rotating credentials invalidates in-flight partner configs — coordinate cutover windows.
- Inbound and outbound are separate resources; a bidirectional partner usually needs both.
