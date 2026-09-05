# L4/L5 DSCSA capability re-audit (2026-08-29)

**Date:** 2026-08-29  
**Baseline:** 2026-08-28 re-audit (TP-401–412) — L4 **35/45**, L5 **27/35**, contract **4/5**, weighted **≈78%**  
**Original written audit:** [`l4-l5-dscsa-capability-audit.md`](l4-l5-dscsa-capability-audit.md) (2026-08-28 first pass; scores there are **28/45** and are stale)  
**Method:** File + test evidence only. Absent if no evidence. No application code changes.

**What it still is:** Multi-tenant Laravel 13 / Filament 5 **L4 SoR + L5-lite gateway**. Not a national hub. Not a TraceLink/ATTP replacement.

---

## Ticket verification (TP-413 → TP-418)

### TP-413 — Live-connection quantity gate cannot be skipped — Complete

- **Files:** `ValidateOutboundShippingSend::quantityBlockers` (live-ladder + override); `OutboundConformanceState::requiresExpectedQuantity` (`first_live_lot` / `hypercare` / `live`); `OverrideOutboundShippingQuantityGate` + `shipping.quantity_gate_override` (Owner / Support Engineer); Filament `overrideQuantityGateAction`.
- **Gate:** `expected_count = 0` on live-ladder **blocks complete** unless audited override. Test / conformance still allowed. Open with 0 still allowed; gate is send/complete only. All send paths go through `ValidateOutboundShippingSend` (`CompleteOutboundShippingSession`, WMS, pharmacy desk, readiness).
- **Tests that FAIL if live-ladder check removed:** `live_connection_with_expected_count_zero_blocks_send`; `live_connection_quantity_gate_override_allows_send_with_expected_count_zero` (pre-override blocker).
- **Tests that stay green if check removed:** `test_connection_may_open_and_validate_with_expected_count_zero`; `live_connection_quantity_match_allows_complete`; `live_connection_declared_split_still_ships_confirmed_only` (positive qty / Test-state).
- **Flag:** Test/conformance + permissioned override remain **intentional opt-in skips**. No dedicated Conformance-state test (same enum branch as Test).

### TP-414 — Printed-never-shipped auto-decommission — Complete (dead catalog twin)

- **Live caller:** `DecommissionNeverShippedEpcs` → `ExceptionService` with `AUTO_DECOMMISSION_FAILED`. Finder `FindNeverShippedCommissionedEpcs`. Command `disposition:decommission-never-shipped`. **Scheduled daily 02:30** in `routes/console.php`. Hold days: `tracepharma.decommission.unshipped_hold_days` (default 30).
- **Profile:** `ExceptionCorrectionProfile` — `AUTO_DECOMMISSION_FAILED` is **not** in `operatorHiddenStubCodes()` (only `TIMING_INVERSION`, `SHIP_BEFORE_COMMISSION`).
- **Tests that FAIL if action removed:** `aged_unshipped_commissioned_epc_is_decommissioned`; `forced_failure_opens_auto_decommission_failed`; `second_run_does_not_write_duplicate_decommission_events`.
- **Tests that stay green if action removed:** `recent_commission_is_skipped`; `shipped_epc_is_skipped`; `never_shipped_auto_decommission_is_scheduled_daily` (schedule string only).
- **Flag:** `RecordOperationalEpcisCatalogSignal::autoDecommissionFailed` is still a **zero-caller stub** (same pattern TP-417 killed for L2/L3). Live path is workbench `ExceptionCase`, not the catalog hook.

### TP-415 — Event retention archive — Complete as a tool; not a scheduled policy

- **Files:** `ArchiveAgedEpcisEvents`; command `tracepharma:epcis-archive-events`; migration `2026_08_29_160000_create_epcis_event_archive_tables`; `ArchivedEpcEvents`. MOVE into `epcis_events_archive` / `event_epcs_archive` (not hard-delete of history). Threshold: `tracepharma.epcis.retention_years` (default 6).
- **Trace:** `ResolveEpcCustodyAsOf::eventsLiveAt` unions archive. `BuildAssetTrace::handle` unions via `ArchivedEpcEvents`. Filament Asset Tracking table still uses hot `eventsQuery()` only.
- **Still report-only:** `EpcisRetentionReportCommand` does not delete. Archive command is **not** in `routes/console.php`.
- **Tests that FAIL if archive action removed:** `old_event_is_archived_not_deleted`; `recent_event_stays_hot`; `as_of_finds_archived_commission_and_ship`; `dry_run_writes_nothing_and_logs_counts_only` in `ArchiveAgedEpcisEventsTest`.
- **Flag:** **Unscheduled / manual.** Soft-supersede prune still exists (TP-402; not age-retention). Not a live retention policy until scheduled.

