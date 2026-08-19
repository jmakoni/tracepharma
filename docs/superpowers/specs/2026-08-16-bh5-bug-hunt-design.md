# BH5 Bug Hunt — Design Spec

> Approved via brainstorming (hybrid scope C). Implementation plan: `docs/superpowers/plans/2026-08-16-bh5-bug-hunt.md`.

**Goal:** Close high-severity authz and ops bugs left after BH1–BH4, starting with recent Admin dashboard / Registry / hub surfaces, then deferred pair/SFTP/EPCIS leftovers.

**Method:** Superpowers process (`using-superpowers` → brainstorming → writing-plans → subagent-driven or executing-plans). Per fix: `advanced-code-audit-debug` + `laravel-security-audit` (authz) / `dscsa-serialization-audit-debug` (EPCIS/custody) → `systematic-debugging` root cause → TDD (`laravel-tdd`) → minimal change.

**Non-goals:** New Spatie permission constants; full AS2 inbound; Meilisearch tenant isolation ops; hub per-route secrets; RedisTenancyBootstrapper enablement; floor UX polish beyond residual time (W2-6 last).

---

## Context

- BH1–BH4 shipped (integrations, SiteAccess/custody, jobs/pack, transmit integrity).
- Compliance TracingRequest SiteAccess, quarantine release authz, and Sanctum ability allowlist are **already closed** — out of BH5.
- Admin roles today: `PlatformAdmin` (all of `admins.manage`, `catalog.manage`, `tenants.manage`) and `Support` (none).

## Permission model (Wave 1)

Reuse existing permissions only:

| Surface | Support | PlatformAdmin |
|---------|---------|---------------|
| FDA registry list/view | Yes (authenticated Admin) | Yes |
| FDA registry edit / import curation actions | No | `catalog.manage` |
| Registry lean widgets (`registry_census`, `registry_exceptions`) + `registry_growth` analytics | Yes | Yes |
| Hub settings page + `hub_health` / `hub_coverage` | No | `catalog.manage` |
| Activity log + `activity_volume` | No | `admins.manage` |
| Tenant/onboarding widgets & CTAs | No | `tenants.manage` |
| `primary_ctas` | Filtered per link target `canAccess` / `canViewAny` | Full |
| App lean VRS counts on Today Activity | Hidden when site-restricted (`!SitesAccessAll`) | N/A (tenant User) |

---

## Wave 1 — Dashboard / Registry / Hub authz

| ID | Finding (confirmed) | Fix |
|----|---------------------|-----|
| W1-1 | `ViewOnlyFdaRegistryResource::canEdit` returns `true` | Require `CatalogManage` |
| W1-2 | FDA resources lack `canViewAny` | Any authenticated Admin may view; edits gated by W1-1 |
| W1-3 | `EpcisHubSettings` has no `canAccess` | Require `CatalogManage` |
| W1-4 | `ActivityLogResource` has no `canViewAny` | Require `AdminsManage` |
| W1-5 | Widget catalog grants hub/activity/registry/CTAs via `=> true` | Split: registry lean+growth stay open; hub_* → CatalogManage; activity_volume → AdminsManage; primary_ctas stays available but links null when unauthorized |
| W1-6 | `todayActivity()` VRS scorecard ignores SiteAccess; Verification has no `site_id` | Omit VRS counts for site-restricted users (fail-closed on visibility) |
| W1-7 | Tests encode Support seeing hub/registry edits | Update + add denial Livewire/feature tests |

## Wave 2 — Deferred high-severity leftovers

| ID | Finding | Fix |
|----|---------|-----|
| W2-1 | Stage provision failure after prod create leaves half-pair | Call `DeleteTenantPair` (or equivalent rollback) on stage failure before rethrow; keep resume path |
| W2-2 | `SftpOutboundSender` always throws | Prefer hard-fail at connection create/select + clear error; implement only if product requires SFTP now — default: reject `transport=sftp` in forms/validation and mark legacy connections inactive/unselectable |
| W2-3 | Enqueue/reprocess paths weak JobRoleAccess | Gate human reprocess/enqueue mutations with `NavIntegrations` and/or `NavExceptions` consistently; receive-only must not reprocess |
| W2-4 | Sending/Processing jobs past SLA have no auto recovery | Scheduler (or enqueue path) for aged Sending/Processing: redispatch or force-fail with audit log — mirror queued stale pattern |
| W2-5 | Rejected onboarding can retain `tenant_id` / slug claim | Ensure reject/release clears claim so `TenantPairAvailability` allows reuse |
| W2-6 | Floor UX residual M/L items | Optional last: menu trim / scan binding only if Waves 1–2 P0–P1 done |

**Order:** W1 complete → W2-1 → W2-2 → W2-3 → W2-4 → W2-5 → W2-6.

## Success criteria

- Support cannot open hub settings, edit FDA registry rows, or list activity logs (direct URL denied).
- Support can still open registry list/view and see registry lean widgets for triage.
- Site-restricted tenant users do not see tenant-wide VRS allowed/blocked on home Today Activity.
- Half-pair stage failure does not leave an unrecovered prod-only pair without automated rollback.
- Legacy SFTP outbound cannot silently fail at transmit without connection-level rejection.
- Receive-only users cannot reprocess EPCIS via enqueue/requeue paths.
- Feature/unit tests green for touched suites; no `RefreshDatabase` on central Admin tests (use `DatabaseTransactions`).

## Out of scope (explicit)

- New permissions (`registry.view`, `hub.manage`, …)
- Full SFTP protocol implementation (unless product later mandates it)
- AS2 inbound MVP, Meilisearch isolation, hub per-route secrets
- Re-opening closed Compliance TracingRequest / quarantine / Sanctum items
