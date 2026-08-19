# App Legal Acceptance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate owners and organization-settings users behind current Terms + Privacy acceptance, with a 14-day grace from first notice.

**Architecture:** A `LegalAcceptance` helper owns stale/grace/accept rules. Tenant `users` columns hold versions and the notice clock. Filament App `authMiddleware` `EnsureLegalAcceptance` starts the clock and redirects after grace. `AcceptLegalDocuments` records the clickwrap; a topbar banner shows during grace.

**Tech Stack:** Laravel 13, Filament 5 App panel, tenant migrations, Pest/PHPUnit feature tests on demo2, Spatie activity log.

## Global Constraints

- Gate only when `JobRoleAccess::canAccessOrganizationSettings($user)` is true.
- Floor/scanner roles are never redirected.
- `TenantImpersonation::isActive()` skips the gate.
- Marketing `customer_onboardings` acceptance is not copied onto the user.
- Grace: `tracepharma.legal_acceptance.grace_days` default 14, clock = `legal_notice_started_at` (first notice, not July 2026 effective date).
- Accept stamps both `TermsOfService::version()` and `PrivacyPolicy::version()`, nulls `legal_notice_started_at`, logs `legal_terms_accepted` with IP and user agent.
- Do not commit unless the user asks.

## File map

- Create: `database/migrations/tenant/2026_08_18_160000_add_legal_acceptance_to_users.php`
- Create: `app/Support/Legal/LegalAcceptance.php`
- Create: `app/Http/Middleware/EnsureLegalAcceptance.php`
- Create: `app/Filament/App/Pages/AcceptLegalDocuments.php`
- Create: `resources/views/filament/app/pages/accept-legal-documents.blade.php`
- Create: `resources/views/filament/app/hooks/legal-acceptance-banner.blade.php`
- Create: `tests/Unit/Support/Legal/LegalAcceptanceTest.php`
- Create: `tests/Feature/Auth/LegalAcceptanceGateTest.php`
- Modify: `app/Models/User.php` (fillable + datetime casts)
- Modify: `config/tracepharma.php` (`legal_acceptance.grace_days`)
- Modify: `app/Providers/Filament/AppPanelProvider.php` (middleware + banner hook)
- Modify: `docs/superpowers/specs/2026-08-18-app-legal-acceptance-design.md` (plan path)

---

### Task 1: LegalAcceptance helper + user columns

**Files:**
- Create: `app/Support/Legal/LegalAcceptance.php`
- Create: `database/migrations/tenant/2026_08_18_160000_add_legal_acceptance_to_users.php`
- Modify: `app/Models/User.php`
- Modify: `config/tracepharma.php`
- Test: `tests/Unit/Support/Legal/LegalAcceptanceTest.php`

**Interfaces:**
- Produces: `LegalAcceptance::isGated(?User $user = null): bool`
- Produces: `LegalAcceptance::hasAcceptedCurrent(?User $user = null): bool`
- Produces: `LegalAcceptance::isStale(?User $user = null): bool`
- Produces: `LegalAcceptance::ensureNoticeStarted(User $user): void`
- Produces: `LegalAcceptance::graceEndsAt(User $user): ?CarbonInterface`
- Produces: `LegalAcceptance::isHardBlocked(?User $user = null): bool`
- Produces: `LegalAcceptance::accept(User $user, ?string $ip = null, ?string $userAgent = null): void`

- [x] Unit tests: ungated floor user; gated stale starts notice once; hard block after grace_days; accept matches current versions and clears notice; impersonation not used here (middleware).
- [x] Tenant migration adds the five nullable columns if missing.
- [x] User fillable + casts for the timestamp columns.
- [x] Config `legal_acceptance.grace_days` => `(int) env('LEGAL_ACCEPTANCE_GRACE_DAYS', 14)`.

---

### Task 2: Middleware, accept page, banner

**Files:**
- Create: `app/Http/Middleware/EnsureLegalAcceptance.php`
- Create: `app/Filament/App/Pages/AcceptLegalDocuments.php` (`shouldRegisterNavigation = false`, `canAccess` = gated)
- Create: views listed above
- Modify: `AppPanelProvider` — `authMiddleware` after `Authenticate`; `PanelsRenderHook::TOPBAR_AFTER` banner when stale and not hard-blocked

**Interfaces:**
- Consumes: `LegalAcceptance::*` from Task 1
- Skip: guests, `!isGated`, `TenantImpersonation::isActive()`, accept page, logout, `livewire/*`, `filament/*` assets

- [x] Feature tests on demo2: technician never redirected; owner first Dashboard visit sets `legal_notice_started_at` and still `assertOk`; travel 15 days then Dashboard redirects to accept; accept both boxes unblocks; impersonation session is not redirected; accept does not touch `customer_onboardings`.

---

### Task 3: Spec pointer

- [x] Set the spec header implementation plan path to this file.
