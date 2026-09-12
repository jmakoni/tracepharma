# Codebase Bug Audit — Round 3 — 2026-09-02

> Skill-driven audit: `advanced-code-audit-debug`, `systematic-debugging`, `dscsa-serialization-audit-debug`, `laravel-security-audit`, `verification-before-completion`.

**Branch:** `release/1.1-1.4-gtm-waves`  
**Prior audits:** Round 1 (`2026-09-01`), Round 2 (`2026-09-02`) — High queues closed  
**Method:** Three bug classes on uncommitted delta + production-class failures Round 2 missed; confirm with tests; fix Critical/High in-pass.

---

## Executive summary

| Severity | Confirmed | Fixed in source | Backlog |
|----------|-----------|-----------------|---------|
| Critical | 2 | 2 | 0 |
| High | 1 | 1 | 0 |
| Medium | 0 | 0 | 0 |
| Low | 4 | 0 | 4 |

**Phase 0:** App boots (scan-in + Filament panels); Round 2 regression pack **42/42** passed.

**High fix this pass:** MD-R3-1 — refuse SGLN derivation / authoring when GLN fails GS1 check digit.

---

## Phase 0 — Reality baseline

| Check | Result |
|-------|--------|
| `php artisan about` (www-data) | OK — Laravel 13.23 / PHP 8.5.9 |
| `route:list --path=scan-in` | OK — `filament.app.pages.scan-in` |
| Filament panels `app`, `knowledge-base`, `admin`, `admin-knowledge-base` | All resolve |
| Round 2 pack (OIDC, impersonation, TenantRunner, export API, VRS portal) | **42 passed** |

### Seed incidents (today)

| ID | Sev | Issue | Status |
|----|-----|-------|--------|
| BOOT-R3-1 | Critical | `KnowledgeBasePanelProvider::panel()` missing `return $panel` after OptionalFilamentPlugins refactor | **Fixed** (pre-pass); regression test added |
| BOOT-R3-2 | Critical | `SupplierQuarantineController` in `routes/tenant.php` without `use` | **Fixed** (pre-pass); imports verified |
| MD-R3-1 | High | Site GLN check digit ≠ SGLN-derived GLN → receiving EPCIS refuses authoring | Demo2 row fixed earlier; **code fixed this pass** |

---

## Class A — Incomplete refactors & boot killers

| ID | Sev | Status | Notes |
|----|-----|--------|-------|
| BOOT-R3-1 | Critical | Fixed | All 4 panel providers now `return $panel`; `FilamentPanelProvidersReturnPanelTest` |
| BOOT-R3-2 | Critical | Fixed | Quarantine + API route `use` imports present; `php -l` clean on changed PHP |
| BOOT-R3-3 | — | Refuted | No further missing returns in OptionalFilamentPlugins chains |
| BOOT-R3-4 | — | Refuted | Untracked/changed PHP files parse |

**Tests:** `tests/Unit/Providers/Filament/FilamentPanelProvidersReturnPanelTest.php` (4 passed)

---

## Class B — Regulated path: EPCIS authoring + GS1 identity

| ID | Sev | Status | Location | Fix |
|----|-----|--------|----------|-----|
| MD-R3-1 | **High** | **Fixed** | `SglnResolution::fromCompanyPrefix` / `fromPrefixLength`, `DerivesSgln`, receiving/unpack authoring | Require `ValidGln::normalize` before inventing SGLN; clear SGLN on save when GLN check digit fails; clearer DomainException |
| EPC-R3-1 | — | Verified OK | DSCSA shipping extension ingest | `IngestDscsaShippingExtensionsTest` 2/2 |
| EPC-R3-2 | — | Verified OK | Partner-type PDF statements + logo data URI | `DscsaDirectPurchaseStatementsTest`, `ComplianceReportBrandingTest`, compliance generator tests |
| EPC-R3-3 | — | Verified OK | Mixed direct + prev-wholesaler statements in PDF data | `it_includes_persisted_direct_purchase_and_prev_wholesaler_statements` |

**Root cause (MD-R3-1):** `fromPrefixLength` built an SGLN from the first 12 digits of a GLN with a **wrong check digit**. Parsed SGLN always encodes the *correct* check digit, so `Sgln::resolveUrn(storedGln, …)` returned null and receiving threw “company prefix required” even when an SGLN column existed.

**Tests:** `GlnSglnIdentityConsistencyTest`, `SiteSglnAutoGenerateTest::org_site_with_invalid_gln_check_digit_does_not_store_mismatched_sgln`, plus existing SGLN / DSCSA suites (33 + 3 PDF tests green).

---

## Class C — New GTM surface authZ / tenancy

| ID | Sev | Status | Notes |
|----|-----|--------|-------|
| SEC-R3-1 | — | Refuted | Quarantine routes all `signed` + `throttle:20,1` |
| GTM-R3-1 | — | Refuted | Export API abilities + feature gate + fail-closed show |
| SEC-R3-2 | — | Refuted | VRS portal feature middleware + unlock disclosure |
| TEN-R3-1 | — | Refuted | Webhooks use `TenantRunner` |
| SEC-R3-4 | — | Refuted | Sensitive fields not mass-assignable on DataExport / VerificationRequestCase |
| TEN-R3-2 | Low | Backlog | Residual `$tenant->run` in some queue jobs (not request-path) |
| GTM-R3-2 | Low | Backlog | No HTTP test for unsupported profile → 403 on track-trace export |
| SEC-R3-5 | Low | Backlog | No HTTP assert portal middleware 404 when flag off |
| SEC-R3-6 | Low | Backlog | No inactive PortalUser logout test |

**No High Class C findings** — Round 2 hardening held.

---

## False positives / already Round 2

- OpenFDA / central `Cache::lock` — intentional, out of scope
- CLI `$tenant->run` loops — not request-path; do not re-litigate as High
- Re-reporting Round 2 closed IDs — skipped unless regression (none found)

---

## Test gaps (Low backlog)

| Finding | Suggested test |
|---------|----------------|
| GTM-R3-2 | `TrackTraceExportApiTest` — BuyingGroup / unsupported profile → 403 |
| SEC-R3-5 | Portal HTTP when manufacturer verification feature disabled → 404 |
| SEC-R3-6 | Inactive portal user cannot use client portal |

---

## Fix queue

1. ~~BOOT-R3-1 / BOOT-R3-2~~ — Done (pre-pass + regression test)
2. ~~MD-R3-1~~ — Done this pass (`SglnResolution`, `DerivesSgln`, receiving + unpack messages)
3. Low backlog TEN-R3-2 / GTM-R3-2 / SEC-R3-5 / SEC-R3-6 — report only

---

## Tooling recommendations

- Keep `FilamentPanelProvidersReturnPanelTest` — catches incomplete `OptionalFilamentPlugins` refactors before opcache/demo2.
- Optional later: artisan doctor listing sites where `ValidGln::normalize(gln)` is null or `Sgln::resolveUrn(gln, null, [sgln])` is null.
- Run Class B / panel tests as `www-data` when storage is www-data-owned.

---

## Verification commands

```bash
sudo -u www-data php artisan test --filter='FilamentPanelProvidersReturnPanelTest|GlnSglnIdentityConsistencyTest|SiteSglnAutoGenerateTest|IngestDscsaShippingExtensionsTest|DscsaComplianceReportGeneratorTest|OidcSsoTest|TrackTraceExportApiTest'
```
