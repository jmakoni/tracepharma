# Laravel Auditor Report

**Generated:** 2026-09-04 19:59:05

## Project

- **name:** TracePharma
- **environment:** local
- **php version:** 8.5.10
- **laravel version:** v13.30.1
- **database:** mysql
- **test framework:** phpunit
- **frontend:** detected, detected, 1

## Summary

**Findings:** 14

| Severity | Count |
| --- | --- |
| Critical | 0 |
| High | 5 |
| Medium | 9 |
| Low | 0 |
| Info | 0 |

| Domain | Count |
| --- | --- |
| Security | 5 |
| Laravel conventions | 4 |
| Performance | 5 |

## Priority synthesis

**Final partition:** 14 unique recommendation(s). Every promoted ID appears exactly once.

- **P0 - correctness, security, or data-loss risk** (3): F-2026-0102, F-2026-0201, F-2026-0101
- **P1 - concrete correctness or high-leverage contract work** (3): F-2026-0103, F-2026-0205, F-2026-0203
- **P2 - material invariant improvements with narrower impact** (8): F-2026-0105, F-2026-0106, F-2026-0107, F-2026-0204, F-2026-0104, F-2026-0202, F-2026-0206, F-2026-0108
- **P3 - lower-impact telemetry, diagnostics, or maintainability** (0): none

## Domains Audited

- Security
- Performance
- Architecture
- Database
- Testing
- Laravel conventions

## Findings

### [HIGH] ExportTenantComplianceArchive has neither $tries nor $timeout `F-2026-0102`

**Rule:** `AUD-QUE-001` — Laravel conventions
**Severity:** High
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

Long-running tenant compliance archive job (large CSV/ZIP generation) implements ShouldQueue with no $tries and no $timeout, so a hung export can occupy a worker indefinitely and retry posture is undefined.

**Why it matters**

Compliance exports can process large activity/EPCIS inventories; unbounded worker occupancy and unclear retries risk queue starvation and duplicate archives.

**Evidence**

- `file` — app/Jobs/Tenants/ExportTenantComplianceArchive.php:17-48
  - ShouldQueue class with no $tries/$timeout properties

**Affected resources**

- `app/Jobs/Tenants/ExportTenantComplianceArchive.php`
- `app/Services/Tenants/TenantComplianceArchiveGenerator.php`

**Recommendation**

Set $timeout aligned under DB/redis retry_after (e.g. 3600) and $tries (e.g. 1–3) consistent with ImportFdaDatasetJob / ProcessTrackTraceExportJob.

**Verification notes**

Job source inspected; no tries/timeout properties present.

### [HIGH] WMS receive-confirm loads all confirmed scan lines via unbounded get() `F-2026-0103`

**Rule:** `AUD-PER-012` — Performance
**Severity:** High
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

NotifyWmsReceiveConfirm::confirmedScans() loads every confirmed ReceivingScanLine with epc via ->get() before chunking HTTP POSTs. Large ASN/scan-first sessions spike memory on the queue worker.

**Why it matters**

Hot post-complete receiving path; large sessions can OOM or slow WMS confirmation and delay custody notifications.

**Evidence**

- `file` — app/Jobs/Receiving/NotifyWmsReceiveConfirm.php:188-207
  - ReceivingScanLine::…->with('epc')->get() then map
- `file` — app/Jobs/Receiving/NotifyWmsReceiveConfirm.php:107-108
  - confirmedScans() called on complete path

**Affected resources**

- `app/Jobs/Receiving/NotifyWmsReceiveConfirm.php`

**Recommendation**

Stream/chunk URI extraction with chunkById or cursor before building POST chunks; avoid materializing the full session line set.

**Verification notes**

confirmedScans() source inspected.

### [HIGH] Mutable Admin Filament resources lack Eloquent policies `F-2026-0201`

**Rule:** `AUD-FIL-001` — Security
**Severity:** High
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

Admin panel create/edit surfaces (Admins, Announcements, Tenants, MailTemplates, FDA registry CRUD) gate access via Permissions::* canAccess/canViewAny only. App panel remediations added Eloquent policies for Role/Fda3911/etc.; Admin mutable peers still have no Admin-typed policies. Filament non-strict authorization allows abilities when no policy method exists.

**Why it matters**

Platform admin CRUD is highly privileged (tenant lifecycle, admin accounts, announcements). Missing policies weaken Gate/Filament consistency and make new mutate actions easy to ship without authorize().

**Evidence**

