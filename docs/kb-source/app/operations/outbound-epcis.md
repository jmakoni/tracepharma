---
title: Outbound EPCIS
parent: operations
order: 40
---

# Outbound EPCIS

Review EPCIS documents generated for partners (shipping, transfer, commissioning handoffs) and their delivery status.

## Partner TI vs live custody

- **Download EPCIS** = partner TI payload (commission/pack/ship when full history applies).
- Live shipping-document events may be the shipping ObjectEvent only (custody).
- Pedigree XML fragments are stored in the DB on ingest (preferred for TI rebuild); payload files remain a fallback — retain for `payload_retention_years`.
- Packing `childEPCs` in rebuilt TI are filtered to open aggregation children; removed-case history stays in DB for a later ship.

## Pedigree policy

Commissioning: whole-event replay. Packing: open-tree children only. Retry Transmit remints InstanceIdentifier and stamps a new prepare-time filename (ship eventTime in XML unchanged), rebuilds shipping TI from the current hierarchy, then validates GS1 EPCIS 1.2 / GS1 US R1.3 before transmit/portal publish. Backfill: `tracepharma:epcis-backfill-pedigree-fragments --tenant=…`. Ops: `tracepharma:epcis-retention-report --check-pedigree-payloads`.
