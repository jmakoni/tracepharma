# Codebase Bug Audit — Round 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Systematically discover, confirm, and triage bugs across TracePharma — with emphasis on new GTM wave code, deferred Round 1 findings, and regulated-path integrity — producing a prioritized spec at `docs/superpowers/specs/2026-09-02-codebase-bug-audit.md`.

**Architecture:** Four parallel audit lanes (Security, EPCIS/DSCSA, Tenancy/Ops, GTM+UX) fed by a shared Phase 0 baseline and automated signal sweeps. Each lane applies domain skills, forms ranked hypotheses, confirms with reproduction tests, then merges into a single severity-ordered fix queue. No fixes in this plan unless P0 blockers prevent audit execution.

**Tech Stack:** Laravel 13, Filament 5, Stancl Tenancy, MariaDB, Pest/PHPUnit, Pint, optional Bugbot + Security Review subagents.

## Global Constraints

- Edit source only under `/dpool/tracepharma` (source-first deploy rule).
- Do not deploy to stage/prod unless explicitly asked.
- Do not commit unless explicitly asked.
- Run tests as `www-data` when `storage/` permission errors occur.
- Use `COMPOSER_PROCESS_TIMEOUT=0` for full suite; prefer targeted `--filter` during lanes.
- Round 1 report (`docs/superpowers/specs/2026-09-01-codebase-bug-audit.md`) is baseline — do not re-report fixed items unless regression found.
- Skill stack per lane is mandatory (see Task 0).

---

### Task 0: Load skills and define audit charter

**Files:**
- Read: `docs/superpowers/specs/2026-09-01-codebase-bug-audit.md`
- Create: `docs/superpowers/specs/2026-09-02-codebase-bug-audit.md` (skeleton)

**Skills (invoke before lane work):**

| Lane | Primary | Secondary |
|------|---------|-----------|
| All | `advanced-code-audit-debug` | `systematic-debugging`, `verification-before-completion` |
| Security | `laravel-security-audit` | `laravel-http-patterns` |
| EPCIS/DSCSA | `dscsa-serialization-audit-debug` | `dscsa-compliance`, `laravel-async-patterns` |
| Tenancy/Ops | `laravel-expert` | `laravel-data-patterns`, `mysql` |
| GTM+UX | `fullstack-code-integrity-ux` | `b2b-saas-ui-ux`, `tenant-type-links` |

- [ ] **Step 1:** Read Round 1 spec; copy deferred items into Round 2 "carry-forward" section.
- [ ] **Step 2:** List new/changed surface since Round 1 from `git diff` and untracked files (exports, VRS portal, migrations, API routes).
- [ ] **Step 3:** Create Round 2 spec skeleton with sections: Executive summary, Phase 0, Lanes A–D, Confirmed bugs, Backlog, Test gaps, Fix queue.

**Carry-forward from Round 1 (must re-audit):**

1. `Cache::lock()` call sites not migrated to `EpcisCacheLock` (packing, disposition, WMS, SSCC print, receiving LPN, OpenFDA downloads).
2. `$tenant->run()` without `TenantRunner` in jobs/commands/webhooks (30+ sites — grep `app/`).
3. PHPStan / CI `migrate:fresh --env=testing` not yet enforced.

**New surface (priority audit):**

1. `app/Services/Exports/*`, `app/Jobs/Exports/*`, `DataExportController`, `TrackTraceExportController`, export console commands.
2. `VerificationRequestPortalController`, `VerificationRequestCaseService`, VRS portal middleware, manufacturer mailers.
3. `routes/api.php` new endpoints (track-trace export, data export, WMS ship-confirm import fix).
4. Tenant migrations: `verification_request_*`, `data_exports`, export lookup indexes.
5. OIDC/impersonation/portal hardening — regression pass only.

---

### Task 1: Phase 0 — Test and boot baseline

**Files:**
- Verify: `composer.json` scripts, `routes/tenant.php`, `routes/api.php`
- Test: `tests/TestCase.php`, `phpunit.xml`

- [ ] **Step 1: Central migrations**

```bash
sudo -u www-data php artisan migrate --force --env=testing
```

Expected: success (no FK/name-length errors on central DB).

- [ ] **Step 2: Smoke boot**

```bash
sudo -u www-data php artisan route:list --columns=method,uri,name 2>&1 | head -5
sudo -u www-data php artisan about 2>&1 | head -20
```

Expected: no `Invalid route action` or parse errors.

- [ ] **Step 3: Lint**

```bash
composer lint
```

Expected: note pass/fail scope; if repo-wide Pint fails, scope to changed files only and record drift count.

- [ ] **Step 4: Targeted regression (Round 1 fixes)**

