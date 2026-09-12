# Floor Roles + Support Engineer

> **For agentic workers:** Implement after plan approval.

**Goal:** (1) Expose floor receive/ship/inbound-exception job roles on Dental / Medical and Drug Wholesaler. (2) Add a **Support Engineer** role on **every** tenant profile, restricted to `@tracepharma.io` emails, with hardening so the domain rule cannot be bypassed by an admin-set password.

**Architecture:** Extend `TenantRole` + `TenantRoleSeeder`. Floor roles reuse existing enum cases. Support Engineer is a new enum case with full `Permissions::tenantAppPermissions()` but **not** Owner. Create/Edit enforce domain + assignment rules below.

**Tech Stack:** Laravel enums, Spatie roles via `TenantRoleSeeder`, Filament Users form (`optionsForProfile`).

---

## Plan audit (skills + industry practice)

Skills applied: `laravel-security-audit`, `receiving-code-review` (verify before agreeing), `tenant-type-links` (profile-scoped roles stay on `TenantRole::forProfile` / `TenantFeatures` unchanged).

### What the plan already gets right

| Item | Why |
|------|-----|
| Separate Support Engineer ≠ Owner | Matches industry “preserve operator identity” — support acts as themselves, not as customer Owner (`JobRoleAccess::isOwner()` stays false). |
| Domain allowlist `@tracepharma.io` | Common SaaS pattern: restrict privileged local identities to a corporate email domain (Azure multi-tenant identity guidance: allow only specific domains for local signup). |
| Floor roles reuse existing enums | Aligns with `tenant-type-links`: widen profile→persona map; don’t invent parallel permission names. |
| Seeder already maps receive/ship/exceptions | Least change; permissions already correct for reused roles. |

### Industry patterns (how other apps do support access)

1. **Own-identity support seat in the tenant** (this plan) — support logs in as `…@vendor.com` inside the customer tenant with elevated RBAC. Used when product has no central “login as tenant” UX. Domain restriction is a **membership signal**, not proof of employment by itself.
2. **Central impersonation / staff portal** (Shopify, Stripe-style, many B2B SaaS) — staff never become tenant users; they open a time-boxed support session with audit (“acting as support for tenant X”). TracePharma already has admin impersonation tokens conceptually; this plan does **not** replace that.
3. **Permission shadowing / ticket-bound elevation** — baseline support role is narrow; elevation is time-bound and audited (NIST-oriented guidance). Stronger than standing “Owner-equivalent” permissions.
4. **Break-glass** — rare emergency accounts, MFA, monitored; **not** daily support (Atlassian / CSA). Do not conflate Support Engineer with break-glass.

### Critical finding (must harden)

**Email domain check alone is insufficient** under the current Users create flow (admin sets password in-form).

Attack: tenant Owner creates `anything@tracepharma.io`, chooses the password, assigns Support Engineer → full app permissions **without** controlling that mailbox.

Industry mitigation when vendor email is required: **activation only via that inbox** (invite / reset link), and/or **only vendor staff can mint the role**, and/or **no standing Owner-equivalent**.

### Locked hardenings (from audit)

1. **Label / key:** `Support Engineer` / `support_engineer` (no “TracePharma” in label).
2. **Domain:** exact `@tracepharma.io` (case-insensitive); reject subdomains.
3. **Who may assign Support Engineer:** only actors who pass `JobRoleAccess::isOwner()` (same gate pattern as granting Owner) — non-owners with `users.manage` cannot mint support seats.
4. **Create with Support Engineer:** do **not** persist creator-chosen password. Generate a random password (or hashed random), create the user, then send existing `TenantUserAccountCreated` / password-reset style mail so only the mailbox holder can sign in. Prefer reusing account-created notify + a forced password reset token if already available; minimal path: random password + `NotifyTenantUserAccountCreated` and document that support must use “forgot password” **or** add a one-time reset notification in the same change if reset exists.
5. **Edit:** cannot add Support Engineer unless email is `@tracepharma.io`; cannot change email off-domain while role is present; only Owners may add/remove Support Engineer.
6. **Permissions:** keep full `tenantAppPermissions()` for v1 (operational parity with Owner for troubleshooting) but document as standing privilege; future follow-up: narrow nav or time-bound elevation. Do not grant `isOwner()` semantics.

### Residual risks (accepted / follow-up)

- Standing Owner-equivalent RBAC is broader than “permission shadowing” best practice — accepted for v1; log in audit notes for later.
- Central Admin impersonation remains the better long-term support path for DSCSA audit clarity — out of scope.
- Spoofed From: on mail doesn’t matter; the risk was local password set, which hardenings address.

---

## Decisions (locked)

- Floor roles on **DentalMedicalSupply** and **DrugWholesaler**: `ReceivingTechnician`, `OutboundPickAndPackLead`, `InboundExceptionCoordinator`
- Support Engineer on **all** profiles including BuyingGroup
- Hardenings 1–6 above

## Resulting Dental / Medical & Wholesaler roles (non-Owner)

| Role | Permissions |
|------|-------------|
| Support Engineer | all tenant app permissions (`@tracepharma.io` + Owner-only assign + no creator password) |
| Receiving Technician | `nav.receive` |
| Outbound Pick-and-Pack Lead | `nav.ship` |
| Inbound Exception Coordinator | `nav.exceptions`, `nav.receive` |
| ATP Verification Manager | verify, master data, compliance |
| VRS Analyst | verify |
| Corporate Compliance Auditor | compliance, exceptions |
| Bulk Exceptions Manager | exceptions, receive |

Plus **Owner**.

## Implementation

### 1. Enum + profile map

[`app/Enums/TenantRole.php`](app/Enums/TenantRole.php): add `SupportEngineer`; include in every `forProfile()` arm; widen DrugWholesaler/DentalMedicalSupply with floor trio + Support Engineer.

### 2. Seeder

[`app/Support/Auth/TenantRoleSeeder.php`](app/Support/Auth/TenantRoleSeeder.php): `SupportEngineer => Permissions::tenantAppPermissions()`.

### 3. Auth helpers + Filament

- Helper: domain check + “selected roles include support engineer”
- Concern (mirror `RestrictsOwnerRoleAssignment`): `assertSupportEngineerAssignmentAllowed`
  - Owner-only assign
  - `@tracepharma.io` required
- [`CreateUser`](app/Filament/App/Resources/Users/Pages/CreateUser.php) / [`EditUser`](app/Filament/App/Resources/Users/Pages/EditUser.php): call asserts; on create with Support Engineer, strip/replace password with random before save and notify mailbox
- Clear ValidationException / Filament notification copy

### 4. Tests

- Profile maps (floor + support on all profiles)
- Seeder permission set
- Non-Owner cannot assign Support Engineer
- Non-domain email + Support Engineer fails create/edit
- Create Support Engineer: succeeds for Owner + `@tracepharma.io`; stored password ≠ submitted password (or login only after reset path)
- Off-domain email change while holding role fails

### 5. Ops

- Deploy; `tracepharma:seed-tenant-job-roles` (or Access toggle) on LaSmile and others
- Assign floor roles / Support Engineer as needed

## Non-goals

- Subdomain emails
- `isOwner()` for Support Engineer
- Central impersonation redesign
- Time-bound elevation / ticket binding (follow-up)
- Auto-assign existing users

## Success criteria

- Users form shows **Support Engineer** + dental/wholesaler floor roles
- Domain + Owner-only assign + no creator-chosen password for Support Engineer
- Focused tests green
