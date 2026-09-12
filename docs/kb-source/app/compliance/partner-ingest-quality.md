# Partner data quality

Filament classes:

- `App\Filament\App\Pages\PartnerIngestQuality`

## When to use

Monitor inbound partner EPCIS / document quality: parse failures, schema issues, missing fields, and recurring partner defects.

## Prerequisites

- Active inbound connections or subscriptions receiving partner files.
- Integrations or compliance access.

## Steps

1. Open **Partner data quality**. Open the page and use Help for live UI.
2. Sort partners by error rate, volume, or recent failures.
3. Drill into sample documents or exceptions for root cause.
4. Escalate to the partner via onboarding kit / correction request, then re-check metrics.

## Related pages

- [../integrations/integration-health.md](../integrations/integration-health.md) — connection-level health
- [../integrations/connections.md](../integrations/connections.md) — inbound/outbound connections
- [../exceptions/inbound-epcis.md](../exceptions/inbound-epcis.md) — inbound document list
- [compliance-alerts.md](compliance-alerts.md) — quality-driven alerts

## Notes

- Quality scores lag slightly behind live ingest; refresh after remediation jobs finish.
- Chronic offenders often need subscription or mapping fixes, not one-off document retries.
