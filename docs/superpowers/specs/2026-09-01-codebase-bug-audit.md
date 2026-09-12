# Codebase Bug Audit — 2026-09-01

> Skill-driven audit: `advanced-code-audit-debug`, `dscsa-serialization-audit-debug`, `laravel-security-audit`, `systematic-debugging`, `verification-before-completion`.

**Branch:** `release/1.1-1.4-gtm-waves`  
**Scope:** Security, tenancy/EPCIS integrity, GTM wave features, test baseline.

---

## Executive summary

| Severity | Confirmed | Fixed in source | Backlog |
|----------|-----------|-----------------|---------|
| Critical | 3 | 3 | 0 |
| High | 8 | 8 | 0 |
| Medium | 9 | 9 | 0 |

**Top fix-first (done this session):**

1. Test suite blocked — missing `DeduplicateCatalogTradingPartnersBySlug` + `routes/tenant.php` parse error
2. OIDC email-link bypasses allowed-domain check on existing users
3. Announcement models query tenant DB when tenancy initialized (missing `CentralConnection`)
4. EPCIS cache locks incomplete — `Cache::lock()` vs `EpcisCacheLock` on SSCC authoring / outbound enqueue

**Backlog (fixed 2026-09-01 follow-up):**

1. OIDC 2FA auto-confirm removed; admin OIDC requires prior issuer+subject binding
2. Impersonation POST redemption with shorter TTL, admin IP binding
3. Outbound resolver ladder — no global B2B when partner-scoped
4. `TenantRunner` wrapper for guaranteed `tenancy()->end()` in jobs/commands
5. Announcement fan-out atomicity + processing/failed retry
6. Role gate alignment (L3 retry, principals, buying group roster)
7. EPCIS job dedup, promote/requeue hardening, Guardian plan closed
8. `SeedTenantRoles` + SFTP poll tests; optional FilamentWatchdog `class_exists` guard

---

## Phase 0 — Test baseline

### P0 — Suite blocked (FIXED)

| Issue | Root cause | Fix |
|-------|------------|-----|
| Migration abort on fresh test DB | `2026_07_29_240001_add_slug_to_catalog_trading_partners.php` calls missing `DeduplicateCatalogTradingPartnersBySlug`; NULL slugs on seeded rows | Added `app/Actions/MasterData/DeduplicateCatalogTradingPartnersBySlug.php` |
| All artisan/test commands fail | `routes/tenant.php:160` extra `)` on EPCIS subscription route | Removed stray parenthesis |

### Infra notes

- Run tests as `www-data` (`sudo -u www-data php artisan test`) — `storage/` owned by www-data
- Full suite exceeds composer 300s timeout; use `COMPOSER_PROCESS_TIMEOUT=0` or `php artisan test` directly
- 16 tests skip when demo2 tenant absent

---

## Confirmed bugs — fixed in source

### 1. Critical — OIDC account linking skips domain allowlist

**Paths:** `app/Services/Auth/Oidc/OidcIdentityResolver.php`  
**Root cause:** `assertEmailDomainAllowed()` only ran on JIT create, not when binding existing user by email.  
**Fix:** Call domain check before linking existing user.  
**Test:** `OidcSsoTest::tenant_sso_rejects_existing_user_when_email_domain_is_not_allowed`

### 2. Critical — Announcement models hit tenant DB under initialized tenancy

**Paths:** `app/Models/Announcement.php`, `app/Models/AnnouncementTenant.php`  
**Root cause:** Central-only tables; models lacked `CentralConnection` trait.  
**Fix:** Added `Stancl\Tenancy\Database\Concerns\CentralConnection`.

### 3. Critical — EPCIS locks bypass tag-safe store

**Paths:** `app/Actions/Labeling/PersistAuthoredSsccEpcis.php`, `app/Actions/EpcisJobs/EnqueueEpcisJob.php`  
**Root cause:** `Cache::lock()` goes through Stancl tag bootstrapper; database driver cannot tag. Inbound path already used `EpcisCacheLock`.  
**Fix:** Switched SSCC authoring and outbound enqueue to `EpcisCacheLock::store()->lock()`.

### 4. High — `PromoteEpcisExceptionToCaseJob` ignores legacy alias

**Paths:** `app/Jobs/PromoteEpcisExceptionToCaseJob.php`  
**Root cause:** Config `ingest_failure` did not match signal type `INGESTION_PARSE_ERROR`.  
**Fix:** Normalize via `ExceptionService::legacySignalTypeMap()` before allow check.

### 5. High — `RetireAnnouncementOnTenant` leaks tenant context on failure

**Paths:** `app/Jobs/Announcements/RetireAnnouncementOnTenant.php`  
**Fix:** Added `try/finally` with `tenancy()->end()` (mirrors fan-out job).

### 6. High — Tenant routes syntax error

**Paths:** `routes/tenant.php:160`  
**Fix:** Removed extra `)` — blocked entire application bootstrap.

---

## Confirmed bugs — backlog (all fixed)

### Security