- `file` — app/Filament/Admin/Resources/Admins/AdminResource.php:21-42
  - Admin model; canAccess via Permissions::AdminsManage; no AdminPolicy
- `file` — app/Filament/Admin/Resources/Announcements/AnnouncementResource.php:29-55
  - Announcement create/edit; permission-only canViewAny
- `file` — app/Filament/Admin/Resources/Tenants/TenantResource.php:21-52
  - Tenant CRUD; Permissions::TenantsManage only
- `file` — app/Policies
  - No AdminPolicy/AnnouncementPolicy/TenantPolicy; App policies present

**Affected resources**

- `app/Filament/Admin/Resources/Admins/AdminResource.php`
- `app/Filament/Admin/Resources/Announcements/AnnouncementResource.php`
- `app/Filament/Admin/Resources/Tenants/TenantResource.php`
- `app/Filament/Admin/Resources/MailTemplates/MailTemplateResource.php`
- `app/Filament/Admin/Resources/Fda/FdaWddFacilities/FdaWddFacilityResource.php`
- `app/Filament/Admin/Resources/Fda/FdaEstablishments/FdaEstablishmentResource.php`
- `app/Filament/Admin/Resources/Fda/FdaOrganizations/FdaOrganizationResource.php`
- `app/Policies`

**Recommendation**

Add Admin-guard policies (AdminPolicy, AnnouncementPolicy, TenantPolicy, …) mirroring Permissions::* gates; type-hint App\Models\Admin; wire Filament can* to policies.

**Verification notes**

App panel 29/29 resources mapped to policies after F-0101/0104; Admin mutable resources have no matching *Policy.php; tests/Feature/Policies has no Admin policy coverage.

### [HIGH] Match-ASN scan-first propagate loads unbounded sessions and confirmed lines `F-2026-0205`

**Rule:** `AUD-PER-012` — Performance
**Severity:** High
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

PropagateScanFirstConfirmsToAsnSession loads every scan-first ReceivingSession in open|in_progress|completed (site-filtered only, no time bound) via ->get(), then CopyConfirmedReceivingScansToSession loads all confirmed lines with epc via ->get() per source session. Scales with historical floor activity × EPC count on Match ASN.

**Why it matters**

Busy warehouses accumulate completed scan-first sessions; Match ASN can spike memory/latency and stall the request/worker after F-0103/0106/0107 receiving hot-paths were already chunked.

**Evidence**

- `file` — app/Actions/Receiving/PropagateScanFirstConfirmsToAsnSession.php:32-47
  - scan-first sessions whereIn status → orderBy id → get()
- `file` — app/Actions/Receiving/CopyConfirmedReceivingScansToSession.php:52-59
  - all confirmed lines with(epc) → get()

**Affected resources**

- `app/Actions/Receiving/PropagateScanFirstConfirmsToAsnSession.php`
- `app/Actions/Receiving/CopyConfirmedReceivingScansToSession.php`
- `app/Actions/Receiving/PropagateScanFirstConfirmsToTransferReceiveSession.php`

**Recommendation**

Bound candidate sessions (recent window / still-open-first / overlapping EPCs); chunkById confirmed lines when copying; preserve parent-then-child order.

**Verification notes**

Prior receiving remediations still use chunkById; this Match ASN path does not. Transfer twin PropagateScanFirstConfirmsToTransferReceiveSession follows the same session get() pattern.

### [HIGH] Mutable Role and Fda3911Report Filament resources lack Eloquent policies `F-2026-0101`

**Rule:** `AUD-FIL-001` — Security
**Severity:** High
**Confidence:** High
**Status:** Fixed

**Summary**

RoleResource and Fda3911ReportResource are create/edit surfaces gated mainly by canAccess(), with no matching Laravel policies. User/ExceptionCase/EpcisDocument now have policies after remediation; these mutable peers do not. Filament non-strict authorization allows abilities when no policy method exists.

**Why it matters**

Role editing and FDA 3911 reporting are privileged; missing policies weaken Gate/Filament authorization consistency and make new actions easier to ship without authorize checks.

**Evidence**

- `file` — app/Filament/App/Resources/Roles/RoleResource.php:28-57
  - model Role; canAccess/canCreate; no RolePolicy
- `file` — app/Filament/App/Resources/Fda3911Reports/Fda3911ReportResource.php:27
  - Fda3911Report resource without Fda3911ReportPolicy
- `file` — app/Policies
  - UserPolicy/EpcisDocumentPolicy/ExceptionCasePolicy present; RolePolicy/Fda3911ReportPolicy absent