### TP-416 — L3→L4 ingest lag — Complete (compute-on-read)

- **Files:** `L3L4IngestLag`; `LeadershipDscsaPackMetrics::l3L4IngestLag`; pack key `l3_l4_ingest_lag`. Formula: L4 commissioning `MIN(event_time)` − `COALESCE(commissioned_at, printed_at)`. SLA: `tracepharma.l3_l4_ingest.sla_hours` default **4**. No job; no archive-table read.
- **Tests that FAIL if helper/section removed:** `L3L4IngestLagTest` (all three methods).
- **Tests that stay green:** `LeadershipDscsaPackTest` — **does not assert** `l3_l4_ingest_lag`. Removing the pack section would not fail that file.

### TP-417 — Dead `l2L3ReconciliationFailure` API — Complete

- **Removed:** `RecordOperationalEpcisCatalogSignal::l2L3ReconciliationFailure` (method does not exist). Unit test `l2_l3_reconciliation_failure_hook_is_removed` asserts `method_exists` is false.
- **Live opener unchanged:** `ReconcileSsccBatchL3L4` → workbench `ExceptionCase` `L2_L3_RECONCILIATION_FAILURE`. Scheduled `sscc:reconcile-l3-l4`.
- **Flag:** Batch/schedule only, not a post-commission hook. Stale line in `l4-l5-dscsa-capability-audit.md` still says stub-only.

### TP-418 — Enterprise event-id replay no-op — Complete as application check

- **Files:** `LiveAcceptedEpcisEventId`; `ProcessEpcisDocument::persistEvent` skip insert; `ConnectionOutboundEpcisTransmitter::transmit` after TP-401 → `markSkipped` + `accepted_event_ids=N`.
- **NULL `event_id`:** unrestricted (filled-id only). Same-document reprocess allowed (`document_id != current`).
- **No global unique:** `epcis_events_event_id_unique` was dropped; only `epcis_events_doc_gen_event_id_unique` `(document_id, ingest_generation, event_id)`. Race can still insert two live rows.
- **Tests that FAIL if skip removed:** `replay_accepted_event_id_does_not_transmit_second_file` (`Http::assertSentCount(1)`); `ingest_replay_of_accepted_event_id_does_not_create_second_live_row` (live count 1).
- **Tests that stay green:** `new_event_id_transmits_once` (unique id).
- **Flag:** Application-level only. Ingest test dropped `status=validated` (replay doc may error after skip). No unit test for the helper.

---

## Scorecard (prev → new)

Scale: **0 Absent · 1 Stub · 2 Partial · 3 Functional · 4 Strong · 5 Complete**

| ID | Capability | Prev → New | What changed |
|----|------------|-----------:|--------------|
| L4.1 | Corporate serial repository | **4 → 4** | Unchanged. Point-in-time custody already landed (TP-409). |
| L4.2 | Master data SoR | **3 → 3** | Unchanged. No effective-dated MD. |
| L4.3 | Event orchestration L3→L4 | **4 → 4** | Strengthened (live qty cannot skip; app-level event-id replay; dead L2/L3 hook gone). Hold at 4: no global `event_id` unique; NULL unrestricted; L3 reconcile still batch-only. |
| L4.4 | Aggregation integrity | **4 → 4** | Unchanged (TP-403/404). |
| L4.5 | Decommissioning | **4 → 5** | Printed-never-shipped is live and scheduled. Residual: dead `autoDecommissionFailed` catalog twin. |
| L4.6 | Exception workbench | **4 → 4** | `AUTO_DECOMMISSION_FAILED` and `L2_L3_RECONCILIATION_FAILURE` are live workbench codes. Hidden stubs remain (`TIMING_INVERSION`, `SHIP_BEFORE_COMMISSION`). |
| L4.7 | Trace reconstruction | **4 → 4** | Archive union on `handle()` / as-of. Still UI/export-centric; Asset Tracking table is hot-only. |
| L4.8 | Audit / access / retention | **4 → 4** | Archive MOVE exists but **unscheduled**. Retention report still report-only. |
| L4.9 | L4 reporting | **4 → 5** | Leadership pack now includes L3→L4 lag (plus existing transmit/MDN, decomm reasons, stuck serials). |
| L5.1 | Outbound TI/TS EPCIS | **5 → 5** | Unchanged (TP-401 + dual-stack). |
| L5.2 | Transport and acks | **4 → 4** | Unchanged. |
| L5.3 | Inbound EPCIS | **4 → 4** | Ingest replay helps integrity; AS2 still not form-selectable. |
| L5.4 | Verification VRS | **3 → 3** | Unchanged. Prod default still `VRS_DRIVER=null`. |
| L5.5 | Partner lifecycle | **4 → 4** | Unchanged (conformance FSM). TP-413 uses that ladder. |
| L5.6 | Regulatory / partner tracing | **3 → 3** | Unchanged. |
| L5.7 | L5 reporting | **4 → 4** | Lag is an L4 pack metric, not a partner-health grid. |
| Contract | L4↔L5 contract | **4 → 4** | App-level event-id no-op landed. Hold at 4: no unique index; NULL unrestricted; race residual. |

