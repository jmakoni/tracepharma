# Codebase Bug Audit — Round 2 — 2026-09-02

> Skill-driven audit: `advanced-code-audit-debug`, `dscsa-serialization-audit-debug`, `laravel-security-audit`, `fullstack-code-integrity-ux`, `systematic-debugging`, `verification-before-completion`.

**Branch:** `release/1.1-1.4-gtm-waves`  
**Prior audit:** `docs/superpowers/specs/2026-09-01-codebase-bug-audit.md` (backlog closed)  
**Plan:** `docs/superpowers/plans/2026-09-02-codebase-bug-audit-round2.md`

---

## Executive summary

| Severity | Confirmed | Fixed in source | Backlog |
|----------|-----------|-----------------|---------|
| Critical | 0 | 0 | 0 |
| High | 7 | 7 | 0 |
| Medium | 18 | 18 | 0 |
| Low | 10 | 10 | 0 |

**Phase 0:** App boots; Round 1 regression **26/26**; P1+P2 regression pack green.

**P2 fix queue — completed 2026-09-02:**

7. ~~**TEN-R2-3**~~ — `TenantRunner` on Guardian/subscription/LPN/seed-templates jobs
8. ~~**SEC-R2-3**~~ — OIDC email-link cannot overwrite existing subject/issuer binding
9. ~~**EPC-R2-5/6**~~ — Direction-scoped SHA dedup (authored persist + catalog rule)
10. ~~**GTM-R2-2..5**~~ — Principals owner gate, CSV truncation warning, portal `reason_code` enum, export email TTL messaging
11. ~~**TEN-R2-5/6**~~ — Export storage purge on fail; purge failed rows with orphan files; commands use `TenantRunner`

**Remaining High:** None — all Round 2 findings addressed (including SEC-R2-4 FK).

**Round 2 backlog:** Closed — High/Medium/Low queues complete; test gaps and PHPStan full baseline added.

---

## Confirmed bugs — backlog (merged from lanes A–D)

### Security ([Lane A](2c974a55-758a-40fe-9102-9d7d82bc26b6))

| ID | Sev | Issue | Location | Fix |
|----|-----|-------|----------|-----|
| SEC-R2-1 | **High** | ~~Portal `show()` renders GTIN/serial/lot/outcome before secure-code unlock~~ **Fixed** | `VerificationRequestPortalController`, unlock views | Generic pre-auth page; details after unlock |
| SEC-R2-2 | Med | ~~Case UUID enumeration via differing invalid/expired/responded views~~ **Fixed** | Portal views | Single indistinguishable pre-auth template |
| SEC-R2-3 | Med | ~~OIDC email fallback overwrites `oidc_subject` on existing password users~~ **Fixed** | `OidcIdentityResolver.php` | Require subject match when binding exists |
| SEC-R2-4 | **High** | ~~Export `show` allows any `epcis:view` user when `requested_by_user_id` null~~ **Fixed** (403 fail-closed; job marks failed when actor missing; FK `restrictOnDelete`) | `DataExportController.php`, `ProcessTrackTraceExportJob`, migration `2026_09_01_210000_create_data_exports_table.php`, `2026_09_02_130000_restrict_data_exports_requested_by_user_fk.php` | ~~Consider `restrictOnDelete` or orphan policy~~ Done |
| SEC-R2-5 | Med | ~~Over-permissive `$fillable` on `DataExport`, `VerificationRequestCase`~~ **Fixed** | Models | Narrow fillable; guard status/path/hash |
| SEC-R2-6 | Low | ~~`storage_path` not validated before download~~ **Fixed** | `DataExportDownloadController` | Regex validate `exports/{tenantId}/…` |
| SEC-R2-7 | Low | ~~Impersonation token still in URL path (logs/history)~~ **Fixed** | `StartTenantUserImpersonation`, routes | Opaque ID + server-side token store |
| SEC-R2-8 | Low | ~~OIDC nonce generated but not sent/validated~~ **Fixed** | `OidcState`, `OidcAuthenticator` | Pass + validate nonce in id_token |
| SEC-R2-9 | Low | ~~SSO callback leaks exception message to login form~~ **Fixed** | `OidcController.php:26-28` | Generic user message; log detail |
| SEC-R2-10 | Low | ~~Empty `allowedEmailDomains` permits any domain~~ **Fixed** | `OidcIdentityResolver` | Default-deny or require non-empty allowlist |