**Affected resources**

- `app/Filament/App/Resources/Roles/RoleResource.php`
- `app/Filament/App/Resources/Fda3911Reports/Fda3911ReportResource.php`
- `app/Policies`

**Recommendation**

Add RolePolicy and Fda3911ReportPolicy mirroring existing JobRoleAccess/canAccess gates; wire Filament can* / authorize() on mutate actions.

**Verification notes**

Compared App Filament resources to app/Policies after prior User/EpcisDocument/ExceptionCase remediation.

### [MEDIUM] ProvisionTenantScoutIndexes has neither $tries nor $timeout `F-2026-0105`

**Rule:** `AUD-QUE-001` — Laravel conventions
**Severity:** Medium
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

Queued Meilisearch index-settings sync shells out via Artisan with no $tries/$timeout.

**Why it matters**

Index sync can run long; worker kill/retry posture is unset and can leave tenants with partial Scout provisioning.

**Evidence**

- `file` — app/Jobs/Scout/ProvisionTenantScoutIndexes.php:16-40
  - ShouldQueue without $tries/$timeout; Artisan::call scout-sync

**Affected resources**

- `app/Jobs/Scout/ProvisionTenantScoutIndexes.php`

**Recommendation**

Add $tries and $timeout appropriate for Meilisearch sync duration; fail closed on non-zero Artisan exit (already throws).

**Verification notes**

Job source inspected.

### [MEDIUM] Scan-first TI gate loads all confirmed receiving lines unbounded `F-2026-0106`

**Rule:** `AUD-PER-012` — Performance
**Severity:** Medium
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

CompleteReceivingSession::assertScanFirstTiWhenRequired() pulls every confirmed ReceivingScanLine with epc via ->get() on the complete path.

**Why it matters**

Large scan-first sessions pay a full-line materialization cost on every complete attempt when TI is required.

**Evidence**

- `file` — app/Actions/Receiving/CompleteReceivingSession.php:239-253
  - ReceivingScanLine::…->with('epc')->get()

**Affected resources**

- `app/Actions/Receiving/CompleteReceivingSession.php`

**Recommendation**

Chunk/cursor TI checks or query only epc_ids needing TI verification without hydrating all lines.

**Verification notes**

Method source inspected.

### [MEDIUM] Accept-remaining confirms load all expected parents/children unbounded `F-2026-0107`

**Rule:** `AUD-PER-012` — Performance
**Severity:** Medium
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

ConfirmRemainingExpectedReceivingLines loads all expected parent and child ReceivingScanLine rows (with epc) via ->get() with no chunk/cursor.

**Why it matters**

Operator accept-remaining on multi-tote ASN sessions can materialize large expected sets in one request.

**Evidence**

- `file` — app/Actions/Receiving/ConfirmRemainingExpectedReceivingLines.php:73-100
  - expected parents/children ->get() paths

**Affected resources**

- `app/Actions/Receiving/ConfirmRemainingExpectedReceivingLines.php`

**Recommendation**

Process expected lines in chunkById batches inside the existing confirmation loop.

**Verification notes**

Action source sampled during re-pass hunt.

### [MEDIUM] Five ShouldQueue jobs declare neither $tries nor $timeout `F-2026-0203`

**Rule:** `AUD-QUE-001` — Laravel conventions
**Severity:** Medium
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

After remediating ExportTenantComplianceArchive / ProvisionTenantScoutIndexes / FanOutAnnouncementToTenant, several queued jobs still omit both $tries and $timeout: SeedSystemOutboundTemplates, SeedTenantRoles, RetireAnnouncementOnTenant, PromoteEpcisExceptionToCaseJob, EnsureTenantStorageDirectoriesJob.

**Why it matters**

Tenant provision and announcement retire paths can hang a worker indefinitely; undefined retry posture risks duplicate seed work or stuck exception promotion.

**Evidence**

- `file` — app/Jobs/SeedSystemOutboundTemplates.php:17-26
  - ShouldQueue; no tries/timeout
- `file` — app/Jobs/SeedTenantRoles.php:16-25
  - ShouldQueue; no tries/timeout
- `file` — app/Jobs/Announcements/RetireAnnouncementOnTenant.php:16-26
  - ShouldQueue; no tries/timeout
- `file` — app/Jobs/PromoteEpcisExceptionToCaseJob.php:21-30
  - ShouldQueue; no tries/timeout
- `file` — app/Jobs/EnsureTenantStorageDirectoriesJob.php:14-23
  - ShouldQueue; no tries/timeout
