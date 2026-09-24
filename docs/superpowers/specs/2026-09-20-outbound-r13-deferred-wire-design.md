# Outbound R1.3 deferred wire (SBDH, DETAIL, transactionDate, void, lot refuse)

Date: 2026-09-20  
Status: Draft — awaiting implementation plan

## Problem

Dual-release outbound already branches NDC shape, `guidelineVersion`, `dropShipment`, and direct-purchase construct on `trading_partners.epcis_guideline`. Five GS1 US DSCSA outbound constructs remain unimplemented. They exist only as skipped tests: TC-SBDH-001, TC-EVT-001, TC-EVT-002, TC-VOID-001, TC-LOT-001.

This spec covers those five as **serial outbound authoring plus one new void action**. It does **not** add lot-level inventory or lot-level TI.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Partner branch | Outbound still follows `trading_partners.epcis_guideline`. Inbound payload bytes stay untouched. |
| SBDH identifiers | Value stays the 13-digit GLN used today. No SGLN URN rewrite. |
| SBDH Authority | R1.2: `GLN`. R1.3: `GS1`. |
| DETAIL event | R1.3 only. R1.2 ship XML stays shipping-only. |
| transactionDate | Both guidelines when the 24-hour rule fires. Omit when no ownership-transfer time exists. |
| Void | Entire shipment only. New outbound document. Original ship payload unchanged. |
| After void | EPCs stay not-shippable until a supervisor closes the void holds. |
| Lot-level | No lot-level ship path. R1.3 GTIN+lot scan is an explicit refuse. R1.2 stays “barcode not recognized.” |

## Architecture

Authoring stays on the existing serial ship path:

- `ShippingTiTsFragments` — SBDH fragment
- `GenerateShippingEpcisEvents` — lean ship XML + persist events
- `BuildFullHistoryShippingEpcisXml` — full-history ship XML after replayed inbound commission/pack
- `ConfirmOutboundShippingScan` — R1.3 lot refuse
- New `VoidOutboundShippingSession` — void document, session flags, quarantine holds, send

`ResolveOutboundEpcisGuideline` remains the only outbound release selector.

## SBDH

`ShippingTiTsFragments::sbdhXml()` takes `EpcisGuideline`.

- R1.2: `Authority="GLN"` and 13-digit sender/receiver GLNs (today).
- R1.3: `Authority="GS1"` and the same 13-digit GLN values.

Call sites: lean header, full-history header, `OutboundEpcisXmlBuilder`. Intracompany transfer documents that have no SBDH stay without SBDH.

## DETAIL ObjectEvent (R1.3 only)

When the partner guideline is R1.3, author one extra ObjectEvent immediately before the Shipping event:

- `action` = `OBSERVE`
- `bizStep` = `urn:epcglobal:cbv:bizstep:shipping`
- Same outermost `epcList` as Shipping
- Same `sourceList` / `destinationList` and `bizTransactionList` as Shipping
- Same `eventTimeZoneOffset` as Shipping
- `eventTime` = Shipping `eventTime` minus 1 second
- No `dropShipment`, no purchase statement, no `transactionDate` on DETAIL

Lean path: DETAIL then Shipping. Full-history path: replayed commission/pack, then DETAIL, then Shipping.

If Shipping cannot be authored, do not author DETAIL. Missing prior pedigree does not block DETAIL.

## transactionDate

On the **Shipping** event only.

Ownership-transfer time = the latest `epcis_events.event_time` among events already persisted for the confirmed scan-line EPCs (and current aggregated children when a confirmed line is a parent) whose `biz_step` is receiving (`urn:epcglobal:cbv:bizstep:receiving` or the transferring-receive events authored by `GenerateTransferringReceiveEpcisEvents`).

- No such event → omit `transactionDate`. Do not invent a date. Do not fail the ship.
- If `OutboundShippingSession.completed_at` (fallback `now()`) is more than 24 hours after that transfer time → emit `<gs1ushc:transactionDate>Y-m-d</gs1ushc:transactionDate>` using the transfer event’s calendar date. Sibling `gs1ushc` extension; do not wrap in EPCIS `<extension>`.
- Shipping `eventTime` remains session complete time (shipment date).

Applies to R1.2 and R1.3. The 24-hour rule is the same in both guidelines.

## Lot refuse (TC-LOT-001)

No schema change. `outbound_shipping_scan_lines.epc_id` stays required.

In `ConfirmOutboundShippingScan`, if the scan parses as GTIN + lot and has no serial:

- Partner guideline R1.3 → typed refuse (`effect` + message that R1.3 does not allow lot-level outbound TI). No scan line.
- Partner guideline R1.2 → existing “barcode not recognized” path.

`CompleteOutboundShippingSession` does not need a second lot-level gate; scan lines cannot exist without an EPC.

## Void shipment

Visible on a **completed** outbound session that has a sent EPCIS document and is not already voided. Pre-send **Cancel** stays as today (no void event).

New action `VoidOutboundShippingSession` in one transaction:

