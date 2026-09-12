# PACK_HIERARCHY_TIME_INVERSION

Date: 2026-09-03  
Status: approved

## Problem

Partner hubs (e.g. LSPedia) reject EPCIS when an intermediate pack (inner pack / case) is timed into a higher parent **before** its own child packing event. TracePharma ingested those events but did not flag them.

## Rule

On packing `AggregationEvent` with action `ADD` in the document’s active ingest generation:

- For each EPC that appears as both `parentID` and `childEPC`
- If earliest `childEPC` pack `event_time` **<** earliest `parentID` pack `event_time`
- Raise `PACK_HIERARCHY_TIME_INVERSION` (error), linked to that EPC and the earlier child-into-parent event

Equal times are out of scope (`EVENTS_OUT_OF_ORDER`).

## Wiring

- `EpcisValidationCatalog`, `ExceptionTypeSeeder`, severity map (error), receive-impact (BusinessRule), correction profile (timing family)
- Implement in `EpcisCatalogBusinessRules` timing pass
- Do not reuse orphan `TIMING_INVERSION`

## Verification

Wholesaler inbound doc #3 SGTIN `urn:epc:id:sgtin:030116.5200116.10000009651279` must raise this finding after validate/reprocess.
