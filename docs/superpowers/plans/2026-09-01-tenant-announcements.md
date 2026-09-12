# Tenant Announcements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let platform admins on admin2 compose announcements, target selected tenants, and publish so each active tenant user gets a dismissible app banner plus a Filament database-notifications bell entry.

**Architecture:** Central `announcements` + `announcement_tenant` are the source of truth. On publish, queued jobs call `Tenant::run()` to upsert `tenant_announcements`, send idempotent Filament DB notifications into the tenant `notifications` table, and record fan-out status. App panel renders undismissed active banners via a TOPBAR render hook; dismissals are per-user in the tenant DB.

**Tech Stack:** Laravel 13, Stancl tenancy, Filament 5 admin/app panels, queued jobs, Pest/PHPUnit feature tests, existing `App\Filament\Notifications\Notification`.

**Spec:** `docs/superpowers/specs/2026-09-01-tenant-announcements-design.md`

## Global Constraints

- Recipients are **tenant app users only** (not platform admins).
- Delivery is **banner and bell**.
- Targeting is **multi-select tenants** (required to publish).
- Published title/body/tenants are **immutable**; change copy via retire + new announcement.
- Dismissing the banner does **not** mark the bell notification read (and vice versa).
- Fan-out must use `Tenant::run()` / tenancy initialize+end with `finally` — never leak cross-tenant queries.
- Admin resource access matches Mail templates: `Permissions::AdminsManage`.
- Notification idempotency key: `data.announcement_id` = central announcement UUID string.
- Do not invent email/SMS, attachments, or per-role targeting in v1.

## File map

| Path | Responsibility |
|------|----------------|
| `database/migrations/2026_09_01_120000_create_announcements_tables.php` | Central `announcements` + `announcement_tenant` |
| `database/migrations/tenant/2026_09_01_120100_create_tenant_announcements_tables.php` | Tenant banner + dismissals |
| `app/Enums/AnnouncementSeverity.php` | `info`, `warning`, `critical` |
| `app/Enums/AnnouncementStatus.php` | `draft`, `published`, `retired` |
| `app/Enums/AnnouncementFanOutStatus.php` | `pending`, `processing`, `succeeded`, `failed` |
| `app/Models/Announcement.php` | Central model + tenants relation |
| `app/Models/AnnouncementTenant.php` | Pivot model with fan-out columns |
| `app/Models/TenantAnnouncement.php` | Tenant mirror for banners |
| `app/Models/TenantAnnouncementDismissal.php` | Per-user dismissals |
| `app/Actions/Announcements/PublishAnnouncement.php` | Status transition + dispatch jobs |
| `app/Actions/Announcements/RetireAnnouncement.php` | Retire + dispatch deactivate jobs |
| `app/Jobs/Announcements/FanOutAnnouncementToTenant.php` | Per-tenant upsert + notify users |
| `app/Jobs/Announcements/RetireAnnouncementOnTenant.php` | Deactivate tenant banner row |
| `app/Filament/Admin/Resources/Announcements/*` | Admin CRUD UI |
| `app/Support/Announcements/ActiveTenantAnnouncements.php` | Query active undismissed for current user |
| `app/Livewire/App/TenantAnnouncementBanner.php` | Banner Livewire + dismiss action |
| `resources/views/filament/app/hooks/tenant-announcement-banner.blade.php` | Hook entry / Livewire mount |
| `resources/views/livewire/app/tenant-announcement-banner.blade.php` | Banner markup |
| `app/Providers/Filament/AppPanelProvider.php` | Register TOPBAR hook |
| `tests/Feature/Announcements/*` | Feature coverage |

---

### Task 1: Central migrations + enums + models

**Files:**
- Create: `database/migrations/2026_09_01_120000_create_announcements_tables.php`
- Create: `app/Enums/AnnouncementSeverity.php`
- Create: `app/Enums/AnnouncementStatus.php`
- Create: `app/Enums/AnnouncementFanOutStatus.php`
- Create: `app/Models/Announcement.php`
- Create: `app/Models/AnnouncementTenant.php`
- Test: `tests/Feature/Announcements/AnnouncementSchemaTest.php`