1. Persist a new outbound EPCIS document (do not edit the original payload).
2. One ObjectEvent: `bizStep` `urn:epcglobal:cbv:bizstep:void_shipping`, `action` `OBSERVE`, same outermost EPCs as the original ship, `eventTime` = void instant (`now()`), same timezone offset rules as ship.
3. When the original shipping event has an `event_id`, include EPCIS 1.2 `errorDeclaration` / `correctiveEventIDs` pointing at that event.
4. Send on the same partner outbound connection as the original TI.
5. Set `voided_at`, `voided_by_user_id`, `void_epcis_document_id` on the session. Session **status stays `completed`**.
6. Open a `QuarantineHold` per confirmed scan-line EPC (the same outermost set as the original ship `epcList`): `reason` = `voided_shipment`, `document_id` = void document, `status` = `open`. Existing open-hold checks keep those EPCs off shippable / ship scan.

If persist or send fails: roll back session flags and holds. The original ship document remains the live TI.

Supervisor release = close those holds through the existing quarantine / exceptions UI. No new release screen. After holds close, the EPCs may be shipped again on a new session.

Partial void is out of scope.

## Data model

Tenant migration on `outbound_shipping_sessions`:

- `voided_at` nullable timestamp
- `voided_by_user_id` nullable FK to users
- `void_epcis_document_id` nullable FK to `epcis_documents`

No new tables. No change to `outbound_shipping_scan_lines`. SBDH / DETAIL / `transactionDate` use existing partner guideline and `epcis_events`.

## Error handling

| Case | Behavior |
|------|----------|
| Void with no sent document, or already voided | Refuse. No document, no holds. |
| Void persist/send fails | Transaction rollback. Original TI unchanged. |
| No ownership-transfer history | Omit `transactionDate`. Ship succeeds. |
| Partner is R1.2 | No DETAIL. SBDH `Authority="GLN"`. |
| Partner is R1.3 | DETAIL + SBDH `Authority="GS1"`. |
| GTIN+lot scan, R1.3 | Refuse. No scan line. |
| Open `voided_shipment` hold | Confirm-outbound scan of that EPC is blocked until the hold is closed. |

## Files

| File | Change |
|------|--------|
| `app/Support/Epcis/ShippingTiTsFragments.php` | Guideline-aware SBDH Authority |
| `app/Actions/Shipping/GenerateShippingEpcisEvents.php` | R1.3 DETAIL; optional `transactionDate` |
| `app/Support/Epcis/BuildFullHistoryShippingEpcisXml.php` | Same emit rules on full-history |
| `app/Services/Epcis/Outbound/OutboundEpcisXmlBuilder.php` | Pass guideline into SBDH |
| `app/Actions/Shipping/ConfirmOutboundShippingScan.php` | R1.3 lot refuse |
| `app/Actions/Shipping/VoidOutboundShippingSession.php` | New |
| Filament view outbound shipping session | Void action + voided badge |
| Tenant migration | Session void columns |
| `tests/Unit/Support/Epcis/ShippingTiTsFragmentsDropShipmentTest.php` | Unskip TC-SBDH-001 |
| `tests/Feature/Shipping/OutboundShippingSessionTest.php` | Unskip TC-EVT-001/002, TC-VOID-001, TC-LOT-001; void-hold assertion |

## Tests

Existing files only. Do not scaffold a new `tests/docs/` tree.

- TC-SBDH-001: R1.3 SBDH contains `Authority="GS1"` and not `Authority="GLN"`. Keep existing R1.2 `Authority="GLN"` assertions.
- TC-EVT-001: R1.3 ship XML has a DETAIL ObjectEvent whose `eventTime` precedes Shipping. R1.2 ship XML has no DETAIL.
- TC-EVT-002: Receiving (or transfer-receive) more than 24 hours before complete → Shipping contains `gs1ushc:transactionDate`; Shipping `eventTime` is still complete time.
- TC-VOID-001: Void event `eventTime` is the void instant, not the original ship time. Original payload still contains the original ship time and does not contain `void_shipping`.
- TC-LOT-001: R1.3 partner + GTIN+lot scan is an explicit refuse.
- After void, confirm-scan of those EPCs is blocked until the `voided_shipment` hold is closed.

## Out of scope

- Lot-level outbound inventory, quantityList TI, or mixed serial+lot orders
- SBDH Sender/Receiver as `urn:epc:id:sgln:…0`
- Partial void
- Rewriting stored inbound bytes
- DETAIL on R1.2 outbound
- Changing L3 Guardian event-time fallback
- Intracompany transfer SBDH (those documents have no SBDH today)

## Success criteria

R1.3 partner serial ship emits `Authority="GS1"`, a DETAIL event before Shipping, and `transactionDate` only when the 24-hour rule has a real transfer time. R1.2 partner serial ship keeps today’s SBDH and has no DETAIL. R1.3 lot-level scan is refused in words, not authored. A sent shipment can be voided as a new document at the void instant; units stay on hold until a supervisor releases them.
