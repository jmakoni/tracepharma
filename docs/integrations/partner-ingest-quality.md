# Partner ingest quality rollup

> **Honesty:** This is an **internal** operational rollup of inbound EPCIS ingest exceptions by trading partner.
>
> It is **NOT clean-data certified** and **NOT TraceReady** (or GS1 Trustmark / Gateway Checker certified).
> Do not present these counts as a third-party clean-data or certification product.

## What it shows

Filament App page **Compliance → Partner data quality** (`PartnerIngestQuality`):

| Column | Meaning |
|--------|---------|
| Partner | Trading partner linked on the inbound `epcis_documents` row |
| Exceptions (7d) | Count of `epcis_exceptions` created in the last 7 days on those inbound docs |
| Exceptions (30d) | Same count over the last 30 days |

Counts include all exception types on inbound documents (parse errors, catalog validation failures, soft signals, etc.). Partners with no inbound exceptions in the 30-day window do not appear (empty table is normal for quiet tenants).

## Access

- Tenant must `supportsInboundIntegrations()` (Buying Group and similar profiles cannot access).
- Job-role gate: `NavCompliance` or `NavIntegrations` when job roles are enabled.

## Implementation

- Metrics: `App\Support\Integrations\PartnerIngestQualityMetrics`
- Page: `App\Filament\App\Pages\PartnerIngestQuality`

Optional hard gates (block receive on partner fail-rate) are **out of GA**.