**Interfaces:**
- Produces: `Announcement` with `tenants(): BelongsToMany` using `announcement_tenant` and pivot model `AnnouncementTenant`; casts for severity/status enums; fillable fields per spec.

- [ ] **Step 1: Write the failing schema test**

```php
<?php

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnnouncementSchemaTest extends TestCase
{
    #[Test]
    public function central_announcements_tables_exist_and_model_persists(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('announcements'));
        $this->assertTrue(Schema::hasTable('announcement_tenant'));

        $admin = Admin::factory()->create();
        $tenant = Tenant::query()->firstOrFail();

        $announcement = Announcement::query()->create([
            'title' => 'Maintenance window',
            'body' => '<p>Saturday 02:00 UTC</p>',
            'severity' => AnnouncementSeverity::Warning,
            'status' => AnnouncementStatus::Draft,
            'created_by_admin_id' => $admin->id,
        ]);

        $announcement->tenants()->sync([
            $tenant->getTenantKey() => ['fan_out_status' => 'pending'],
        ]);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'status' => 'draft',
            'severity' => 'warning',
        ]);
        $this->assertDatabaseHas('announcement_tenant', [
            'announcement_id' => $announcement->id,
            'tenant_id' => $tenant->getTenantKey(),
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AnnouncementSchemaTest`

Expected: FAIL (missing migration/model or table)

- [ ] **Step 3: Add enums**

```php
// app/Enums/AnnouncementSeverity.php
enum AnnouncementSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}

// app/Enums/AnnouncementStatus.php
enum AnnouncementStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}

// app/Enums/AnnouncementFanOutStatus.php
enum AnnouncementFanOutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Add central migration**

Create `announcements` (UUID PK via `$table->uuid('id')->primary()`, title, body text, severity string, status string default `draft`, nullable starts_at/ends_at/published_at/retired_at, nullable `created_by_admin_id` FK `admins` nullOnDelete, timestamps) and `announcement_tenant` (id, announcement_id FK cascade, tenant_id string FK `tenants` cascade, fan_out_status string default `pending`, nullable fan_out_error text, nullable fan_out_at, unique announcement_id+tenant_id, timestamps).

- [ ] **Step 5: Add models**

`Announcement`: `$incrementing = false`, `$keyType = 'string'`, boot creating UUID, casts enums + datetimes, `tenants()` belongsToMany `Tenant::class`, table `announcement_tenant`, withPivot fan-out columns, using `AnnouncementTenant`.  
`AnnouncementTenant`: table `announcement_tenant`, casts `fan_out_status` enum.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AnnouncementSchemaTest`

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_01_120000_create_announcements_tables.php \
  app/Enums/AnnouncementSeverity.php app/Enums/AnnouncementStatus.php \
  app/Enums/AnnouncementFanOutStatus.php app/Models/Announcement.php \
  app/Models/AnnouncementTenant.php tests/Feature/Announcements/AnnouncementSchemaTest.php
git commit -m "feat: add central announcements schema"
```

---

### Task 2: Tenant migrations + models

**Files:**
- Create: `database/migrations/tenant/2026_09_01_120100_create_tenant_announcements_tables.php`
- Create: `app/Models/TenantAnnouncement.php`
- Create: `app/Models/TenantAnnouncementDismissal.php`
- Test: `tests/Feature/Announcements/TenantAnnouncementSchemaTest.php`

**Interfaces:**
- Consumes: central announcement UUID string as `TenantAnnouncement::$announcement_id`
- Produces: `TenantAnnouncement::dismissals()` HasMany; `TenantAnnouncementDismissal` unique per user

- [ ] **Step 1: Write the failing tenant schema test**

Use the existing demo2 tenant pattern from `tests/Feature/Filament/FilamentDatabaseNotificationsTest.php` (`DEMO2_TENANT_ID`, `tenants:migrate`, `tenancy()->initialize`). Assert `tenant_announcements` and `tenant_announcement_dismissals` exist; create a `TenantAnnouncement` + dismissal for a factory user.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TenantAnnouncementSchemaTest`

