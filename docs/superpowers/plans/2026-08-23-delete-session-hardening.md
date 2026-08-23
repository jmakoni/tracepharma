# Delete-session Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix mobile receive delete no-op, transfer from-site delete auth, transfer-receive eligibility when receive EPCIS exists, and invoice file cleanup; add Filament phrase / job-role / mobile / transfer-EPCIS tests.

**Architecture:** Shared eligibility stays in `UnsubmittedSessionDelete`; domain Actions own side effects (invoice unlink, transfer revert). Policy for transfer delete narrows to from-site to match Action. Mobile page whitelists the existing Filament action name.

**Tech Stack:** Laravel 13, Filament 5, Stancl tenant DB, Pest/PHPUnit. Prefer demo2 tenant patterns already used in `Delete*SessionTest`. Prefer `DatabaseTransactions` where those suites already do; no central `RefreshDatabase` / `migrate:fresh`.

## Global Constraints

- Scope = DH-1…DH-8 only (no ship suite fixture work, no AS2, no branding).
- Minimal diffs; TDD per task.
- Skip git commits unless user explicitly asks (workspace may lack usable git).
- Source-first: edit only under `/dpool/tracepharma`.

---

### Task 1: DH-1 Mobile `deleteReceiving` whitelist

**Files:**
- Modify: `app/Filament/App/Resources/ReceivingSessions/Pages/MobileViewReceivingSession.php` (`getHeaderActions` filter list)
- Test: `tests/Feature/Receiving/DeleteReceivingSessionTest.php` (add DH-8 mobile case here or in Task 5 — prefer add failing mobile test in this task)

**Interfaces:**
- Consumes: `UnsubmittedSessionDeleteAction::forReceivingHud` action name `deleteReceiving` (already registered via HUD trait)
- Produces: `getHeaderActions()` includes action named `deleteReceiving`

- [ ] **Step 1: Write the failing mobile delete test**

```php
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;

#[Test]
public function mobile_delete_receiving_action_deletes_session(): void
{
    $this->initializeDemo2Tenant();

    try {
        config(['tracepharma.regulatory_compliance.password_gate' => false]);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'delete-receive-mobile-'.uniqid('', true).'@example.test',
        ]);
        $this->userIds[] = (int) $user->getKey();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);

        $session = app(OpenScanFirstReceivingSession::class)->handle();
        $this->trackSession($session);
        $sessionId = (int) $session->getKey();

        Livewire::test(MobileViewReceivingSession::class, ['record' => $sessionId])
            ->assertActionVisible('deleteReceiving')
            ->callAction('deleteReceiving')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertNull(ReceivingSession::query()->find($sessionId));
    } finally {
        $this->cleanup();
    }
}
```

- [ ] **Step 2: Run test — expect fail** (action missing / not visible / call no-ops)

```bash
php artisan test --filter=mobile_delete_receiving_action_deletes_session
```

- [ ] **Step 3: Add `deleteReceiving` to the whitelist**

In `MobileViewReceivingSession::getHeaderActions`, add `'deleteReceiving'` next to `'cancelReceiving'` in the `in_array` name list.

- [ ] **Step 4: Re-run — expect PASS**

- [ ] **Step 5: Commit only if user asked** (otherwise skip)

---

### Task 2: DH-3 Block hard-delete when transfer receive EPCIS authored

**Files:**
- Modify: `app/Support/Floor/UnsubmittedSessionDelete.php` (`canHardDeleteReceiving`)
- Modify: `app/Actions/Receiving/DeleteReceivingSession.php` (assert same gate inside transaction before revert)
- Test: `tests/Feature/Receiving/DeleteReceivingSessionTest.php`

**Interfaces:**
- Consumes: `ReceivingSession::isTransferReceive()`, `transferring_session_id`, `TransferringSession.receive_events_generated_at`
- Produces: `canHardDeleteReceiving()` returns false when linked transfer has `receive_events_generated_at !== null`