```bash
sudo -u www-data php artisan test --filter='OidcSsoTest|TenantUserImpersonationTest|SeedTenantRolesTest|OutboundConnectionResolverLadderTest|TenantRunnerTest|PollSftpInboundConnectionTest|PublishAnnouncementFanOutTest'
```

Expected: all pass; record any failure as P0 audit blocker.

- [ ] **Step 5: Fresh tenant provision smoke**

```bash
sudo -u www-data php artisan test --filter='SeedTenantRolesTest::job_seeds_roles_for_tenant_profile'
```

Expected: tenant migrations complete including `verification_request_cases` FKs.

- [ ] **Step 6: Record baseline in spec** — test counts, failures, infra notes (permissions, demo2 tenant staleness, composer timeout).

---

### Task 2: Phase 1 — Automated signal sweeps

**Files:** `app/`, `routes/`, `config/`, `database/migrations/`

Run each sweep; log file:line hits in spec appendix.

- [ ] **Step 1: Tenancy leak patterns**

```bash
rg '\$tenant->run\(' app/ --glob '*.php' -l
rg 'tenancy\(\)->initialize' app/ --glob '*.php'
rg 'tenancy\(\)->end' app/ --glob '*.php' -c
rg 'CentralConnection' app/Models --glob '*.php' -l
```

Flag: `$tenant->run` without nearby `finally` / `TenantRunner`; central models missing `CentralConnection`.

- [ ] **Step 2: Cache lock under tenancy**

```bash
rg 'Cache::lock\(' app/ --glob '*.php'
rg 'EpcisCacheLock' app/ --glob '*.php'
```

Flag: tenant-context hot paths still on `Cache::lock` when `CACHE_STORE=database`.

- [ ] **Step 3: AuthZ gaps**

```bash
rg 'canAccess\(\)' app/Filament --glob '*.php' -l
rg 'authorize\(|Gate::|Policy' app/Http/Controllers --glob '*.php'
rg 'withoutMiddleware|->middleware\(\[\]' routes/ --glob '*.php'
```

Flag: resources with `canCreate` but no `canAccess`; API routes missing abilities middleware; portal routes without active-user middleware.

- [ ] **Step 4: IDOR / scoping**

```bash
rg 'findOrFail\(\$' app/Http/Controllers --glob '*.php'
rg '::query\(\)->find' app/Http/Controllers --glob '*.php'
```

Manually trace controllers that accept IDs without tenant/partner/org scope checks (especially new export + VRS portal).

- [ ] **Step 5: Job idempotency / overlap**

```bash
rg 'ShouldBeUnique|WithoutOverlapping|uniqueId\(' app/Jobs --glob '*.php'
rg 'dispatch\(|::dispatch' app/Actions/Epcis --glob '*.php'
```

Flag: duplicate job classes on same document; missing `failed()` handlers on queue jobs.

- [ ] **Step 6: Migration hazards**

```bash
rg "constrained\('exception_cases'\)" database/
rg "->constrained\(" database/migrations/tenant --glob '*.php' | rg -v 'vr_|exceptions'
```

Flag: FK to wrong table; auto-generated constraint names >64 chars (MySQL 1059).

- [ ] **Step 7: Secrets / PII in logs**

```bash
rg 'Log::(info|debug|warning|error)\(' app/ --glob '*.php' | rg -i 'password|token|secret|secure_code'
```

- [ ] **Step 8: Document sweep results** in spec Phase 1 table (pattern, hit count, triage priority).

---

### Task 3: Lane A — Security audit

**Skills:** `laravel-security-audit`, `advanced-code-audit-debug`

**Primary paths:**
- `app/Services/Auth/Oidc/*`
- `app/Actions/Admin/StartTenantUserImpersonation.php`, `app/Http/Controllers/Tenant/ImpersonateController.php`
- `app/Http/Middleware/EnsurePortalUserIsActive.php`, `app/Http/Middleware/EnsureManufacturerVerificationPortalEnabled.php`
- `app/Http/Controllers/VerificationRequestPortalController.php`
- `app/Http/Controllers/Api/V1/*Export*`, `app/Http/Controllers/DataExportDownloadController.php`
- `routes/tenant.php`, `routes/api.php`
- `app/Policies/*`, Filament `canAccess` on new resources

**Checklist (each item → finding or "verified OK"):**

- [ ] OIDC: domain allowlist on link + JIT; admin issuer/subject-only; no 2FA bypass; state/nonce if applicable.
- [ ] Impersonation: POST-only redemption; TTL; single-use; admin IP binding; token not logged.
- [ ] Portal: deactivated user session kill; secure code entropy + hashing; case UUID enumeration; rate limits on portal POST.
- [ ] Exports: download authorization (tenant user vs signed URL vs API token); path traversal on `attachment_path`; export row scoped to requestor tenant.
- [ ] API tokens: abilities on new routes (`wms:ship-confirm`, track-trace export); throttle coverage.
- [ ] Mass assignment: `$fillable` / `$guarded` on new models (`DataExport`, `VerificationRequestCase`).
- [ ] CSRF: portal + impersonation redeem forms.