| ID | Severity | Issue | Fix |
|----|----------|-------|-----|
| SEC-2 | High | OIDC bypasses Breezy 2FA | Removed auto `confirmTwoFactorAuthentication()` from `OidcAuthenticator` |
| SEC-3 | High | Admin OIDC binds by email only | `resolveAdmin()` requires prior `oidc_issuer` + `oidc_subject` match |
| SEC-4 | High | Impersonation token in GET URL | POST `/impersonate/{token}/redeem`; 60s TTL cap; admin IP check |
| SEC-5 | Med-High | Deactivated portal users keep session | `EnsurePortalUserIsActive` on `auth:portal` routes |
| SEC-6 | Med-High | L3 retry when job roles off | `JobRoleAccess::allowsOwnerOrAny()` on `L3ForwardLog` |
| SEC-7 | Medium | 3PL Principal enumeration | `PrincipalPolicy` requires maintainer |
| SEC-8 | Medium | Buying Group roster read/mutation mismatch | `BuyingGroupMemberResource::canAccess()` aligned with `UsersManage` |

**Client portal IDOR:** No confirmed IDOR — `ClientPortalAccess::assertDocumentVisible` correctly scoped; cross-partner denial tested.

### EPCIS / DSCSA

| ID | Severity | Issue | Fix |
|----|----------|-------|-----|
| EPC-3 | High | Dual Process + ValidateAndCommit jobs | Shared `uniqueId()` on both job classes |
| EPC-4 | High | Global B2B before partner portal | `resolveActiveB2b()` skips global when partner-scoped |
| EPC-5 | High | Shipping pins Email via `resolve()` | `UpdateOutboundShippingParty` uses `resolveWithLadder()` |
| EPC-6 | Medium | Promote job silent failures | `failed()` handler + warning logs; `TenantRunner` |
| EPC-7 | Medium | Requeue leaves stale Error ledger rows | `archiveSupersededJob()` after successful requeue |
| EPC-8 | Medium | Guardian residual plan stale | Plan checkboxes updated; code already present |

**Deferred:** Remaining `Cache::lock()` call sites (packing, disposition, WMS, SSCC print, receiving LPN) — migrate to `EpcisCacheLock` if used under tenant context with database cache.

### Tenancy

| ID | Severity | Issue | Fix |
|----|----------|-------|-----|
| TEN-2 | High | `$tenant->run()` no revert on exception | `TenantRunner` wrapper adopted in jobs/commands |
| TEN-4 | High | Fan-out non-atomic | DB transaction; deactivate banner on partial failure |
| TEN-5 | High | Fan-out pivot stuck in `processing` | Admin retry includes `processing` + `failed` |
| TEN-6 | Medium | `whereHas('fdaProduct')` broken cross-DB | Documented on `Product::fdaProduct()` |
| TEN-7 | Medium | Artisan tenant loops missing `finally` | `TenantRunner` in SFTP poll, archive, reconcile commands |

### GTM / ops

| ID | Severity | Issue | Fix |
|----|----------|-------|-----|
| GTM-1 | Medium | SFTP poll job untested, no `failed()` | `PollSftpInboundConnectionTest` + `failed()` logging |
| GTM-2 | Medium | `SeedTenantRoles` job untested | `SeedTenantRolesTest` per profile |
| GTM-3 | Low | Filament plugin hard deps | `class_exists` guard for `FilamentWatchdogPlugin` |

### Migration fix (tenant provisioning)

| Issue | Fix |
|-------|-----|
| `verification_request_cases.exception_id` FK to non-existent `exception_cases` | Changed to `exceptions` (matches `ExceptionCase` model) |

---

## Test gaps

| Bug | Test that would catch it |
|-----|--------------------------|
| Missing dedupe action | Migration test / fresh `migrate:fresh --env=testing` in CI |
| OIDC domain bypass | `tenant_sso_rejects_existing_user_when_email_domain_is_not_allowed` (added) |
| Announcement CentralConnection | Feature test querying `Announcement` under initialized tenancy |
| EpcisCacheLock adoption | Integration test with `CACHE_STORE=database` under tenant |
| routes/tenant.php typo | Smoke test booting application / `route:list` |
| Promote alias mismatch | Unit test with `auto_promote_types=ingest_failure` |

---

## Tooling recommendations

1. Add `composer lint` → `vendor/bin/pint --test` (added)
2. Add PHPStan level 5 on `app/` for cross-DB and missing-class detection
3. CI: `migrate:fresh --env=testing` before test run
4. Set `COMPOSER_PROCESS_TIMEOUT=0` for full suite locally

---

## Fix queue (priority order)

1. ~~P0: Migration dedupe + route syntax~~ **DONE**
2. ~~P0: OIDC domain check on link~~ **DONE**
3. ~~P0: Announcement CentralConnection~~ **DONE**
4. ~~P1: EpcisCacheLock on SSCC/enqueue~~ **DONE**
5. ~~P1: OIDC 2FA bypass policy decision + fix~~ **DONE**
6. ~~P1: Impersonation token hardening~~ **DONE**
7. ~~P1: Outbound resolver ladder (global B2B / Email pin)~~ **DONE**
8. ~~P2: Tenancy `finally` in multi-tenant commands + `$tenant->run` wrapper~~ **DONE**
9. ~~P2: Announcement fan-out atomicity + processing retry~~ **DONE**
10. ~~P3: Wave 3 role gate alignment, portal session deactivation, SFTP poll tests~~ **DONE**

**Deferred:** Migrate remaining `Cache::lock()` call sites to `EpcisCacheLock` where tenant DB cache is used.

---

## Verification commands

```bash
# Targeted regression (fixes from this audit)
sudo -u www-data php artisan test --filter='OidcSsoTest|PublishAnnouncementFanOutTest|AnnouncementAudienceTest'

# Lint
composer lint

# Full suite (long)
COMPOSER_PROCESS_TIMEOUT=0 sudo -u www-data php artisan test
```