**Verified OK:** Admin OIDC issuer+subject, impersonation POST/TTL/IP, portal throttling, `EnsurePortalUserIsActive`, signed export downloads, SEC-6/7/8 role gates.

### EPCIS / DSCSA ([Lane B](06907c98-1440-4922-b35b-914ca5e6015e))

| ID | Sev | Issue | Location | Fix |
|----|-----|-------|----------|-----|
| EPC-R2-1 | **High** | ~~`uniqueId()` identical for Process + ValidateAndCommit~~ **Fixed** | Both job classes | Prefix per job type (`process:` vs `validate-commit:`) |
| EPC-R2-2 | **High** | ~~Rules export `document_id` subquery ignores site constraints~~ **Fixed** | `TrackTraceExportQuery.php` | Scope subquery with `SiteAccess` |
| EPC-R2-3 | Med | ~~Row cap enforced only at queue time, not in job~~ **Fixed** | `QueueTrackTraceExport`, `ProcessTrackTraceExportJob` | Re-count at job start or cap while streaming |
| EPC-R2-4 | Med | ~~Export job runs without actor → skips site access~~ **Fixed** | `ProcessTrackTraceExportJob`, `TrackTraceExportQuery` | Fail if user missing (overlaps SEC-R2-4) |
| EPC-R2-5 | Med | ~~SSCC authoring dedup by SHA only, not direction~~ **Fixed** | `PersistAuthoredSsccEpcis` vs `ReceiveEpcisUpload` | Direction-scoped lock + dedup keys |
| EPC-R2-6 | Med | ~~`DUPLICATE_TRANSMISSION` ignores direction~~ **Fixed** | `EpcisCatalogBusinessRules` | Scope by direction |
| EPC-R2-7 | Low | ~~`ProcessEpcisDocumentJob::failed()` missing platform alert~~ **Fixed** | vs `ValidateAndCommitEpcisDocumentJob` | Mirror dispatcher call |
| EPC-R2-8 | Low | ~~`Cache::lock()` on packing/disposition/WMS (carry-forward)~~ **Fixed** | 11 sites | Migrate to `EpcisCacheLock` |

**Verified OK:** EPC-4..8 (ladder, requeue archive, Guardian, XSD/SBDH, export streaming via cursor).

### Tenancy / ops ([Lane C](ff8d2f76-2337-458b-8aef-eed44ee36265))

| ID | Sev | Issue | Location | Fix |
|----|-----|-------|----------|-----|
| TEN-R2-1 | **High** | ~~Export job `$tenant->run` without `TenantRunner`~~ **Fixed** | `ProcessTrackTraceExportJob` | `TenantRunner::run()` |
| TEN-R2-2 | **High** | ~~All 6 webhook controllers leak tenancy on exception~~ **Fixed** | `Http/Controllers/Webhooks/*` | `TenantRunner` |
| TEN-R2-3 | **High** | ~~Guardian, subscription, LPN, seed-templates jobs same pattern~~ **Fixed** | 4 queue jobs | `TenantRunner` |
| TEN-R2-4 | **High** | ~~`EpcisHubRouter` uses raw `$tenant->run`~~ **Fixed** | `EpcisHubRouter.php:59` | `TenantRunner` |
| TEN-R2-5 | Med | ~~Stale export fail leaves storage files~~ **Fixed** | `FailStaleDataExports`, `DataExport::markFailed` | Delete object on stale fail |
| TEN-R2-6 | Med | ~~Purge skips failed exports / orphan files~~ **Fixed** | `PurgeExpiredDataExports` | Extend lifecycle cleanup |
| TEN-R2-10 | Med | ~~Multi-tenant CLI loops without `finally`~~ **Fixed** | Several commands | `TenantRunner` or `try/finally` |
| TEN-R2-11 | Med | ~~Impersonate user picker leaks tenancy~~ **Fixed** | `ImpersonateTenantUserAction` | `TenantRunner` |