- [ ] **Step 1: Write failing tests**

```php
#[Test]
public function it_refuses_hard_delete_when_transfer_receive_epcis_authored(): void
{
    // Build transfer_receive session as existing delete/cancel transfer helpers do,
    // then forceFill linked TransferringSession receive_events_generated_at = now().
    $this->assertFalse($session->fresh()->canHardDelete());
    $this->expectException(DomainException::class);
    app(DeleteReceivingSession::class)->handle($session->fresh());
}
```

Reuse transfer-receive fixture patterns already in `DeleteReceivingSessionTest` / cancel tests.

- [ ] **Step 2: Run — expect FAIL** (`canHardDelete` still true or Action succeeds)

- [ ] **Step 3: Implement**

In `UnsubmittedSessionDelete::canHardDeleteReceiving`, after existing checks:

```php
if ($session->isTransferReceive() && $session->transferring_session_id !== null) {
    $receiveGeneratedAt = TransferringSession::query()
        ->whereKey($session->transferring_session_id)
        ->value('receive_events_generated_at');

    if ($receiveGeneratedAt !== null) {
        return false;
    }
}
```

In `DeleteReceivingSession` transaction, before calling revert, if transfer receive and linked transfer has `receive_events_generated_at`, throw `DomainException` with a clear message (same spirit as revert).

Add `use App\Models\Transferring\TransferringSession;` to the Support class if needed.

- [ ] **Step 4: Re-run — PASS**

---

### Task 3: DH-2 Transfer delete from-site-only policy

**Files:**
- Modify: `app/Policies/TransferringSessionPolicy.php` (`delete`)
- Test: `tests/Feature/Transferring/DeleteTransferringSessionTest.php`

**Interfaces:**
- Consumes: `SiteAccess::canAccessSite($user, from_site_id)`, `JobRoleAccess::allows(Permissions::NavShip, $user)`
- Produces: `delete()` true only when NavShip **and** from-site access (not to-site alone)

- [ ] **Step 1: Write failing tests**

```php
#[Test]
public function to_site_only_user_cannot_delete_transfer_session(): void
{
    // User with site access only to to_site_id, not from_site_id.
    $this->assertFalse($user->can('delete', $session));
    // Optional: Action assert AuthorizationException / SiteAccess denial when acting as that user.
}

#[Test]
public function from_site_user_can_delete_open_transfer_session(): void
{
    $this->assertTrue($user->can('delete', $session));
}
```

Mirror site-scoping patterns from receive delete cross-site tests and existing transfer cancel/auth tests.

- [ ] **Step 2: Run — expect FAIL** (to-site currently allowed via `update`)

- [ ] **Step 3: Implement**

```php
public function delete(User $user, TransferringSession $session): bool
{
    if (! JobRoleAccess::allows(Permissions::NavShip, $user)) {
        return false;
    }

    $fromSiteId = $session->from_site_id;
    if ($fromSiteId === null) {
        return false;
    }

    return SiteAccess::canAccessSite($user, (int) $fromSiteId);
}
```

Do **not** change `view` / `update` either-site behavior.

- [ ] **Step 4: Re-run — PASS**

---

### Task 4: DH-4 Delete invoice file before hard-deleting receive

**Files:**
- Modify: `app/Actions/Receiving/DeleteReceivingSession.php`
- Test: `tests/Feature/Receiving/DeleteReceivingSessionTest.php`
- Reference: `tests/Feature/Receiving/AttachReceivingSessionInvoiceTest.php` for attach + Storage cleanup patterns

**Interfaces:**
- Consumes: `invoice_disk`, `invoice_path` on session
- Produces: file absent from disk after successful delete; missing file must not abort delete

- [ ] **Step 1: Write failing test**

