# Tenant announcements (admin → selected orgs)

**Date:** 2026-09-01  
**Status:** Approved for planning  
**Panel:** Admin (`admin2`) publishes; App (tenant hosts) displays  

## Goal

Platform operators on admin2 compose announcements, target **selected tenants**, and publish so every **active user** in those orgs sees:

1. A **dismissible banner** (urgency), and  
2. A **database-notifications bell** entry (history).

## Decisions (locked)

| Topic | Choice |
|-------|--------|
| Audience | Tenant app users only (not platform admins as recipients) |
| Delivery | Banner **and** bell |
| Targeting | Multi-select tenants per announcement |
| Architecture | Central source of truth + per-tenant fan-out job |

## Non-goals (v1)

- Email / SMS / webhooks  
- Attachments  
- Per-role or per-site targeting inside a tenant  
- Editing body after publish and re-fanning (v1: drafts editable; published content treated as immutable — retire + create new to change copy)  
- Scheduling beyond optional `starts_at` / `ends_at`  
- Announcements aimed at platform admins

## Central schema

### `announcements`

| Column | Notes |
|--------|--------|
| `id` | UUID PK |
| `title` | string, required |
| `body` | text, required (plain or simple HTML from Filament RichEditor — store sanitized HTML) |
| `severity` | enum string: `info`, `warning`, `critical` |
| `status` | enum string: `draft`, `published`, `retired` |
| `starts_at` | nullable timestamp (banner eligible when null or ≤ now) |
| `ends_at` | nullable timestamp (banner ineligible when set and &lt; now) |
| `published_at` | nullable |
| `retired_at` | nullable |
| `created_by_admin_id` | FK admins, nullable on delete set null |
| timestamps | |

### `announcement_tenant`

| Column | Notes |
|--------|--------|
| `id` | bigIncrements |
| `announcement_id` | UUID FK cascade |
| `tenant_id` | string FK tenants cascade |
| `fan_out_status` | `pending`, `processing`, `succeeded`, `failed` |
| `fan_out_error` | nullable text |
| `fan_out_at` | nullable |
| unique | (`announcement_id`, `tenant_id`) |

Draft may save tenant selections before publish. Publish requires ≥1 tenant.

## Tenant schema

### `tenant_announcements`

Mirror for banner rendering without calling central from tenant requests:

| Column | Notes |
|--------|--------|
| `id` | bigIncrements |
| `announcement_id` | UUID (central id), unique |
| `title`, `body`, `severity` | copied at fan-out |
| `published_at` | |
| `starts_at`, `ends_at` | copied |
| `is_active` | bool; false on retire or expiry sweep |
| timestamps | |

### `tenant_announcement_dismissals`

| Column | Notes |
|--------|--------|
| `id` | bigIncrements |
| `tenant_announcement_id` | FK cascade |
| `user_id` | FK users cascade |
| `dismissed_at` | |
| unique | (`tenant_announcement_id`, `user_id`) |

Bell history uses existing tenant `notifications` table (Filament format). Dismissing the banner does **not** mark the bell notification read (and vice versa).

## Admin UI

- Filament resource **Announcements**, navigation group **Settings**, label **Announcements**, sort near Mail templates.
- List: title, severity, status, tenant count, published_at, fan-out summary (e.g. succeeded/failed).
- Form: title, body, severity, starts/ends, tenants multi-select.
- Actions:
  - **Save draft** — status stays/sets `draft`; no fan-out.
  - **Publish** — validate tenants; set `published`; enqueue fan-out; disable mutating title/body/tenants after success (or lock form fields).
  - **Retire** — status `retired`; enqueue deactivate for linked tenants.
- Permission: gate with existing admin panel permission pattern (same class of access as Mail templates / platform settings — Platform Admin and Super Admin unless a finer permission already exists; add `announcements.manage` only if the admin permission catalog is being extended in the same change).

## Publish / retire jobs

### `FanOutAnnouncementToTenant` (queued, one job per tenant row)

1. Load central announcement + pivot; skip if not `published`.  
2. `tenancy()->initialize($tenant)`.  
3. Upsert `tenant_announcements` by `announcement_id` (`is_active = true`).  
4. For each active `User`, send Filament database notification (title + body excerpt/link; severity → notification status color). Idempotency: skip users who already have a notification whose `data->announcement_id` matches (store custom key in notification data).  
5. Mark pivot `succeeded` or `failed` + error.  
6. `tenancy()->end()` (restore prior tenancy if any).

### `RetireAnnouncementOnTenant`

Set matching `tenant_announcements.is_active = false`. Do not delete notifications.

Failed fan-outs remain visible on the admin View page with **Retry** per tenant.

## Tenant app UX

- Render active, in-window, undismissed announcements as a banner in the app panel layout (or dashboard top). Order: `critical` first, then `warning`, then `info`; then `published_at` desc.
- Banner: title, short body, severity styling, **Dismiss** (writes dismissal row).
- Optional: link “View in notifications” is unnecessary if body is fully shown; keep banner body truncated with expand if long.
- Bell: one Filament DB notification per user at fan-out time.

## Authorization & tenancy safety

- Admin resource only on central admin panel / central connection.  
- Fan-out jobs must never leak queries across tenants; always initialize/end tenancy in `finally`.  
- Tenant users only see their tenant’s `tenant_announcements` / dismissals.

## Testing (acceptance)

1. Admin can create draft with selected tenants; no tenant rows / notifications yet.  
2. Publish creates `tenant_announcements` and one Filament notification per active user in each selected tenant DB.  
3. App user sees banner; dismiss hides for that user only; other users still see it.  
4. Bell still shows the notification after banner dismiss.  
5. Retire hides banners for those tenants; bell history remains.  
6. Retry works after a simulated fan-out failure.  
7. Admin nav shows **Announcements**.

## Deploy notes

- Central migrate on environments serving admin (including `/dpool/tracepharma` for `admin2.internal.vatengi.com`).  
- `tenants:migrate` for tenant announcement tables.  
- Queue worker must be running for fan-out.

## Open points (resolved in plan if needed)

- Exact Filament notification `data` shape for idempotency key.  
- Whether RichEditor HTML is sanitized via existing HTML purifier helper (prefer yes if one exists).
