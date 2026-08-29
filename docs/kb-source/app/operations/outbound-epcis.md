# Outbound EPCIS documents

Filament classes:

- `App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource`

## When to use

Review EPCIS documents generated for partners (shipping, transfer, commissioning handoffs) and their delivery status.

## Prerequisites

- Outbound connections configured.
- Events authored by operational desks or jobs.

## Steps

1. Open **Outbound EPCIS documents**. Open the page and use Help for live UI.
2. Filter by partner, status, or created time.
3. Open a document; inspect payload and delivery attempts.
4. Retry failed sends after fixing connection issues.

## Related pages

- [epcis-jobs.md](epcis-jobs.md) — related processing jobs
- [../integrations/connections.md](../integrations/connections.md) — outbound endpoints
- [../compliance/l3-forward-log.md](../compliance/l3-forward-log.md) — L3 forward log
- [../integrations/integration-health.md](../integrations/integration-health.md) — health overview

## Notes

- A successful local author does not guarantee partner acceptance — watch delivery status.
- Coordinate bulk retries with the receiving partner.