```php
use App\Actions\Receiving\AttachReceivingSessionInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

#[Test]
public function it_removes_invoice_blob_when_hard_deleting(): void
{
    $session = app(OpenScanFirstReceivingSession::class)->handle();
    // Attach via AttachReceivingSessionInvoice (fake UploadedFile) as in AttachReceivingSessionInvoiceTest
    $disk = (string) $session->fresh()->invoice_disk;
    $path = (string) $session->fresh()->invoice_path;
    $this->assertTrue(Storage::disk($disk)->exists($path));

    app(DeleteReceivingSession::class)->handle($session->fresh());

    $this->assertFalse(Storage::disk($disk)->exists($path));
    $this->assertNull(ReceivingSession::query()->find($session->getKey()));
}
```

- [ ] **Step 2: Run — expect FAIL** (file still exists)

- [ ] **Step 3: Implement** inside the transaction, after eligibility checks and before `$session->delete()`:

```php
$disk = $session->invoice_disk;
$path = $session->invoice_path;
if (filled($disk) && filled($path)) {
    Storage::disk((string) $disk)->delete((string) $path);
}
```

Use `Illuminate\Support\Facades\Storage`. Swallow missing-file quietly (Storage::delete already no-ops / returns false).

- [ ] **Step 4: Re-run — PASS**

---

### Task 5: DH-5 / DH-6 Filament phrase + job-role denial tests

**Files:**
- Test only: `tests/Feature/Receiving/DeleteReceivingSessionTest.php`
- Test only (optional ship/transfer): `DeleteOutboundShippingSessionTest.php`, `DeleteTransferringSessionTest.php` for NavShip denial if not already covered

**Interfaces:**
- Consumes: Filament `callAction('deleteReceiving', ['confirm_phrase' => ...])`; Action `DomainException` for job role

- [ ] **Step 1: Phrase tests (receive view is enough)**

```php
#[Test]
public function delete_receiving_requires_delete_phrase_when_scans_exist(): void
{
    // Confirm at least one scan so confirmedScanCount > 0
    Livewire::test(ViewReceivingSession::class, ['record' => $sessionId])
        ->callAction('deleteReceiving', data: ['confirm_phrase' => 'WRONG'])
        ->assertHasActionErrors(['confirm_phrase']);

    $this->assertNotNull(ReceivingSession::query()->find($sessionId));

    Livewire::test(ViewReceivingSession::class, ['record' => $sessionId])
        ->callAction('deleteReceiving', data: ['confirm_phrase' => 'DELETE'])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $this->assertNull(ReceivingSession::query()->find($sessionId));
}
```

- [ ] **Step 2: Job-role denial**

Create user **without** `NavReceive` (use a tenant job role / permission strip pattern already used elsewhere — e.g. assign a role that lacks receive nav, or revoke permission). Call `DeleteReceivingSession::handle` → expect `DomainException` with receiving-not-authorized message.

Mirror for ship/transfer with `NavShip` if easy in existing test harnesses; receive is required.

- [ ] **Step 3: Run Delete receive (+ ship/transfer if touched) — PASS**

---

### Task 6: Close-out regression

**Files:** none required (optional one-line note in `docs/roadmap-status.md` if that file tracks bug hunts)

- [ ] **Step 1: Run focused suites**

```bash
php artisan test tests/Feature/Receiving/DeleteReceivingSessionTest.php \
  tests/Feature/Shipping/DeleteOutboundShippingSessionTest.php \
  tests/Feature/Transferring/DeleteTransferringSessionTest.php
```

- [ ] **Step 2: Confirm all DH-1…DH-8 behaviors covered and green**

- [ ] **Step 3: Skip commit unless user requests**

---

## Spec coverage check

| Spec ID | Task |
|---------|------|
| DH-1 | Task 1 |
| DH-2 | Task 3 |
| DH-3 | Task 2 |
| DH-4 | Task 4 |
| DH-5 | Task 5 |
| DH-6 | Task 5 |
| DH-7 | Task 2 |
| DH-8 | Task 1 |