**Verified OK:** Announcement fan-out/retire use `TenantRunner`; `CentralConnection` on announcements; `Product::scopeRx()` pattern.

### GTM / UX ([Lane D](bb2e32dd-ebbc-42ad-955d-effefbffd0db))

| ID | Sev | Issue | Location | Fix |
|----|-----|-------|----------|-----|
| GTM-R2-1 | **High** | ~~Track-and-trace export API not gated by profile/feature flag~~ **Fixed** | `TrackTraceExportController`, `TenantFeatures` | Add `supportsTrackAndTraceExport()` |
| GTM-R2-2 | Med | ~~Principals nav visible to non-owners when job roles off~~ **Fixed** | `PrincipalResource::canAccess` | `allowsOwnerOrAny(NavMasterData)` |
| GTM-R2-3 | Med | ~~Find/Recall CSV export silently caps at 1,000 rows~~ **Fixed** (warning notification) | `ListEpcisDocuments` | Warn or route to async export |
| GTM-R2-4 | Med | ~~VRS portal invalid `reason_code` can 500 (`ValueError`)~~ **Fixed** | Portal `submit` | `Rule::enum` validation |
| GTM-R2-5 | Med | ~~Export email expiry (7d) vs signed URL TTL (60m) mismatch~~ **Fixed** (messaging) | `TrackTraceExportReadyMail` | Align messaging or TTL |
| GTM-R2-6 | Low | ~~Portal flag not enforced in `openFromVerification()`~~ **Fixed** | `VerificationRequestCaseService` | Guard in service |
| GTM-R2-7 | Med | ~~DSCSA compliance PDF loads unbounded serials (OOM risk)~~ **Fixed** | `SerialSelector`, `DscsaComplianceReportGenerator` | Cap or async export |
| GTM-R2-8 | Low | ~~Admin/App Filament plugins lack `class_exists` guards~~ **Fixed** | Panel providers | Optional plugin guards |

---

## Fix queue (priority)

1. ~~SEC-R2-1 — portal pre-auth disclosure~~ **Done**
2. ~~SEC-R2-4 + EPC-R2-4 — export auth fail-closed~~ **Done**
3. ~~EPC-R2-1 — job `uniqueId` prefix~~ **Done**
4. ~~EPC-R2-2 — export site-scoped `document_id`~~ **Done**
5. ~~TEN-R2-1, TEN-R2-2, TEN-R2-4 — `TenantRunner` on export job, webhooks, hub router~~ **Done**
6. ~~GTM-R2-1 — export feature flag~~ **Done**
7. ~~TEN-R2-3 — `TenantRunner` on Guardian/subscription/LPN/seed-templates jobs~~ **Done**
8. ~~SEC-R2-3 — OIDC subject hijack on email link~~ **Done**
9. ~~EPC-R2-5/6 — direction-scoped dedup~~ **Done**
10. ~~GTM-R2-2..5 — UX/policy alignment~~ **Done**
11. ~~TEN-R2-5/6 — export storage lifecycle~~ **Done**
12. ~~P3 — cache lock migration, PHPStan, plugin guards~~ **Done** (2026-09-02)
13. ~~Medium queue — portal views, fillable, export re-count, TenantRunner CLI/impersonate, DSCSA serial cap~~ **Done** (2026-09-02)
14. ~~Low queue — export path validation, impersonation public_id, OIDC hardening, EPC alert, portal service guard~~ **Done** (2026-09-02)
15. ~~Remaining — SEC-R2-4 FK, EPC-R2-2 site test, PHPStan full baseline, SeedSystemOutboundTemplates import~~ **Done** (2026-09-02)

**Remaining items deliverables:**
- **SEC-R2-4:** Tenant migration `restrictOnDelete` on `data_exports.requested_by_user_id` (blocks silent orphan exports on user delete)
- **EPC-R2-2:** `post_with_document_id_returns_422_for_other_site_document`, `rules_export_document_id_subquery_respects_site_access`
- **PHPStan:** `phpstan-baseline.neon` + `composer analyse:full` passes; scoped `composer analyse` adds `OidcIdTokenValidator`
- **Ops:** Fixed missing `TenantWithDatabase` import in `SeedSystemOutboundTemplates` (unblocked impersonation provisioning tests)