Expected: FAIL (missing tenant tables)

- [ ] **Step 3: Add tenant migration + models**

`tenant_announcements`: id, uuid `announcement_id` unique, title, body, severity, published_at, nullable starts_at/ends_at, `is_active` bool default true, timestamps.  
`tenant_announcement_dismissals`: id, tenant_announcement_id FK cascade, user_id FK cascade, dismissed_at, unique (tenant_announcement_id, user_id), timestamps.

Models live in `app/Models` (tenant connection via tenancy). `TenantAnnouncement` casts severity enum + bool/datetimes; `dismissals()` HasMany.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TenantAnnouncementSchemaTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/tenant/2026_09_01_120100_create_tenant_announcements_tables.php \
  app/Models/TenantAnnouncement.php app/Models/TenantAnnouncementDismissal.php \
  tests/Feature/Announcements/TenantAnnouncementSchemaTest.php
git commit -m "feat: add tenant announcement banner tables"
```

---

### Task 3: Fan-out and retire jobs

**Files:**
- Create: `app/Jobs/Announcements/FanOutAnnouncementToTenant.php`
- Create: `app/Jobs/Announcements/RetireAnnouncementOnTenant.php`
- Create: `app/Actions/Announcements/PublishAnnouncement.php`
- Create: `app/Actions/Announcements/RetireAnnouncement.php`
- Test: `tests/Feature/Announcements/PublishAnnouncementFanOutTest.php`

**Interfaces:**
- Consumes: `Announcement`, `Tenant` id, pivot rows
- Produces:
  - `PublishAnnouncement::handle(Announcement $announcement): void` — requires ≥1 tenant; sets status published + published_at; sets pivot pending; dispatches `FanOutAnnouncementToTenant` per pivot
  - `RetireAnnouncement::handle(Announcement $announcement): void` — sets retired; dispatches `RetireAnnouncementOnTenant` per pivot
  - `FanOutAnnouncementToTenant` constructor `(string $announcementId, string $tenantId)`
  - Notification data MUST include `'announcement_id' => $announcementId` and `'format' => 'filament'`

- [ ] **Step 1: Write the failing publish fan-out test**

```php
#[Test]
public function publish_fans_out_banner_row_and_bell_notification_to_active_users(): void
{
    Bus::fake(); // first assert Publish dispatches jobs — OR use Queue::fake then Bus::dispatchSync in a second test

    // Preferred: SyncQueue / $this->app['config']->set('queue.default', 'sync');
    config(['queue.default' => 'sync']);

    $tenant = $this->initializeDemo2Tenant();
    $admin = Admin::factory()->create();
    tenancy()->end();

    $user = null;
    $tenant->run(function () use (&$user): void {
        $user = User::factory()->create(['is_active' => true]); // or whatever active flag exists; if none, all users count
    });

    $announcement = Announcement::query()->create([/* draft fields */, 'created_by_admin_id' => $admin->id]);
    $announcement->tenants()->sync([$tenant->getTenantKey() => ['fan_out_status' => 'pending']]);

    app(PublishAnnouncement::class)->handle($announcement->fresh());

    $this->assertSame(AnnouncementStatus::Published, $announcement->fresh()->status);

    $tenant->run(function () use ($announcement, $user): void {
        $this->assertDatabaseHas('tenant_announcements', [
            'announcement_id' => $announcement->id,
            'is_active' => true,
            'title' => $announcement->title,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
        ]);
        $row = DB::table('notifications')->where('notifiable_id', $user->getKey())->first();
        $data = json_decode($row->data, true);
        $this->assertSame($announcement->id, $data['announcement_id'] ?? null);
    });

    $this->assertDatabaseHas('announcement_tenant', [
        'announcement_id' => $announcement->id,
        'tenant_id' => $tenant->getTenantKey(),
        'fan_out_status' => 'succeeded',
    ]);
}
```

Adjust `User` active criteria to match the real `users` schema (if there is no `is_active`, notify all users).

Also add:
- `publish_is_idempotent_for_bell_notifications` — run fan-out job twice; still one notification per user.
- `retire_deactivates_tenant_banner` — after publish, retire; `is_active` false; notifications remain.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PublishAnnouncementFanOutTest`