- [ ] **Confirm top 3 hypotheses** with feature tests or manual repro steps documented in spec.

---

### Task 4: Lane B — EPCIS / DSCSA integrity

**Skills:** `dscsa-serialization-audit-debug`, `laravel-async-patterns`

**Primary paths:**
- `app/Jobs/*Epcis*`, `app/Actions/EpcisJobs/*`
- `app/Services/Epcis/OutboundConnectionResolver.php`, `ConnectionOutboundEpcisTransmitter.php`
- `app/Support/Epcis/Validation/EpcisCatalogBusinessRules.php`
- `app/Jobs/L3/ConvertAndAcceptGuardianLotJob.php`
- `app/Services/Epcis/Outbound/OutboundEpcisXmlBuilder.php`
- `app/Actions/Labeling/PersistAuthoredSsccEpcis.php`

**Checklist:**

- [ ] Outbound ladder: partner-scoped B2B vs portal vs email; no global fallback when partner pinned.
- [ ] Job dedup: `ProcessEpcisDocumentJob` vs `ValidateAndCommitEpcisDocumentJob` `uniqueId()` parity.
- [ ] Requeue: superseded job archived; ledger consistency.
- [ ] Promote exception: legacy signal alias map; `failed()` logging.
- [ ] Guardian: failed→processing retry; accepted lot immutability; runtime settings re-check; container Type fail-closed.
- [ ] XSD: correlation header SBDH-first in `OutboundEpcisXmlBuilder`.
- [ ] Cache locks: SSCC authoring, enqueue, packing, disposition, WMS confirm — tag-safe store under Stancl tenancy.
- [ ] Track-trace export query: correct tenant scoping; no cross-tenant serial leakage in CSV; pagination/streaming memory bounds.

- [ ] **Run targeted tests:**

```bash
sudo -u www-data php artisan test tests/Feature/L3/GuardianLotCloseIngestTest.php
sudo -u www-data php artisan test tests/Unit/Services/Epcis/OutboundConnectionResolverLadderTest.php
sudo -u www-data php artisan test tests/Unit/Services/Epcis/Outbound/OutboundEpcisXmlBuilderTest.php
```

---

### Task 5: Lane C — Tenancy, jobs, and ops

**Skills:** `laravel-expert`, `laravel-data-patterns`, `mysql`

**Primary paths:**
- `app/Support/Tenancy/TenantRunner.php` + all `$tenant->run` call sites
- `app/Jobs/Announcements/*`, `app/Jobs/Exports/ProcessTrackTraceExportJob.php`
- `app/Console/Commands/FailStaleDataExports.php`, `PurgeExpiredDataExports.php`
- `app/Models/Announcement.php`, cross-DB relations on `Product`
- Webhook controllers (`app/Http/Controllers/Webhooks/*`)

**Checklist:**

- [ ] `TenantRunner` adoption: exports job, Guardian job, webhook controllers, `SeedSystemOutboundTemplates`, stale export commands.
- [ ] Fan-out: transaction atomicity; processing/failed retry; `tenancy()->end` on all paths.
- [ ] Export lifecycle: stale fail + purge commands use `TenantRunner`; orphaned files on disk vs DB rows.
- [ ] Cross-DB: `Product::fdaProduct()`, `whereHas` across central/tenant — document forbidden patterns; find live violations.
- [ ] Central models: any new central-only tables missing `CentralConnection`.
- [ ] Multi-tenant artisan loops: exception in one tenant does not leak context to next.

- [ ] **Grep diff:** produce list of `$tenant->run` files not using `TenantRunner` with risk rating (High = queue job / webhook / loop).

---

### Task 6: Lane D — GTM features + UX integrity

**Skills:** `fullstack-code-integrity-ux`, `b2b-saas-ui-ux`, `tenant-type-links`

**Primary paths:**
- `app/Filament/App/Resources/EpcisDocuments/*` (export actions)
- `app/Filament/Admin/Resources/Announcements/*`
- `resources/views/verification-request/*`, `resources/views/tenant/*`
- `app/Notifications/*Export*`, `*VerificationRequest*`
- `app/Support/TenantFeatures.php`, `TenantSettings.php`
- Filament `canAccess` on wave-3 resources (Principals, BuyingGroup, L3ForwardLog)

**Checklist:**

