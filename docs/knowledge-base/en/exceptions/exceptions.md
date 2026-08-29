---
title: Exceptions
parent: exceptions
order: 20
group: Receiving
---

# Exceptions

Filament classes:

- `App\Filament\App\Resources\Exceptions\ExceptionResource`

## When to use

Investigate and resolve EPCIS / DSCSA exceptions raised by hard-gate validation, partner ingest, or operational desks.

## Prerequisites

- Investigator or operations role with exception access.
- Enough context (document, EPC, site) to triage.

## Steps

1. Open **Exceptions**. Open the page and use Help for live UI.
2. Filter by status, severity, type, partner, or age.
3. Open a record; review activities, request partner correction if needed, and resolve.
4. Confirm related alerts and SLAs clear after close.

## Related pages

- [investigator-sla.md](../exceptions/investigator-sla) — aging and SLA desk
- [inbound-epcis.md](../exceptions/inbound-epcis) — source documents
- [verifications.md](../exceptions/verifications) — verification history
- [../compliance/compliance-alerts.md](../compliance/compliance-alerts) — alert center

## Notes

- Closing without partner correction can recreate the exception on reprocess.
- Keep notes suitable for inspection — this is part of the audit trail.
