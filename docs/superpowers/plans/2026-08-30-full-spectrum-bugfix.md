# Full-Spectrum Bug Fix Sweep

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans`. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close confirmed Critical/High bugs from a full-spectrum audit (security residual + DSCSA/EPCIS + correctness/reliability), then address Medium leftovers — TDD, minimal diffs, no commit unless asked.

**Architecture:** Four lenses ran in parallel (security residual, DSCSA integrity, correctness/reliability, uncommitted WIP). Findings below are spot-checked. Fix in severity order; prefer reuse of `SiteAccess`, `EpcisSubscriptionUrl`, and Guardian residual patterns already in-tree.

**Tech stack:** Laravel 13, Filament 5, Pest/PHPUnit, tenant L3 Guardian, EPCIS ingest/archive, Horizon queues.

**Skills:** `using-superpowers`, `brainstorming`, `writing-plans`, `advanced-code-audit-debug`, `dscsa-serialization-audit-debug`, `systematic-debugging`, `laravel-tdd`, `laravel-security-audit`.

**Approach chosen:** Parallel multi-lens hunt → ranked fix plan (vs sequential-only or diff-only). Diff-only would miss HTTPS double-send / subscription retries; security-only would miss Guardian SoR gaps.

**Global constraints:**
- Edit only `/dpool/tracepharma` (source-first deploy).
- No commit/push unless user asks.
- TDD: failing test before production fix.
- Do not reopen already-closed Guardian residual items (failed→reprocess terminal, accepted-lot overwrite, job-time enablement, blank Type) unless regression found.
- Do not weaken SSRF guards; if HTTP L3 was intentional, document + allowlist explicitly rather than reopening private hosts.

---

## Audit verdict (confirmed, ranked)

### Critical

| # | Issue | Where |
|---|--------|--------|
| C1 | Guardian can mark lot/feed `accepted` when `validated` doc has **0 events** (silent `event_id` replay skip + unstable base time) | `ConvertAndAcceptGuardianLotJob` + `ProcessEpcisDocument::persistEvent` |

### High — DSCSA / Guardian

| # | Issue | Where |
|---|--------|--------|
| H1 | Same MessageID + **different body** returns existing feed; corrected XML never stored | `ReceiveGuardianLotFeed` ~106–116 |
| H2 | Stale `processing` redispatch (600s) vs `ShouldBeUnique` 3600s → job dropped | `ConvertAndAcceptGuardianLotJob` / `ReceiveGuardianLotFeed` |
| H3 | Crash after validate → duplicate hash → catch marks **failed** despite valid doc | job + `ReceiveEpcisUpload` |
| H4 | Concurrent feeds race on `(lot_number, unit_gtin14)` — no `lockForUpdate` | `upsertLot` |
| H5 | `CaseQty` hard-gate **fail-open** when missing/non-numeric | `AuthorGuardianLotEpcisDocument::assertCaseQuantityMatchesHierarchy` |
| H6 | Asset Tracking **as-of** includes voided/error gens (custody does not) | `BuildAssetTrace::eventsQuery` as-of branch |
| H7 | As-of HUD uses **live** aggregation children | `BuildAssetTrace` children helpers |

### High — Security residual / correctness

| # | Issue | Where |
|---|--------|--------|
| H8 | LocationDevice / ReadPoint lack SiteAccess query (Device was fixed) | Filament resources |
| H9 | Network printer `fsockopen` SSRF (no private deny) | `NetworkPrinterClient` |
| H10 | HTTPS outbound transmit send-then-mark can double-POST on retry | `ConnectionOutboundEpcisTransmitter` / `TransmitEpcisJob` |
| H11 | Subscription delivery can double-POST (`tries=5`, no unique/ledger) | `DeliverEpcisSubscriptionJob` |

### Medium (same sweep if time; else follow-up)

- SsccNumberRange SiteAccess; OutboundConnection/`EpcisSubscription` `last_error` in activity log; WMS DNS-rebinding (no pin); L3 URL persist weaker than runtime HTTPS; VRS hostname→private resolve gap; SerializationLot null `site_id` invisible; MessageID uniqueness with null `unit_gtin14`; archive hot orphans; container fields wipe before validate; Asset Tracking Guardian fields site ACL; migration status indexes; subscription unsafe-URL soft success; print/L3 forward double-send; Asset Tracking unbounded event collections; JobRoleAccess fail-open when roles off (documented).

### Out of scope / already OK

- Guardian API key cross-tenant IDOR (requires victim key).
- Residual plan items 1–4 already in working tree.
- `EpcisSubscriptionUrl` PHPUnit DNS soft-fail (tests only).
- AS2 production unsigned gate (fail-closed).

---

## Phase A — Guardian SoR integrity (Critical + High H1–H5)

### Task 1: Reject accepted-with-zero-events (C1)

**Files:** `app/Jobs/L3/ConvertAndAcceptGuardianLotJob.php`, `tests/Feature/L3/GuardianLotCloseIngestTest.php`

- [x] Write failing test: feed that validates but persists 0 events must leave lot/feed `failed` (or hard-fail before accept).
- [x] After `$document->status === 'validated'`, also require `event_count > 0` (and preferably `epc_count > 0`); else fail with clear summary.
- [x] Prefer fixing unstable `resolveBaseTime()` / LotProcessedTime so deterministic eventIDs don’t collide on retry (companion to H3).

### Task 2: MessageID body mismatch (H1)

**Files:** `app/Actions/L3/ReceiveGuardianLotFeed.php`, tests

- [x] Failing test: same MessageID, different SHA → 409/422 (or replace payload only when status is failed/received — pick one policy and document).
- [x] Recommended: if `message_id` matches and `file_sha256` differs → reject with conflict (do not redispatch old payload).

### Task 3: Unique lock vs stale redispatch (H2)

**Files:** `ConvertAndAcceptGuardianLotJob`, `ReceiveGuardianLotFeed`

- [x] Align `uniqueFor` with stale window (e.g. uniqueFor ≤ STALE_PROCESSING_SECONDS) **or** release unique lock when marking stale redispatches.
- [x] Test: processing older than stale threshold can actually run a new job.

### Task 4: Duplicate-hash recovery after validate (H3)

**Files:** job + optionally `ReceiveEpcisUpload`

- [x] On `DuplicateEpcisUploadException`, if existing doc is `validated` for this feed/message, attach `epcis_document_id` and mark accepted (do not force failed).
- [x] Failing test covering crash-after-validate retry.

### Task 5: Lot upsert locking (H4)

**Files:** `ConvertAndAcceptGuardianLotJob::upsertLot`

- [x] `lockForUpdate` on matching lot row inside the existing tenant transaction; serialize writers.
- [x] Test concurrent upserts (or sequential locked path assertion).

### Task 6: CaseQty fail-closed (H5)

**Files:** `AuthorGuardianLotEpcisDocument.php`, fixture/tests

- [x] Missing/non-numeric CaseQty when Case→Bottle hierarchy exists → throw.
- [x] Failing test with hierarchy but no CaseQty.

---

## Phase B — Asset Tracking as-of integrity (H6–H7)

### Task 7: As-of event set parity with custody

**Files:** `BuildAssetTrace.php`, `ArchivedEpcEvents.php`, `AssetTrackingPageTest.php`

- [x] As-of path exclude documents in `error`/`voided` (match `ResolveEpcCustodyAsOf`).
- [x] Archive as-of hydration applies same doc-status filter.

### Task 8: As-of children counts

**Files:** `BuildAssetTrace.php`

- [x] Children count/query as-of aware (or hide children metrics when as-of is set).
- [x] Test that packing-after-T does not inflate as-of children.

---

## Phase C — Security residual + double-send (H8–H11)

### Task 9: SiteAccess on LocationDevice + ReadPoint (+ Sscc optional)

**Files:** resources + forms + policies; mirror `DeviceResource`

- [x] `getEloquentQuery` SiteAccess; constrain site selects; policy `view`/`update` use `SiteAccess::canAccessSite` when `site_id` present.

### Task 10: Network printer SSRF

**Files:** `NetworkPrinterClient.php`, LabelPrinter form validation

- [x] Deny loopback / link-local / metadata hosts (RFC1918 **allowed** for on-prem printers — WMS posture; plan originally said RFC1918 deny, adjusted to avoid breaking warehouse printers).
- [x] Unit test rejects `169.254.169.254`.

### Task 11: HTTPS transmit + subscription idempotency

**Files:** `TransmitEpcisJob` / transmitter; `DeliverEpcisSubscriptionJob`

- [x] Prefer: mark sent / record delivery **intent** before POST with recoverable evidence, **or** `ShouldBeUnique` + delivery ledger keyed by `(subscription_id, document_id)` / `(connection, document)`.
- [x] HTTPS: align with AS2 `recoverSentFromPersistedEvidence` pattern where possible.
- [x] Tests: simulated success-then-crash does not second-POST (Http::fake assertion counts).

---

## Phase D — Medium leftovers (time-box)

Done in C pass: (1) L3 HTTPS assert on persist, (3) `last_error` logExcept, (4) SerializationLot `orWhereNull('site_id')`.

Deferred: WMS pin, status indexes, container-field replace ordering.

---

## Verification

- [x] Targeted Phase A–C filter suite green (61+ tests)
- [x] `vendor/bin/pint --dirty`
- [x] No commit unless asked

## Status

**Implemented** — pending commit/deploy.
