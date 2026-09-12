# Guardian residual + EPCIS authoring bug-fix

> **Status:** Implemented — pending deploy

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans`. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Close residual correctness bugs in Guardian lot-close retry/lot-upsert paths and fix the shared outbound EPCIS header builder so correlation headers validate under GS1 EPCIS 1.2 XSD.

**Architecture:** Keep dual-layer Guardian ingest. Fix status-machine retry so `failed` → redispatch actually reprocesses. Protect accepted lot rows from destructive overwrite. Align `OutboundEpcisXmlBuilder` with SBDH-first header shape used by shipping authoring.

**Tech stack:** Laravel 13, tenant L3 models/jobs, EPCIS 1.2 XSD validation, Filament Serialization Lots / Asset Tracking.

**Skills:** `using-superpowers`, `brainstorming`, `writing-plans`, `advanced-code-audit-debug`, `dscsa-serialization-audit-debug`, `laravel-security-audit`.

**Scope (locked):** Residual Guardian L3 post-bugfix findings + shared `OutboundEpcisXmlBuilder` XSD fix.

**Out of scope:** Broader kill-switch coverage on Filament/supplier/CLI; `PersistAuthoredSsccEpcis` lock/dedup alignment; demo2 EPCIS fixture failures; whole-repo sweep.

---

## Audit verdict

After the first Guardian bug-fix pass, residual audits found:

| # | Severity | Issue |
|---|----------|--------|
| 1 | Critical | Failed-feed resubmit redispatches but job treats `failed` as terminal → silent no-op |
| 2 | High | Later failed re-ingest can overwrite an accepted lot to `failed` |
| 3 | High | Job does not re-check Manufacturer / L3 enablement / Systech at run time |
| 4 | Medium | Missing/null container `Type` bypasses fail-closed gates |
| 5 | High | `OutboundEpcisXmlBuilder` correlation header is XSD-invalid (Guardian works around by omitting it) |

Verified still OK from prior fix: outbound + `validated` gate, sha256 lock, deterministic eventIDs, Domain hard gates, receive-time Manufacturer/Systech/kill switch, stale processing, CaseQty/Bundle/URI, DOCTYPE/Content-Length, Manufacturer `canAccess`, webhooks host+IP limiter.

---

### Task 1: Failed-feed resubmit actually reprocesses (Critical)

**Files:** `app/Jobs/L3/ConvertAndAcceptGuardianLotJob.php`, `app/Models/L3/L3LotFeed.php`, `tests/Feature/L3/GuardianLotCloseIngestTest.php`

**Root cause:** Receive redispatches `failed`, but job treats `failed` as terminal and returns immediately.

- [x] Write failing test: create `failed` feed with archived payload; POST same MessageID (or dispatch job after receive); assert job runs real work — not silent no-op.
- [x] Fix: on job start, if status is `failed`, reset to `processing` (clear `error_summary`) before work; keep `accepted` as true terminal skip.
- [x] Run `php artisan test tests/Feature/L3/GuardianLotCloseIngestTest.php`.

---

### Task 2: Do not destroy an accepted lot on a later failed re-ingest (High)

**Files:** `ConvertAndAcceptGuardianLotJob.php` (`upsertLot` / catch)

**Locked policy:** Leave accepted lot untouched; fail the new feed without overwriting accepted lot fields/container rows.

- [x] Test: seed accepted lot for `(lot_number, unit_gtin14)` linked to feed A; run failing conversion for feed B same lot keys; assert lot stays `accepted` with original `epcis_document_id`.
- [x] Implement: if existing lot status is `accepted` and `feed_id` differs, throw before overwrite.
- [x] Catch block must not set `status=failed` on lots that remain accepted / unrelated `feed_id`.

---

### Task 3: Re-check Manufacturer / L3 settings at job start (High)

**Files:** Job (+ receive already gated)

- [x] At job start (with kill-switch re-check): require Manufacturer profile, `l3Enabled`, `l3GuardianLotCloseEnabled`, provider Systech; on failure mark feed `failed` and return.
- [x] Test: receive OK, then flip provider/profile/toggle; job marks failed without projecting.

---

### Task 4: Fail-closed on missing/null container Type (Medium)

**Files:** `app/Actions/L3/AuthorGuardianLotEpcisDocument.php`

- [x] Treat blank Type like unsupported Type — throw.
- [x] Test: strip `<Type>` from one Bottle in fixture → feed `failed`.

---

### Task 5: Fix `OutboundEpcisXmlBuilder` EPCISHeader XSD (High, shared)

**Files:** `app/Services/Epcis/Outbound/OutboundEpcisXmlBuilder.php`, shipping SBDH helpers for pattern

- [x] When `correlationId` present, emit `EPCISHeader` with `sbdh:StandardBusinessDocumentHeader` first, then `extension` (match shipping authoring).
- [x] Unit/feature: `buildDocument(..., correlationId: 'x')` passes XSD validation.
- [x] Optionally restore Guardian document-level correlation once builder is fixed.

---

### Task 6: Verification + docs

- [x] `php artisan test tests/Feature/L3/ tests/Feature/AssetTrackingPageTest.php` (+ any new builder unit test)
- [x] Update `docs/integrations/guardian-lot-close.md` retry semantics if needed
- [x] CHANGELOG Unreleased Fixed bullets
- [x] Do not deploy unless asked

---

## Explicit non-goals

- Kill-switch on Filament upload / supplier portal / CLI / reprocess
- `PersistAuthoredSsccEpcis` `EpcisCacheLock` + direction-scoped dedup (follow-up)
- Same MessageID + different body → 409
- Webhooks per-route limiter split
- 30k-bottle chunking
