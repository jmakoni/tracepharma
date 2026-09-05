---
title: Tracing and FDA 3911
parent: compliance
order: 65
group: Compliance
---

# Tracing and FDA 3911

Filament classes:

- `App\Filament\App\Resources\TracingRequests\TracingRequestResource`
- `App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource`

## When to use

Respond to DSCSA tracing requests and prepare / track FDA Form 3911 illegitimate product reports.

## Prerequisites

- Investigator or compliance permissions.
- EPC / lot context and partner contacts for outbound tracing responses.

## Steps

1. Open **Tracing requests**; create or open a request. Open the page and use Help for live UI.
2. Attach evidence (events, documents, partner responses) and advance status to closed.
3. Open **FDA 3911 reports** when filing or tracking Form 3911 submissions.
4. Link related exceptions, verifications, and quarantine holds for an audit trail.

## Related pages

- [../exceptions/exceptions.md](../exceptions/exceptions) — investigation records
- [../exceptions/verifications.md](../exceptions/verifications) — VRS / verification history
- [quarantine.md](../compliance/quarantine) — hold product under investigation
- [recall-and-inspection.md](../compliance/recall-and-inspection) — broader recall/inspection tools

## Notes

- Tracing and 3911 are distinct legal workflows; do not treat a closed tracing request as a filed 3911.
- Keep partner communications in-system when possible for inspection readiness.