Expected: FAIL

- [ ] **Step 3: Implement PublishAnnouncement**

```php
public function handle(Announcement $announcement): void
{
    if ($announcement->tenants()->count() < 1) {
        throw new InvalidArgumentException('Select at least one tenant before publishing.');
    }
    if ($announcement->status === AnnouncementStatus::Published) {
        return;
    }

    $announcement->forceFill([
        'status' => AnnouncementStatus::Published,
        'published_at' => now(),
    ])->save();

    foreach ($announcement->tenants()->get() as $tenant) {
        $announcement->tenants()->updateExistingPivot($tenant->getTenantKey(), [
            'fan_out_status' => AnnouncementFanOutStatus::Pending->value,
            'fan_out_error' => null,
        ]);
        FanOutAnnouncementToTenant::dispatch($announcement->id, (string) $tenant->getTenantKey());
    }
}
```

- [ ] **Step 4: Implement FanOutAnnouncementToTenant**

Use `Tenant::query()->findOrFail($tenantId)->run(function () { ... })`. Inside:
1. Mark pivot processing (on central connection — use `tenancy()->central()` or query AnnouncementTenant without tenant connection).
2. Upsert `TenantAnnouncement` by `announcement_id`.
3. For each User: if a notification already exists with `data->announcement_id` matching, skip; else:

```php
Notification::make()
    ->title($announcement->title)
    ->body(str($announcement->body)->stripTags()->limit(200))
    ->status(match ($announcement->severity) {
        AnnouncementSeverity::Critical => 'danger',
        AnnouncementSeverity::Warning => 'warning',
        default => 'info',
    })
    ->sendToDatabase($user);
```

Filament’s `sendToDatabase` may not accept custom `announcement_id` in data by default — after send, update the latest notification row’s `data` JSON to merge `announcement_id`, **or** use `DatabaseNotification` creation manually following Filament’s format (`format` => `filament`, plus title/body/status). Prefer reading how Filament stores DB notifications in an existing test and match that shape while adding `announcement_id`.

