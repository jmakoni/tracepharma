---
title: Connections
parent: integrations
order: 25
group: Integrations
---

# Connections

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

- [integration-health.md](../integrations/integration-health) — health overview
- [epcis-subscriptions.md](../integrations/epcis-subscriptions) — push/pull subscriptions
- [api-tokens.md](../integrations/api-tokens) — API auth for programmatic ingest
- [partner-onboarding.md](../integrations/partner-onboarding) — partner invite kit

## Notes

- Rotating credentials invalidates in-flight partner configs — coordinate cutover windows.
- Inbound and outbound are separate resources; a bidirectional partner usually needs both.
- An **outbound** connection can link to **multiple trading partners** (shared hub/AS2 endpoint). Leave partners empty for a global connection usable by any customer.
