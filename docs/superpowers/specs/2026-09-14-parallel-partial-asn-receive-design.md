# Parallel / partial ASN receive (claim-on-scan + explicit Complete)

Date: 2026-09-14  
Status: implemented — awaiting operator verify on wholesaler floor  
Approach: extend `receiving.allow_parallel_sessions` (user chose approach 1, open rule A, Complete rule 2)

## Problem

Warehouse teams often cannot finish an entire ASN in one sitting or with one scanner:

- One EPCIS/ASN may have ~30 SSCCs or ~126 cases.
- Operator A scans 10 now; coworker is busy or next shift will take the rest.
- Or three operators split the same file concurrently (10 each).

Today (ASN, especially with parallel off):

- Opening a session can seed **all** remaining expected parents onto **that** session.
- **Complete Receive** only unlocks when **this session’s** expected set is finished (`isReadyToCompleteInboundAsn()`).
- So scanning 10 of 30 does **not** allow receiving those 10 without Accept remaining or scanning the rest.

Operators reasonably expect “partial receive” to mean: post what I scanned; leave the rest on the ASN.

## Goals

1. With **Allow parallel sessions** on, the same ASN/shipment can be received in **chunks** across people and shifts.
2. Every opener starts an **empty** session and **claims on scan** (no full ASN seed on open).
3. **Complete Receive** available after **≥1 confirmed** on that session; operator must press it (no auto-complete while parallel on).
4. No overlapping serials across live sessions (claims + already-received gates).
5. Same rule for sealed-parent (SSCC) and open-count (case) SOPs.
6. Parallel **off** keeps current seed + session-ready Complete behavior.

## Non-goals

- Renaming/replacing the org setting with a separate “partial receive” toggle (YAGNI; one setting).
- Changing scan-first or transfer-receive Complete rules.
- Accept remaining pulling the rest of the ASN onto the session.
- Hard unique “one open session per shipment” when parallel is off (soft resume stays).
- UI redesign beyond enabling Complete and minor helper copy if needed.
- Auto-provisioning / migrating tenants without an explicit ops step.

## Setting

| Key | Default | UI |
|-----|---------|-----|
| `receiving.allow_parallel_sessions` | `false` | Org Settings (existing toggle) |

No new setting. Behavior below applies only when this is **on**.

## Open rules (parallel on)

1. Opening an inbound ASN session for a shipment **always** creates a **new** session (no resume of another user’s live session solely to block parallel work — existing parallel-on open path).
2. **Do not seed** expected parents on open — including the **first** opener and day-2 / next-shift (change from today’s “seed unless another live peer exists”).
3. On successful scan of an available expected EPC:
   - Claim the expected line(s) for this session (`claimed_receiving_session_id`).
   - Add/confirm scan lines per existing Receive SOP (sealed parent vs open-count).
4. Reject confirm when:
   - Already confirmed / received → `already_received` (or equivalent).
   - Claimed by another **live** session → `claimed_other_session`.

## Complete rules (parallel on)

1. **Complete Receive** visible/enabled when:
   - Session is `open` / `in_progress`, and
   - At least one confirmed scan line on the session (`confirmed ≥ 1`), and
   - Open-tote lock does not block (unfinished expected children under active parent).
2. Empty session with zero confirms → Complete **hidden/disabled**.
3. **No auto-complete on scan** while parallel is on (ignore `autoCompleteAsnOnReady` for ASN auto-flip). Operator must press Complete (same spirit as scan-first).
4. Complete posts receive / EPCIS for **this session’s confirmed** EPCs only (existing `CompleteReceivingSession` path).
5. After complete: release unconfirmed claims for this session; refresh shipment/order rollups; shipment remains open until all expected lines on the order are received.
6. **Accept remaining** stays session-scoped only (lines already expected on this session). It must not claim the rest of the ASN.

## Parallel off (unchanged)

- Second opener resumes existing open/in_progress session when applicable.
- Open seeds remaining available expected parents.
- Complete gated by `isReadyToCompleteInboundAsn()` (session expected finished).
- Existing auto-complete / Accept remaining behavior retained.

## Data / infrastructure

Depends on existing:

- `inbound_expected_lines` (+ `claimed_receiving_session_id`)
- `InboundExpectedLineClaims`
- Parallel open/confirm/complete hooks already sketched in codebase

**Tenant prerequisite:** target tenants (e.g. wholesaler) must have tenant migrations applied before parallel/claim behavior is reliable. Missing table → fail soft / do not pretend claims work.

## UI

- Desktop receiving session + floor: Complete follows parallel-on rules above.
- Progress remains session-scoped; expected-order header continues to show shipment remaining when data exists.
- Optional short helper when parallel on: Complete posts **this session only**.

## Edge cases

| Case | Behavior |
|------|----------|
| Two scanners, same SSCC | First claim wins; second blocked |
| Cancel session | Release unconfirmed claims |
| Next shift after partial complete | New empty session; scan from remaining |
| Parallel on, no expected-order table | Soft-fail; migrate tenant |
| Open-count SOP | Complete after ≥1 confirmed case (same ≥1 rule) |

## Test plan (acceptance)

1. Parallel on: first opener has empty expected; scan N parents; Complete succeeds; shipment still has remaining expected.
2. Two live sessions same shipment: disjoint confirms; each Completes independently; cross-scan blocked.
3. After A completes partial: B opens empty session, scans leftover, Completes; shipment completes when none remain.
4. Parallel on: confirming one parent does **not** auto-complete the session.
5. Parallel off: regression — seed remaining; Complete only when session ready.
6. Open-tote: Complete still blocked while active tote has expected children; Accept remaining session-only still works.

## Implementation sketch (for plan)

1. `OpenReceivingSessionFromDocument`: when `allowParallel && shipmentId`, **never** seed on open (`seedOnOpen = false`).
2. `canCompleteManually` (HUD + Scan In + floor): if parallel on + inbound ASN → `confirmedCount() ≥ 1` && !openToteLock (instead of full `isReadyToCompleteInboundAsn`).
3. `ConfirmReceivingScan` ASN auto-complete path: skip when parallel on.
4. Ensure claim-on-scan / expand still claims and updates session counters as today for peers.
5. Tests in `ParallelReceivingSessionsTest` (and HUD visibility if covered).
6. Docs / org helper text if the toggle description still implies “peers only.”

## Out of scope / later

- Separate “partial receive” product name in UI copy beyond helper text.
- Packing-list dock SOP picker.
- Forcing migration on all tenants in this change set.