- `file` — app/Jobs/Announcements/FanOutAnnouncementToTenant.php:32-34
  - Contrast: remidiated peer has tries=3 timeout=300

**Affected resources**

- `app/Jobs/SeedSystemOutboundTemplates.php`
- `app/Jobs/SeedTenantRoles.php`
- `app/Jobs/Announcements/RetireAnnouncementOnTenant.php`
- `app/Jobs/PromoteEpcisExceptionToCaseJob.php`
- `app/Jobs/EnsureTenantStorageDirectoriesJob.php`

**Recommendation**

Add $tries and $timeout aligned under queue retry_after (3900); mirror FanOutAnnouncementToTenant for announcement retire; use tries=1 for idempotent seeders if duplicates are harmful.

**Verification notes**

Source inspected; ExportTenantComplianceArchive and ProvisionTenantScoutIndexes remain fixed with bounds.

### [MEDIUM] Operational jobs set $tries but omit $timeout `F-2026-0204`

**Rule:** `AUD-QUE-001` — Laravel conventions
**Severity:** Medium
**Confidence:** Confirmed
**Status:** Fixed

**Summary**

NotifyWmsReceiveConfirm ($tries=3), GenerateReceivingLpnLabelJob, ForwardCommissioningToL3, PrintSsccLabelJob, RunProductVerificationJob, SyncTenantAtpLicensesFromFda, and BackfillCatalogPartnerPlacesJob declare retries without a worker $timeout, so a hung HTTP/print call can occupy the worker until the connection layer fails.

**Why it matters**

WMS confirm, VRS, L3 forward, and label print are latency-bound external I/O; without timeout, retries alone do not bound worker occupancy.

**Evidence**

- `file` — app/Jobs/Receiving/NotifyWmsReceiveConfirm.php:35
  - public int $tries = 3; no $timeout
- `file` — app/Jobs/PrintSsccLabelJob.php:34
  - tries=3; no timeout
- `file` — app/Jobs/Vrs/RunProductVerificationJob.php:19
  - tries=3; no timeout
- `file` — app/Jobs/Labeling/ForwardCommissioningToL3.php:33
  - tries=3; no timeout
- `config` — config/queue.php
  - database/redis retry_after default 3900 — timeouts should stay below

**Affected resources**

- `app/Jobs/Receiving/NotifyWmsReceiveConfirm.php`
- `app/Jobs/GenerateReceivingLpnLabelJob.php`
- `app/Jobs/Labeling/ForwardCommissioningToL3.php`
- `app/Jobs/PrintSsccLabelJob.php`
- `app/Jobs/Vrs/RunProductVerificationJob.php`
- `app/Jobs/SyncTenantAtpLicensesFromFda.php`
- `app/Jobs/BackfillCatalogPartnerPlacesJob.php`

**Recommendation**

Add $timeout per job class consistent with HTTP client timeouts and under retry_after; keep existing $tries.

**Verification notes**

Confirmed property presence via source grep; ProcessEpcisDocumentJob / TransmitEpcisJob already set both.

### [MEDIUM] Additional App Filament models still lack policies (mostly read-only) `F-2026-0104`

**Rule:** `AUD-FIL-001` — Security
**Severity:** Medium
**Confidence:** High
**Status:** Fixed

**Summary**

EpcisJob, Verification, SerializationLot, SsccLabel, ActivityLog, and FdaProduct App resources have no matching policies. Most disable writes via canCreate/canEdit false, but Filament still falls through the no-policy allow path for any ability not overridden.

**Why it matters**

Defense-in-depth gap relative to resources that now have policies; new mutating actions can ship without Gate coverage.

**Evidence**

- `file` — app/Filament/App/Resources/EpcisJobs/EpcisJobResource.php
  - No EpcisJobPolicy
- `file` — app/Filament/App/Resources/Verifications/VerificationResource.php
  - No VerificationPolicy
- `file` — app/Policies
  - Policy set does not include these models

**Affected resources**

- `app/Filament/App/Resources/EpcisJobs`
- `app/Filament/App/Resources/Verifications`
- `app/Filament/App/Resources/SerializationLots`
- `app/Filament/App/Resources/SsccLabels`
- `app/Filament/App/Resources/ActivityLogs`
- `app/Filament/App/Resources/FdaProducts`

**Recommendation**

Add thin viewAny/view policies mirroring canAccess; keep create/update/delete false where resources are read-only.

**Verification notes**

