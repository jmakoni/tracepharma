# Inbound ASN shipment grouping (ATTP parity)

Date: 2026-09-03  
Status: draft — awaiting user review  
End state: full ATTP-style logical ASN (user chose option 3)  
First build: Phase A

## Problem

Partners often send **multiple EPCIS files** that share the same DESADV (ASN) and/or PO but contain different EPCs (split loads, size limits, CMO partials).

TracePharma today:

- Accepts those files (dedupe is SHA-256 only) — correct
- Treats each file as an independent receive (1 session ↔ 1 document) — **gap vs typical ATTP/L4**

Industry pattern: ASN/PO are **correlation keys** for a logical shipment; messages append EPCs; warehouse receives against the shipment.

## Goals

1. Group inbound documents into a durable **inbound shipment** keyed by seller + ASN (and PO when present).
2. One inbound ASN **receiving session** whose expected parents are the **union** of all shipment member documents.
3. Soft-signal when a second file joins an existing shipment.
4. Later: shipment-level completeness, UI grouping, portal/reporting.

## Non-goals

- Change SHA-256 duplicate upload rules
- Merge custody into ASN (remains per-EPC)
- Auto-merge blank-ASN documents or different ASNs
- Replace scan-first receiving

## Shipment identity

Create or attach when inbound document enrichment sets a non-blank `asn_number`:

| Field | Role |
|-------|------|
| `trading_partner_id` | Seller (SBDH / document trading partner); null-safe bucket if unknown |
| `asn_number` | Required for shipment membership |
| `customer_po` | Optional; when both docs have PO, must match to join; blank PO may join ASN-only shipment |

**Unique key (tenant DB):** `(trading_partner_id, asn_number)` with a documented null-partner sentinel strategy (e.g. `trading_partner_id` nullable + unique on `(asn_number)` only when partner null is insufficient — prefer requiring partner when possible, else use `COALESCE(trading_partner_id, 0)` generated column or separate partial unique indexes).

If PO is present on the incoming file and an existing shipment for that ASN has a **different** non-blank PO → **do not auto-join**; create a separate shipment or raise a soft warning and keep document ungrouped until operator resolves (Phase A: prefer **do not join** + soft warning on the new document).

## Data model (Phase A)

### `inbound_shipments`

- `id`
- `trading_partner_id` nullable FK
- `asn_number` string, indexed
- `customer_po` nullable string
- `status` enum-like: `open` | `receiving` | `completed` | `cancelled` (v1: mostly `open`/`receiving`)
- `document_count` int (denormalized)
- `epc_count` / `sscc_count` optional denormalized (recompute on attach)
- timestamps

### `epcis_documents`

- `inbound_shipment_id` nullable FK → `inbound_shipments`

### `receiving_sessions`

- Keep `epcis_document_id` as the document that **opened** the session (unique constraint stays for ASN kind when set)
- Add `inbound_shipment_id` nullable FK
- Expected parent seeding uses **all documents** on that shipment with status in receiving-allowed set (`validated`, or `parsed|validated` per config)

### Pivot (optional Phase A)

If unique `epcis_document_id` on sessions blocks “open from doc B while session already exists for doc A on same shipment”:

- Opening receive from any member doc must **reuse** the open/in-progress session for that `inbound_shipment_id` (not create a second session).
- Lookup order: existing session by `inbound_shipment_id`, else by `epcis_document_id`, else create with both FKs set.

## Behaviors (Phase A)

### Attach on enrich

After `EnrichEpcisDocumentShippingFields` sets `asn_number` (inbound only):

1. Resolve shipment by identity rules above  
2. Set `document.inbound_shipment_id`  
3. If shipment already had ≥1 other document → emit soft finding/exception  
   - Code: `ASN_SHIPMENT_FILE_ADDED` (new catalog code, severity **warning**, receive impact Warning)  
   - Description: second (or Nth) inbound file joined ASN {asn} shipment #{id}

Backfill: artisan command or one-shot on migrate for existing inbound docs with ASN.

### Open receive

`OpenReceivingSessionFromDocument`:

1. Ensure document attached to shipment when ASN present  
2. If shipment has open/in_progress session → return it (refresh expected lines if new docs arrived — see below)  
3. Else create session with `inbound_shipment_id` + opening `epcis_document_id`  
4. `resolveRootParentEpcIds` → union roots across **all** shipment member docs (same aggregation-root logic as today, per doc, then unique EPC ids)

### Late-arriving file while session open

When a new document attaches to a shipment that already has an open/in_progress receiving session:

- Expand expected parent lines with any new root parents not already on the session  
- Bump `expected_parent_count`  
- Soft signal as above  

### Complete receive

Phase A: unchanged completeness semantics except expected set is already the union.  
Phase B: explicit shipment-level SSCC/qty gates.

## Phase B — Completeness

- Shipment aggregates expected SSCC / parent counts vs confirmed across the session  
- Config: warn vs block on `CompleteReceivingSession` when shipment incomplete  
- Align with existing qty/split work in product audits where possible  

## Phase C — UI + portal/reporting

- Inbound EPCIS: group by shipment or shipment detail page (member files, combined items/SSCC counts)  
- Receiving HUD: show ASN + “N files in shipment”  
- Portal / compliance exports: optional shipment id / combined ASN rollup  

## Exception / catalog

| Code | Severity | When |
|------|----------|------|
| `ASN_SHIPMENT_FILE_ADDED` | warning | Document joins shipment that already had another inbound file |

Correction profile: timing/document family → investigate / waive OK.

## Testing (Phase A)

1. Two inbound docs, same partner + ASN, different EPCs → one shipment, two members  
2. Open receive from either doc → one session; expected parents = union  
3. Third file same ASN while session open → expected set expands; warning exception  
4. Same ASN, different non-blank PO → no silent join  
5. Blank ASN → no shipment  
6. Existing single-file ASN receive still works  
7. Cross-document SHA duplicate rules unchanged  

## Rollout

1. Tenant migration + model + attach-on-enrich + soft signal  
2. OpenReceivingSessionFromDocument union + session reuse by shipment  
3. Late-file expand expected lines  
4. Backfill command  
5. Phase B/C follow-up specs  

## Open questions (defaults if unanswered)

1. **PO mismatch:** do not join (default above)  
2. **Null trading partner:** still group by ASN alone within tenant (weaker) vs leave ungrouped — **default: group by ASN when partner null**  
3. **Outbound documents:** never join inbound shipments  

## Success criteria (Phase A)

Two validated inbound files, same seller + ASN, different EPCs → one `inbound_shipment`, one receiving session, scans against EPCs from either file count toward that session; attaching the second file raises `ASN_SHIPMENT_FILE_ADDED`.
