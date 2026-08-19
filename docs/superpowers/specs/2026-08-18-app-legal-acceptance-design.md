# App legal acceptance — Design Spec

> Approved via brainstorming (2026-08-18). Implementation plan: `docs/superpowers/plans/2026-08-18-app-legal-acceptance.md`.

**Goal:** Owners and organization-settings users must accept the current Terms of Service and Privacy Policy before they can keep using the tenant App. Floor/scanner roles are not gated. A 14-day grace runs from first notice so existing tenants are not blocked on deploy.

**Non-goals:** Admin-panel acceptance, marketing get-started changes, auto-stamping the owner from a CustomerOnboarding row, Sanctum/API gating, per-document clickwrap beyond the two current versions.

---

## Who is gated

A signed-in App user is gated when `JobRoleAccess::canAccessOrganizationSettings($user)` is true (Owner, `users.manage`, or master-data nav when job roles are on).

Receiving technicians and other floor roles skip the wall even if they have never accepted.

Platform admin impersonation (`TenantImpersonation` session) skips the gate so support does not accept on the customer’s behalf.

---

## What “accepted” means

Current versions come from `TermsOfService::version()` and `PrivacyPolicy::version()`.

The user has accepted when **both** stored versions match the current pair. A bump to either document makes them stale.

Marketing get-started acceptance stays on `customer_onboardings` only. It does **not** copy onto the tenant user. The person who signs in still accepts in-app.

---

## Data (tenant `users`)

Nullable columns:

| Column | Type |
|---|---|
| `terms_accepted_at` | timestamp |
| `terms_version` | string |
| `privacy_accepted_at` | timestamp |
| `privacy_version` | string |
| `legal_notice_started_at` | timestamp |

Also store on accept (not separate columns unless already easy): IP and user agent via activity log (`legal_terms_accepted`) so the users table stays small.

`legal_notice_started_at` is the grace clock. It is set on the first gated request after the user becomes stale (write-on-read, once). Accepting current versions nulls it.

---

## Grace and hard block

`tracepharma.legal_acceptance.grace_days` defaults to **14**.

- **Current:** no banner, no redirect.
- **Stale, inside grace:** App works. Persistent App-panel banner: accept by `{notice + 14 days}` with a link to the accept page.
- **Stale, grace elapsed:** only the accept page, logout, and outbound legal-doc links. All other App panel URLs redirect to accept.

Clock starts at first notice, not at the documents’ July 2026 effective date.

---

## Accept page

New App panel page (e.g. `AcceptLegalDocuments`), no nav item.

- Reuse `x-legal.legal-summary` for version/effective dates and links to marketing `/tos` and `/privacy`.
- Two required checkboxes (Terms, Privacy) and one Accept button.
- On submit: stamp both versions + timestamps, clear `legal_notice_started_at`, activity log, redirect to the intended URL or Dashboard.

Logout remains available. Login and 2FA are not gated (they run before the App middleware).

---

## Wiring

Filament App `authMiddleware` after `Authenticate`: `EnsureLegalAcceptance`.

The middleware:

1. Skip guests, non-gated roles, impersonation, the accept page, logout.
2. If stale and `legal_notice_started_at` is null, persist `now()`.
3. If grace remains, continue (banner is a render hook).
4. If grace elapsed, redirect to the accept page.

---

## Tests

- Floor user never redirected.
- Gated user with null acceptance: first hit sets `legal_notice_started_at`; still reaches Dashboard during grace; banner present.
- After grace: Dashboard redirects to accept; accept with both boxes stamps versions and unblocks.
- Version bump (terms or privacy) makes a previously current user stale and starts a new clock when notice was cleared.
- Impersonation is not redirected.
- Accept does not write the password or a marketing onboarding row.

---

## Success criteria

- Admin-created owners cannot use org-settings surfaces after 14 days of notice without accepting.
- Floor work is uninterrupted.
- Each accept records current versions and a timestamp; version bumps require a new accept after a new grace.
- Marketing application acceptance is unchanged and is not treated as in-app acceptance.