4. Mark pivot succeeded; on exception mark failed + message, rethrow or swallow per job policy (prefer catch, mark failed, don't rethrow endlessly — `$this->fail($e)` after marking).

Important: pivot updates must run on the **central** connection. Pattern:

```php
$tenant = Tenant::query()->findOrFail($this->tenantId);
$announcement = Announcement::query()->findOrFail($this->announcementId);

AnnouncementTenant::query()
    ->where('announcement_id', $announcement->id)
    ->where('tenant_id', $tenant->getTenantKey())
    ->update(['fan_out_status' => AnnouncementFanOutStatus::Processing->value]);

try {
    $tenant->run(function () use ($announcement): void {
        // upsert + notify
    });
    // succeeded update on central
} catch (Throwable $e) {
    // failed update on central
    throw $e;
}
```

- [ ] **Step 5: Implement RetireAnnouncement + RetireAnnouncementOnTenant**

Retire sets status/retired_at; dispatches deactivate job; job sets `is_active = false` for matching `announcement_id`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=PublishAnnouncementFanOutTest`

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/Announcements app/Actions/Announcements \
  tests/Feature/Announcements/PublishAnnouncementFanOutTest.php
git commit -m "feat: fan out tenant announcements to banner and bell"
```

---

### Task 4: Admin Filament Announcement resource

**Files:**
- Create: `app/Filament/Admin/Resources/Announcements/AnnouncementResource.php`
- Create: `app/Filament/Admin/Resources/Announcements/Pages/ListAnnouncements.php`
- Create: `app/Filament/Admin/Resources/Announcements/Pages/CreateAnnouncement.php`
- Create: `app/Filament/Admin/Resources/Announcements/Pages/EditAnnouncement.php`
- Create: `app/Filament/Admin/Resources/Announcements/Pages/ViewAnnouncement.php`
- Create: `app/Filament/Admin/Resources/Announcements/Schemas/AnnouncementForm.php`
- Create: `app/Filament/Admin/Resources/Announcements/Tables/AnnouncementsTable.php`
- Test: `tests/Feature/Announcements/AdminAnnouncementResourceTest.php`

**Interfaces:**
- Consumes: `PublishAnnouncement`, `RetireAnnouncement`
- Produces: Nav item label **Announcements**, group **Settings**, sort `12` (Mail templates is `11`), slug `announcements`
- `canViewAny` / mutate: `auth('admin')->user()?->can(Permissions::AdminsManage)`

- [ ] **Step 1: Write the failing admin UI test**

```php
#[Test]
public function platform_admin_can_open_announcements_index(): void
{
    $admin = Admin::factory()->create();
    // assign AdminsManage the same way other admin resource tests do
    $this->actingAs($admin, 'admin');

    $this->get('https://'.config('tracepharma.admin_domain').'/announcements')
        ->assertOk()
        ->assertSee('Announcements');
}
```

Mirror auth/permission setup from `tests/Feature/Admin/AdminRoleResourceTest.php` or Mail template tests if present.

Also test publish action requires tenants (validation error) and successful publish changes status.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminAnnouncementResourceTest`

Expected: FAIL (404)

- [ ] **Step 3: Implement resource following MailTemplateResource layout**

Form fields:
- `title` TextInput required
- `body` RichEditor required
- `severity` Select enum
- `starts_at` / `ends_at` DateTimePicker nullable
- `tenants` Select multiple relationship `tenants` preload search by name — disabled when status is Published or Retired

Header actions on Edit/View:
- Publish → `PublishAnnouncement` (visible when Draft)
- Retire → `RetireAnnouncement` (visible when Published)
- Retry failed fan-out → re-dispatch `FanOutAnnouncementToTenant` for pivots with status failed

Table columns: title, severity, status, tenants count, published_at, fan-out succeeded/failed counts (optional subquery).

Lock fields on published/retired via `->disabled(fn ($record) => $record?->status !== AnnouncementStatus::Draft)`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AdminAnnouncementResourceTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/Announcements \
  tests/Feature/Announcements/AdminAnnouncementResourceTest.php
git commit -m "feat: add admin Announcements resource"
```

---

### Task 5: App banner query + Livewire + render hook

**Files:**
- Create: `app/Support/Announcements/ActiveTenantAnnouncements.php`
- Create: `app/Livewire/App/TenantAnnouncementBanner.php`
- Create: `resources/views/livewire/app/tenant-announcement-banner.blade.php`
- Create: `resources/views/filament/app/hooks/tenant-announcement-banner.blade.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php` (TOPBAR_AFTER hook — append after existing banners)
- Test: `tests/Feature/Announcements/TenantAnnouncementBannerTest.php`

**Interfaces:**
- Consumes: `TenantAnnouncement`, `TenantAnnouncementDismissal`, auth user
- Produces:
  - `ActiveTenantAnnouncements::forUser(User $user): Collection<int, TenantAnnouncement>`
  - Order: critical → warning → info, then `published_at` desc
  - Eligible when `is_active`, `starts_at` null|≤now, `ends_at` null|≥now, no dismissal for user
  - `TenantAnnouncementBanner::dismiss(int $tenantAnnouncementId): void`

- [ ] **Step 1: Write the failing banner test**

Initialize demo2, create active `TenantAnnouncement`, actingAs user on app panel, GET `/` or dashboard URL for demo2 host, `assertSee` title. Then call dismiss (Livewire test or POST), GET again, `assertDontSee` title. Second user still sees it.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TenantAnnouncementBannerTest`

Expected: FAIL

- [ ] **Step 3: Implement ActiveTenantAnnouncements**

```php
final class ActiveTenantAnnouncements
{
    /** @return Collection<int, TenantAnnouncement> */
    public function forUser(User $user): Collection
    {
        $severityOrder = "FIELD(severity, 'critical', 'warning', 'info')";

        return TenantAnnouncement::query()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->whereDoesntHave('dismissals', fn ($q) => $q->where('user_id', $user->getKey()))
            ->orderByRaw($severityOrder)
            ->orderByDesc('published_at')
            ->get();
    }
}
```

(If MariaDB `FIELD` is undesirable in tests on SQLite, use PHP sort after fetch — prefer PHP sort for portability.)

- [ ] **Step 4: Implement Livewire component + Blade**

Show stacked alerts (daisyUI/Filament-friendly): title, stripped body excerpt, Dismiss button. Severity → CSS classes (`alert-info`, `alert-warning`, `alert-error`).

Hook view:

```blade
@auth
    @livewire(\App\Livewire\App\TenantAnnouncementBanner::class)
@endauth
```

Register Livewire if auto-discovery does not pick `App\Livewire`.

- [ ] **Step 5: Append hook in AppPanelProvider**

```php
->renderHook(
    PanelsRenderHook::TOPBAR_AFTER,
    fn (): string => view('filament.app.hooks.impersonation-banner')->render()
        .view('filament.app.hooks.legal-acceptance-banner')->render()
        .view('filament.app.hooks.tenant-announcement-banner')->render(),
)
```

Only render when `filament()->getId() === 'app'` and tenancy initialized (guard inside the Livewire `render` / `mount`).

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TenantAnnouncementBannerTest`

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Support/Announcements app/Livewire/App/TenantAnnouncementBanner.php \
  resources/views/livewire/app/tenant-announcement-banner.blade.php \
  resources/views/filament/app/hooks/tenant-announcement-banner.blade.php \
  app/Providers/Filament/AppPanelProvider.php \
  tests/Feature/Announcements/TenantAnnouncementBannerTest.php
git commit -m "feat: show dismissible tenant announcement banners"
```

---

### Task 6: End-to-end regression suite + deploy checklist

**Files:**
- Create: `tests/Feature/Announcements/AnnouncementEndToEndTest.php`
- Modify: none required unless nav registration needs a discovery tweak

**Interfaces:**
- Consumes: all prior pieces

- [ ] **Step 1: Write E2E feature test**

Single test method:
1. Admin creates draft with two tenant IDs if available (or one demo2 tenant).
2. Publish (sync queue).
3. Assert tenant banner row + notification.
4. App user dismisses banner; notification still present unread.
5. Retire; banner inactive; notification still present.

- [ ] **Step 2: Run full announcements filter**

Run: `php artisan test --filter=Announcements`

Expected: PASS

- [ ] **Step 3: Manual deploy checklist (document in commit message / PR)**

On peer `/dpool/tracepharma` (admin2/demo2 docroot):
1. Sync code
2. `php artisan migrate --force`
3. `php artisan tenants:migrate --force` (or at least demo2)
4. Reload php-fpm / ensure queue worker running
5. Confirm admin2 nav shows **Announcements**

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Announcements/AnnouncementEndToEndTest.php
git commit -m "test: cover announcement publish banner dismiss and retire"
```

---

## Spec coverage check

| Spec requirement | Task |
|------------------|------|
| Central announcements + pivot | Task 1 |
| Tenant banner + dismissals | Task 2 |
| Publish fan-out + bell idempotency | Task 3 |
| Retire deactivates banner | Task 3 |
| Admin nav Announcements CRUD | Task 4 |
| Banner + dismiss UX | Task 5 |
| Retry failed fan-out | Task 4 |
| AdminsManage gate | Task 4 |
| E2E + migrate deploy notes | Task 6 |

## Placeholder scan

No TBD/TODO left; notification data merge step calls out verifying Filament DB payload shape against existing tests.

## Type consistency

- Central id: UUID string on `Announcement` and `TenantAnnouncement.announcement_id`
- Enums: `AnnouncementSeverity`, `AnnouncementStatus`, `AnnouncementFanOutStatus`
- Jobs: `(string $announcementId, string $tenantId)`
- Actions: `handle(Announcement $announcement): void`
