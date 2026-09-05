---
title: Outbound EPCIS
parent: operations
order: 40
group: Operations
---

# Outbound EPCIS

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
4. Use **Download EPCIS** for the partner TI file (commission/pack/ship when packed).
5. Retry failed sends after fixing connection issues.

## Partner TI payload vs live custody events

| Surface | Meaning |
|---------|---------|
| **Download EPCIS** / payload path | What the trading partner receives — self-contained TI/TS XML when full history applies |
| **Live `epcis_events` for shipping docs** | Often only the authored **shipping** ObjectEvent (custody handoff) |
| **Events (TI file) count** | Event count taken from the partner payload, not a 1:1 list of live DB rows |

Do not treat the live event table alone as the TI document. Custody and inventory use DB events; partner exchange uses the retained payload.

## Pedigree (packed / prior commission)

Outbound shipping rebuilds manufacturer commission and packing by **replaying lossless DB pedigree XML fragments** (preferred) or **retained inbound / Guardian-authored payloads**, then appends the wholesaler shipping ObjectEvent. Packing `childEPCs` are filtered to the **current open aggregation tree** (removed cases stripped from TI only; fragment history kept for later ship). Fragments are written on successful ingest so TI survives payload file loss.

**Retry transmit** mints a new SBDH InstanceIdentifier and a new outbound filename stamped at prepare time (not the original ship event time); for shipping docs it rebuilds TI from confirmed parents + open hierarchy (ship `eventTime` in XML unchanged), validates GS1 EPCIS 1.2 / GS1 US R1.3 (including portal), replaces the portal-facing file, then transmits. Unpack a case or unconfirm a pallet (allowed when transmission is failed/skipped) before retry when hierarchy changed.

Backfill existing tenants: `php artisan tracepharma:epcis-backfill-pedigree-fragments --tenant=…`

Policy: **whole-event** replay (batch commissioning ObjectEvents are kept verbatim even if they list serials outside this shipment).

## Related pages

- [epcis-jobs.md](../operations/epcis-jobs) — related processing jobs
- [../integrations/connections.md](../integrations/connections) — outbound endpoints
- [../compliance/l3-forward-log.md](../compliance/l3-forward-log) — L3 forward log
- [../integrations/integration-health.md](../integrations/integration-health) — health overview

## Notes

- A successful local author does not guarantee partner acceptance — watch delivery status.
- Coordinate bulk retries with the receiving partner.
- Ops: `php artisan tracepharma:epcis-retention-report --check-pedigree-payloads`