Resource vs Policies directory comparison after prior remediation.

### [MEDIUM] Read-mostly Admin Filament resources lack Eloquent policies `F-2026-0202`

**Rule:** `AUD-FIL-001` — Security
**Severity:** Medium
**Confidence:** High
**Status:** Fixed

**Summary**

Admin DemoRequest, CustomerOnboarding, FdaImportRun, match-review, and WDD list resources disable writes via canCreate/canEdit false but still have no Eloquent policies, so Filament falls through the no-policy allow path for any ability not overridden.

**Why it matters**

Read paths still expose PII/ops data; missing policies make accidental enablement of create/edit unsafe by default.

**Evidence**

- `file` — app/Filament/Admin/Resources/DemoRequests/DemoRequestResource.php
  - List/View; no DemoRequestPolicy
- `file` — app/Filament/Admin/Resources/CustomerOnboardings/CustomerOnboardingResource.php
  - List/View; no policy
- `file` — app/Filament/Admin/Resources/Fda/FdaImportRuns/FdaImportRunResource.php
  - List/View; no policy

**Affected resources**

- `app/Filament/Admin/Resources/DemoRequests/DemoRequestResource.php`
- `app/Filament/Admin/Resources/CustomerOnboardings/CustomerOnboardingResource.php`
- `app/Filament/Admin/Resources/Fda/FdaImportRuns/FdaImportRunResource.php`
- `app/Filament/Admin/Resources/Fda/FdaOrganizationMatchReviews/FdaOrganizationMatchReviewResource.php`
- `app/Filament/Admin/Resources/Fda/FdaWddLicenses/FdaWddLicenseResource.php`
- `app/Filament/Admin/Resources/Fda/FdaWdd3plUnmatcheds/FdaWdd3plUnmatchedResource.php`
- `app/Filament/Admin/Resources/Fda/FdaWdd3plStagings/FdaWdd3plStagingResource.php`

**Recommendation**

Add thin viewAny/view policies for Admin read resources; keep create/update/delete false.

**Verification notes**

Same AUD-FIL-001 pattern as F-0104 for App panel; Admin still open.

### [MEDIUM] Domain EPCIS hard-gate hydrates full event/EPC graph via get() `F-2026-0206`

**Rule:** `AUD-PER-012` — Performance
**Severity:** Medium
**Confidence:** High
**Status:** Fixed

**Summary**

RunDomainEpcisHardGate::handle loads all active events, then all EventEpc (with epc) and EventQuantity rows for the document via whereIn(...)->get() before ValidationPipeline. Large inbound ASN documents materialize the full graph in memory on validate/commit.

**Why it matters**

Ingest/validate is on the critical path for large partner files; unbounded hydration risks memory spikes even when queue jobs have timeouts.

**Evidence**

- `file` — app/Actions/Epcis/RunDomainEpcisHardGate.php:31-50
  - activeEvents()->get(); EventEpc/EventQuantity whereIn get

**Affected resources**

- `app/Actions/Epcis/RunDomainEpcisHardGate.php`
- `app/Jobs/ValidateAndCommitEpcisDocumentJob.php`

**Recommendation**

Stream/chunk event graph into ValidationContext or validate in batches if pipeline contracts allow; otherwise document memory budget and fail closed earlier on event-count caps.

**Verification notes**

GenerateReceivingEpcisEvents also loads all confirmed EPCs under lockForUpdate — not filed separately because single ObjectEvent authorship requires a consistent locked set (architectural necessity, not a pure unbounded list scan).

### [MEDIUM] OutboundConnection mass-assigns is_system and system_key `F-2026-0108`

**Rule:** `AUD-SEC-002` — Security
**Severity:** Medium
**Confidence:** Medium
**Status:** Fixed

**Summary**

Privileged flags is_system and system_key remain on OutboundConnection $fillable. Conformance transitions are guarded in saving(), and deletes block system templates, but fill()/create() can still set is_system/system_key if those keys reach mass assignment.

**Why it matters**

System outbound templates are privileged integration configuration; mass-assignable flags are a defense-in-depth gap similar to the prior account-security fillable finding.

**Evidence**

- `file` — app/Models/OutboundConnection.php:32-45
  - fillable includes is_system, system_key

**Affected resources**

- `app/Models/OutboundConnection.php`

**Recommendation**

Remove is_system/system_key from fillable; set only via dedicated seeders/forceFill paths for system templates.

**Verification notes**

Model fillable inspected; no request-path exploit proven (confidence medium).