**Low queue deliverables:**
- **SEC-R2-6:** Export download validates disk + canonical `storageObjectKey()` path
- **SEC-R2-7:** Impersonation URLs use opaque `public_id` UUID; secret `token` stays server-side
- **SEC-R2-8:** OIDC nonce sent on authorize + validated from `id_token` via `OidcIdTokenValidator`
- **SEC-R2-9:** SSO callbacks show generic failure message; details via `report($e)`
- **SEC-R2-10:** Tenant SSO default-deny when `allowedEmailDomains` is empty
- **EPC-R2-7:** `ProcessEpcisDocumentJob::failed()` dispatches platform support alert
- **GTM-R2-6:** `openFromVerification()` rejects when portal feature disabled

**Medium queue deliverables:**
- **SEC-R2-2:** Unified `invalid` view for expired/responded/respond routes; removed unused portal blades
- **SEC-R2-5:** Narrowed `$fillable` on `DataExport` / `VerificationRequestCase`; guarded fields via `forceFill`
- **EPC-R2-3:** `ProcessTrackTraceExportJob` re-counts rows before export
- **TEN-R2-10/11:** All CLI tenant loops + impersonate picker use `TenantRunner`
- **GTM-R2-7:** `tracepharma.exports.compliance_report_max_serials` (default 50k) enforced in `ComplianceReportDataBuilder`

**P3 deliverables:**
- **EPC-R2-8:** Tenant-context `Cache::lock()` migrated to `EpcisCacheLock::lock()` (packing, disposition, WMS, SSCC print, receiving LPN)
- **GTM-R2-8:** `OptionalFilamentPlugins` + `class_exists` guards on all Filament panel providers
- **Tooling:** Larastan + scoped `phpstan.neon` (L5 on lock/plugin paths) + `composer analyse` / `composer analyse:full` + `scripts/audit-epcis-cache-locks.sh`

---

## Test gaps

| Finding | Test |
|---------|------|
| ~~SEC-R2-1~~ | `portal_show_does_not_leak_product_details_before_unlock` |
| ~~SEC-R2-2~~ | `portal_expired_and_responded_cases_show_indistinguishable_invalid_page` |
| ~~GTM-R2-7~~ | `job_marks_export_failed_when_compliance_serial_cap_exceeded` |
| ~~SEC-R2-3~~ | `tenant_sso_rejects_subject_mismatch_on_existing_bound_user` |
| ~~SEC-R2-6~~ | `signed_download_rejects_tampered_storage_path` |
| ~~SEC-R2-8~~ | `OidcIdTokenValidatorTest` |
| ~~SEC-R2-10~~ | `tenant_jit_rejects_when_allowed_email_domains_empty` |
| ~~GTM-R2-6~~ | `open_from_verification_rejects_when_portal_feature_disabled` |
| ~~EPC-R2-7~~ | `process_epcis_document_job_failed_dispatches_platform_alert` |
| ~~EPC-R2-1~~ | `ValidateAndCommitEpcisDocumentJobTest` |
| ~~EPC-R2-2~~ | `post_with_document_id_returns_422_for_other_site_document`, `rules_export_document_id_subquery_respects_site_access` |
| GTM-R2-2 | ~~Non-owner cannot see Principals nav when job roles off~~ `non_owner_cannot_access_principal_resource_when_job_roles_are_disabled` |

---

## Verification

```bash
composer analyse
composer analyse:full
sudo -u www-data php artisan test --filter='ValidateAndCommitEpcisDocumentJobTest|TrackTraceExportApiTest|ManufacturerVerificationRequestPortalTest|OidcSsoTest|OidcIdTokenValidatorTest|TenantUserImpersonationTest|EpcisCacheLockTest|OptionalFilamentPluginsTest|InboundEpcisJobLedgerTest::process_epcis_document_job_failed_dispatches_platform_alert'
```