### Subtotals

| | Previous (2026-08-28 re-audit) | **New** |
|--|-------------------------------|---------|
| **L4** | 35/45 (78%) | **37/45 (82%)** — L4.5 4→5, L4.9 4→5 |
| **L5** | 27/35 (77%) | **27/35 (77%)** |
| **Contract** | 4/5 | **4/5** |
| **Weighted (60% L4 / 40% L5)** | ≈78% | **≈80%** (0.6×82.2% + 0.4×77.1%) |

**Verdict:** Same product shape. Iteration-2 closed the live qty skip, auto-decommission, lag metric, dead L2/L3 hook, and app-level event-id replay. Remaining SoR holes are **unscheduled archive**, **no unique `event_id`**, and **VRS ops default**.

---

## Explicit callouts

- **`expected_count=0` on live connections:** **Blocked** at send unless audited override. Test/conformance still skip. Removing the live-ladder `if` would fail 2 tests; the other three named ACs would still pass.
- **`AUTO_DECOMMISSION_FAILED` caller:** **Live** — `DecommissionNeverShippedEpcs::openFailureCase`. **Dead twin** — `RecordOperationalEpcisCatalogSignal::autoDecommissionFailed` (zero callers).
- **Archive vs report-only retention:** Archive MOVE is implemented and tested; **not scheduled**. `EpcisRetentionReportCommand` is still report-only. Age retention is **opt-in manual**.
- **L3→L4 lag metric:** Compute-on-read on Leadership DSCSA pack. `L3L4IngestLagTest` is the real gate. `LeadershipDscsaPackTest` would not fail if the section were removed.
- **Dead `l2L3ReconciliationFailure` API:** **Removed.** Production opener remains `ReconcileSsccBatchL3L4`.
- **Event-id replay + NULL + missing unique:** App-level skip on ingest and transmit. NULL unrestricted. Unique is per `(document_id, ingest_generation, event_id)` only. Race can still create two live rows.

---

## Remaining blockers

### Before live partner traffic

- Per-environment: credentials encryption / no secrets in logs; keep MDN reject/late/missing signals on.
- Set `VRS_DRIVER=http` where dock verify is required (fail-closed; default still `null`).
- Confirm connection conformance + 1.2 vs 2.0 so JSON-LD is not sent to 1.2 partners.
- On live lots: set `expected_count` (or audited override). Test-state `0` is still a skip by design.
- Exercise SSCC completeness + qty/split on real ASN volumes.

### Before claiming full enterprise L4 SoR

- **Schedule** `tracepharma:epcis-archive-events` (or equivalent policy). Report-only is not SoR retention.
- **Global `event_id` uniqueness** (or equivalent durable constraint). App-level replay is not race-safe.
- Point-in-time custody REST product; effective-dated master data.
- Optional: inbound AS2 operator form; GS1 Query scheduler; delete the dead `autoDecommissionFailed` catalog method (hygiene, same as TP-417).

---

## Partial / stub / weak-test flags

- `RecordOperationalEpcisCatalogSignal::autoDecommissionFailed` — **dead API**
- Qty gate Test/conformance + override — **intentional opt-in**
- Archive command — **unscheduled**
- `LeadershipDscsaPackTest` vs L3→L4 lag — **would not fail if section removed**
- TP-418 ingest — does not require `validated` status; only live-row count
- TP-418 — **no unique index**; NULL unrestricted
- `docs/product/l4-l5-dscsa-capability-audit.md` — **stale** (still 28/45)

No application code was changed in this pass.