- [ ] Feature flags: manufacturer verification portal, track-trace export gated by profile + `TenantFeatures`.
- [ ] Filament: nav visibility matches policy; job_roles off vs on behavior consistent (`JobRoleAccess::allowsOwnerOrAny`).
- [ ] Export UX: error states, empty states, download link expiry communicated.
- [ ] VRS portal: mobile layout, form validation messages, terms acceptance recorded.
- [ ] Query safety: export list pages use pagination; no unbounded `->get()` on EPCIS event tables.
- [ ] Plugin boot: optional Filament plugins (`FilamentWatchdog`) — any other hard `use` without `class_exists`.

- [ ] **Run new feature tests if present:**

```bash
sudo -u www-data php artisan test tests/Feature/Exports/ 2>/dev/null || true
sudo -u www-data php artisan test tests/Feature/Vrs/ManufacturerVerificationRequestPortalTest.php 2>/dev/null || true
```

---

### Task 7: Phase 3 — Hypothesis confirmation and severity triage

**Files:**
- Create failing tests under `tests/Feature/` or `tests/Unit/` as needed
- Update: `docs/superpowers/specs/2026-09-02-codebase-bug-audit.md`

For each candidate finding:

- [ ] **Step 1:** State hypothesis (1 sentence) + severity draft.
- [ ] **Step 2:** Minimal repro — test, tinker, or documented HTTP steps.
- [ ] **Step 3:** Classify: **Confirmed** | **Likely** (needs staging) | **False positive**.
- [ ] **Step 4:** Assign ID (`SEC-R2-n`, `EPC-R2-n`, `TEN-R2-n`, `GTM-R2-n`).
- [ ] **Step 5:** Propose minimal fix (1–3 bullets) — do not implement unless P0.

**P0 blockers (fix before continuing audit):** boot failure, migration failure on fresh tenant, test suite cannot run.

---

### Task 8: Phase 4 — Critical finding review (subagents)

**Skills:** `review-bugbot`, `review-security` (Cursor skills → Bugbot + security-review subagents)

- [ ] **Step 1:** Run Bugbot on branch changes (`Diff: branch changes`).
- [ ] **Step 2:** Run Security Review on same diff for Critical/High security candidates.
- [ ] **Step 3:** Merge subagent findings into spec; dedupe with lane findings.

---

### Task 9: Phase 5 — Final report and fix queue

**Files:**
- Complete: `docs/superpowers/specs/2026-09-02-codebase-bug-audit.md`

**Report structure (required):**

1. Executive summary table (severity × confirmed × fixed × backlog)
2. Phase 0 baseline notes
3. Confirmed bugs (fixed in source vs backlog)
4. Likely / needs staging
5. False positives closed
6. Test gaps (tests that would catch each bug)
7. Fix queue (priority order, 1–N)
8. Tooling recommendations (PHPStan level, CI migrate:fresh, EpcisCacheLock audit script)

- [ ] **Step 1:** Complete all sections; link to file:line for each confirmed bug.
- [ ] **Step 2:** Compare against Round 1 — note regressions explicitly.
- [ ] **Step 3:** Offer execution: subagent-driven fix queue vs inline.

---

## Parallel execution map

```text
Phase 0 (Task 1) ──► Phase 1 sweeps (Task 2)
                           │
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
    Lane A (Task 3)   Lane B (Task 4)   Lane C (Task 5)   Lane D (Task 6)
         │                 │                 │                 │
         └─────────────────┴────────┬────────┴─────────────────┘
                                    ▼
                          Task 7 Triage + tests
                                    ▼
                          Task 8 Bugbot / SecReview
                                    ▼
                          Task 9 Final spec
```

Lanes A–D may run in parallel after Task 2 completes. Task 7 merges lane outputs.

---

## Verification commands (quick reference)

```bash
# Full suite (long)
COMPOSER_PROCESS_TIMEOUT=0 sudo -u www-data php artisan test

# Round 1 regression pack
sudo -u www-data php artisan test --filter='OidcSsoTest|TenantUserImpersonationTest|SeedTenantRolesTest|OutboundConnectionResolverLadderTest|GuardianLotCloseIngestTest'

# Automated sweeps (examples)
rg 'Cache::lock\(' app/ --glob '*.php' -c
rg '\$tenant->run\(' app/ --glob '*.php' | rg -v TenantRunner | head -40

# Lint
composer lint
```

---

## Out of scope (unless P0 discovered)

- Deploy to stage/prod
- Repo-wide Pint autofix on unrelated files
- Large refactors (TenantRunner everywhere) — report only unless trivial
- New features or spec changes beyond audit documentation

---

## Self-review (plan author)

| Check | Status |
|-------|--------|
| Round 1 deferred items included | ✓ |
| New GTM surface covered | ✓ |
| Skills mapped per lane | ✓ |
| Each task has verifiable steps | ✓ |
| No placeholder steps | ✓ |
| Spec output path defined | ✓ |
| Parallel lane map | ✓ |
