---
title: Inbound EPCIS
parent: exceptions
order: 25
group: Receiving
---

# Inbound EPCIS

Filament classes:

- `App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource`

## When to use

Browse received EPCIS documents, inspect parse/validation status, and start receiving when appropriate.

## Prerequisites

- Inbound connections or subscriptions delivering documents.
- Receiving / investigator permissions as needed.

## Steps

1. Open **EPCIS documents** (inbound). Open the page and use Help for live UI.
2. Filter by partner, status, or received time.
3. Open a document; review events and linked exceptions.
4. Start receiving from the document when the flow is ready, or requeue after fixes.

## Related pages

- [exceptions.md](../exceptions/exceptions) — validation failures
- [../operations/epcis-jobs.md](../operations/epcis-jobs) — processing jobs
- [../integrations/epcis-subscriptions.md](../integrations/epcis-subscriptions) — subscription sources
- [../compliance/partner-ingest-quality.md](../compliance/partner-ingest-quality) — partner quality trends

## Notes

- Failed documents usually need partner correction or mapping fixes before reprocess.
- Starting receive from a bad document can create more exceptions — triage first.
