# Wave 1 Mid-market Deal Blockers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Wave 1 GA slices — outbound SFTP, MDN catalog emitters, partner apply-form, drop-ship indicator, named PMS runbooks — per [`docs/superpowers/specs/2026-08-27-wave1-midmarket-deal-blockers-design.md`](../specs/2026-08-27-wave1-midmarket-deal-blockers-design.md).

**Architecture:** Sequential slices on one branch. Reuse inbound Flysystem SFTP factory patterns, existing `RecordOperationalEpcisCatalogSignal`, supplier quarantine signed pages, and unified dispense-check API. No scan-page redesign; no `/api/v1/pms/{vendor}` or TraceLink-style T2 network.

**Tech Stack:** Laravel 13, Filament 5, Pest/PHPUnit, League Flysystem SFTP (phpseclib v3), Stancl tenancy.

## Global Constraints

- No Receive/Ship/Transfer/Pack/Unpack/Scan In/Out/VRS workstation layout redesign
- Catalog reject code is `PARTNER_REJECTED_FILE` (not `PARTNER_REJECTED`)
- No inbound email-reply parser; no multienterprise POET workspace
- No multi-party drop-ship network choreography / Delivery UI
- No `POST /api/v1/pms/{vendor}/dispense`; keep `POST /api/v1/dispense-check`
- No `GET /api/v1/compliance/*` in Wave 1
- TDD: failing test → implement → green per task
- Do not commit unless the user asks

---

## File map

| Slice | Create | Modify |
|-------|--------|--------|
| 1 SFTP | — | `SftpOutboundSender`, `SftpConnectionProviderFactory`, `OutboundTransportAvailability`, `OutboundConnectionResolver`, `OutboundConnectionForm`, Integration Health page/blade/metrics, docs, tests |
| 2 MDN | `EmitPendingMdnCatalogSignalsCommand` (name may vary) | `ProcessAs2AsyncMdn`, `ConnectionOutboundEpcisTransmitter`, `RecordOperationalEpcisException` or catalog signal (de-dupe), `ExceptionCorrectionProfile`, `config/tracepharma.php`, `routes/console.php`, docs, tests |
| 3 Apply-form | — | `SupplierQuarantineController`, show blade, `QuarantineService` signed URL helper, routes, `ExceptionService` (nullable actor or system transition), docs, tests |
| 4 Drop-ship | — | Outbound shipping session field + form, `GenerateShippingEpcisEvents` / TI fragments, docs, tests |
| 5 PMS | `docs/integrations/pms/*.md` runbooks | `pms.md`, `multi-pms-adapters.md`, `PmsIntegrationChecklist`, optional marketing links |

---

### Task 1: Unlock outbound SFTP availability + factory

**Files:**
- Modify: `app/Support/Integrations/OutboundTransportAvailability.php`
- Modify: `app/Support/SftpConnectionProviderFactory.php`
- Modify: `app/Services/Epcis/OutboundConnectionResolver.php`
- Test: `tests/Unit/Support/Integrations/OutboundTransportAvailabilityTest.php`

**Interfaces:**
- Produces: `OutboundTransportAvailability::isSelectable(Sftp) === true`; `assertSavable` / `assertTransmittable` allow SFTP; `SftpConnectionProviderFactory::forOutboundConnection(OutboundConnection): SftpConnectionProvider`; resolver includes active SFTP

- [ ] **Step 1: Rewrite availability tests for productized SFTP**

Replace “not selectable / reject save / reject transmit” assertions with:

```php
public function sftp_outbound_transport_is_selectable(): void
{
    $this->assertTrue(OutboundTransportAvailability::isSelectable(OutboundTransport::Sftp));
}

public function assert_savable_allows_new_sftp_connection(): void
{
    $connection = new OutboundConnection([
        'name' => 'Partner SFTP',
        'serialization_provider' => SerializationProvider::CustomSftp,
        'transport' => OutboundTransport::Sftp,
        'is_active' => true,
    ]);
    OutboundTransportAvailability::assertSavable($connection);
    $this->assertTrue(true); // no exception
}

public function assert_transmittable_allows_sftp(): void
{
    $connection = new OutboundConnection(['transport' => OutboundTransport::Sftp, 'is_active' => true]);
    OutboundTransportAvailability::assertTransmittable($connection);
}
```

Keep or retire `isLegacyUnavailable` — prefer `false` for SFTP once productized; update Integration Health later in Task 3.

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --compact tests/Unit/Support/Integrations/OutboundTransportAvailabilityTest.php`

- [ ] **Step 3: Implement availability + factory + resolver**

`OutboundTransportAvailability`:
- `isSelectable` → always true (or only exclude nothing)
- `isLegacyUnavailable` → always false (or remove SFTP special case)
- Remove SFTP branches from `assertSavable` / `assertTransmittable` (or no-op)
- Soften/repurpose `sftpSaveMessage` / `sftpTransmitMessage` only if still referenced; prefer delete unused after sender works
- `deactivateActiveLegacySftpConnections` may remain for ops cleanup of bad rows

`SftpConnectionProviderFactory::forOutboundConnection`:

```php
public static function forOutboundConnection(OutboundConnection $connection): SftpConnectionProvider
{
    $credentials = $connection->credentials ?? [];
    $settings = $connection->settings ?? [];

    return new SftpConnectionProvider(
        host: $credentials['host'] ?? $settings['host'] ?? '',
        username: $credentials['username'] ?? '',
        password: $credentials['password'] ?? null,
        privateKey: $credentials['private_key'] ?? null,
        passphrase: $credentials['passphrase'] ?? null,
        port: (int) ($settings['port'] ?? 22),
        timeout: (int) ($settings['timeout'] ?? 30),
    );
}
```

`OutboundConnectionResolver::resolve`: remove `->where('transport', '!=', OutboundTransport::Sftp)`.

- [ ] **Step 4: Run tests — expect PASS**

---

### Task 2: Implement `SftpOutboundSender` with injectable Filesystem

**Files:**
- Modify: `app/Services/Epcis/Outbound/SftpOutboundSender.php`
- Test: `tests/Unit/Services/Epcis/Outbound/SftpOutboundSenderTest.php`

**Interfaces:**
- Consumes: `SftpConnectionProviderFactory::forOutboundConnection`
- Produces: `send(OutboundConnection, string $content, string $filename, ?Filesystem $filesystem = null): void` writes to `{outbound_path}/{filename}`

- [ ] **Step 1: Failing test — put succeeds with fake filesystem**

```php
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter; // or Mockery mock with write expectation

#[Test]
public function send_writes_content_to_outbound_path(): void
{
    $adapter = new InMemoryFilesystemAdapter;
    $fs = new Filesystem($adapter);
    $connection = new OutboundConnection([
        'transport' => OutboundTransport::Sftp,
        'settings' => ['host' => 'sftp.example', 'outbound_path' => 'outbound/epcis', 'root' => '/'],
        'credentials' => ['username' => 'u', 'password' => 'p'],
    ]);

    app(SftpOutboundSender::class)->send($connection, '<epcis/>', 'ship-1.xml', $fs);

    $this->assertSame('<epcis/>', $fs->read('outbound/epcis/ship-1.xml'));
}

#[Test]
public function send_requires_host(): void
{
    $this->expectException(DomainException::class);
    app(SftpOutboundSender::class)->send(new OutboundConnection([
        'transport' => OutboundTransport::Sftp,
        'settings' => ['outbound_path' => '/out'],
        'credentials' => ['username' => 'u'],
    ]), '<x/>', 'a.xml');
}
```

If `league/flysystem-memory` is not installed, Mockery-mock `Filesystem` with `write` expectation instead — prefer memory package only if already in composer; otherwise mock.

- [ ] **Step 2: Run — expect FAIL (still throws stub)**

- [ ] **Step 3: Implement sender**

```php
public function send(OutboundConnection $connection, string $content, string $filename, ?Filesystem $filesystem = null): void
{
    $settings = $connection->settings ?? [];
    $credentials = $connection->credentials ?? [];
    $host = $credentials['host'] ?? $settings['host'] ?? '';
    if ($host === '') {
        throw new DomainException('SFTP outbound connection is missing host.');
    }
    $username = $credentials['username'] ?? '';
    if ($username === '') {
        throw new DomainException('SFTP outbound connection is missing username.');
    }

    $filesystem ??= $this->filesystemFor($connection);
    $dir = trim((string) ($settings['outbound_path'] ?? 'outbound/epcis'), '/');
    $path = ($dir === '' ? '' : $dir.'/').ltrim($filename, '/');
    $filesystem->write($path, $content);
}
```

Mirror inbound `filesystemFor` using factory + `settings.root`.

- [ ] **Step 4: Run — expect PASS**

---

### Task 3: Filament SFTP form + Integration Health + docs + feature tests

**Files:**
- Modify: `app/Filament/App/Resources/OutboundConnections/Schemas/OutboundConnectionForm.php`
- Modify: Integration Health page/blade/metrics (retire legacy-unavailable for product SFTP)
- Modify: `docs/integrations/outbound-transports.md`
- Modify: `tests/Feature/Integrations/OutboundConnectionIntegrationTest.php`, `IntegrationHealthPageTest.php` as needed

- [ ] **Step 1: Add SFTP form section** (visible when transport = Sftp)

Fields: `settings.host`, `settings.port`, `settings.outbound_path`, `settings.root` (optional), `sftp_username`, `sftp_password`, `sftp_private_key`, `sftp_passphrase` — reuse `TransformsConnectionCredentials` like inbound.

Remove transport select filter that excludes SFTP / fail rule using `sftpSaveMessage`.

- [ ] **Step 2: Soften Integration Health**

Rename copy from “legacy unavailable” to optional “deactivate SFTP connections” only if still useful; stop treating SFTP as unavailable badge via `isLegacyUnavailable` returning false.

- [ ] **Step 3: Update feature tests that expect stub failure on SFTP transmit**

Pinned legacy SFTP transmit test should expect success when filesystem injected OR mark connection + mock sender — follow existing transmitter test patterns. Prefer binding a fake sender in container for feature test.

- [ ] **Step 4: Docs** — Outbound SFTP row = Production; remove pilot-only paragraph.

- [ ] **Step 5: Run** `php artisan test --compact --filter=Sftp|OutboundTransport|OutboundConnection|IntegrationHealth`

---

### Task 4: MDN partner-rejected emitters + de-dupe

**Files:**
- Modify: `app/Actions/Epcis/RecordOperationalEpcisException.php` (or catalog signal wrapper) — open-row de-dupe by `document_id` + `exception_type`
- Modify: `app/Actions/Integrations/ProcessAs2AsyncMdn.php`
- Modify: `app/Services/Epcis/ConnectionOutboundEpcisTransmitter.php`
- Test: new feature/unit tests under `tests/Feature/Integrations/` or `tests/Unit/`

**Interfaces:**
- Produces: on MDN `failed`, at most one open `PARTNER_REJECTED_FILE` per document

- [ ] **Step 1: Failing test** — async MDN failed creates catalog exception once; second call no duplicate open row

- [ ] **Step 2: Implement de-dupe in recorder** — if open exception exists for document+code, return existing

- [ ] **Step 3: Wire `partnerRejected` on sync reject (transmitter after failed MDN) and async failed save

- [ ] **Step 4: Run tests PASS**

---

### Task 5: MDN missing/late schedule + unhide codes

**Files:**
- Create: `app/Console/Commands/EmitPendingMdnCatalogSignalsCommand.php`
- Modify: `routes/console.php`, `config/tracepharma.php`
- Modify: `app/Support/Exceptions/ExceptionCorrectionProfile.php` — remove three MDN codes from `operatorHiddenStubCodes`
- Modify: `tests/Unit/Support/Exceptions/ExceptionCorrectionProfileStubCodesTest.php`
- Create: command feature test

Config:

```php
'as2_mdn' => [
    'missing_after_hours' => (int) env('TRACEPHARMA_AS2_MDN_MISSING_HOURS', 24),
    'late_after_hours' => (int) env('TRACEPHARMA_AS2_MDN_LATE_HOURS', 72),
],
```

Command logic:
- Pending MDNs with `created_at` older than missing hours → `missingMdn` (de-duped)
- Optionally: if MDN received after late window, `lateMdn` on receive path OR pending past late hours → `lateMdn` (prefer: pending past late → late; past missing but before late → missing — use exclusive windows: missing at 24h, escalate/replace with late at 72h OR emit late only when received after late SLA — pick **pending past missing → missingMdn; pending past late → lateMdn** with de-dupe so both don’t spam — if both thresholds met, emit only `lateMdn`)

Schedule daily or hourly in `routes/console.php`.

- [ ] **Step 1–4:** TDD command + unhide + docs catalog note + tests PASS

---

### Task 6: Partner apply-form

**Files:**
- Modify: `routes/tenant.php` — POST `supplier-quarantine/{shareUuid}/apply`
- Modify: `app/Services/Quarantine/QuarantineService.php` — signed apply URL
- Modify: `app/Http/Controllers/SupplierQuarantineController.php` — `apply` method
- Modify: `resources/views/supplier-quarantine/show.blade.php`
- Modify: `app/Services/Exceptions/ExceptionService.php` — allow `?User $actor = null` for system transitions (log as System / partner-triggered)
- Test: feature test with signed URL

Apply payload validation:
- `acknowledged` required accepted
- `corrected_reference` optional string
- `gtin`, `serial`, `lot`, `expiry` optional strings
- `notes` optional string
- `supplier_name` optional

On success: `logActivity(Comment|System, null, summary, Partner, meta: [...])`; if status `WaitingPartner` → `transition(..., Investigating, null)`.

- [ ] **Step 1–4:** TDD apply + docs `partner-exception-collaboration.md` + tests PASS

---

### Task 7: Drop-ship indicator on outbound ship

**Files:**
- Migration (tenant): `is_drop_shipment` boolean default false on outbound shipping sessions (or settings JSON — prefer boolean column if sessions table is standard)
- Filament ship order / session form: checkbox (no scan layout redesign)
- `GenerateShippingEpcisEvents` / `ShippingTiTsFragments`: when true, include `dropShipment` indicator in XML (match what inbound `checkDropShipmentIndicator` string-scans)
- Docs: `drop-ship-t2.md`, outbound-transports cross-link
- Tests: generation includes / excludes indicator

- [ ] **Step 1–4:** TDD + implement + docs + PASS

---

### Task 8: Named PMS runbooks + checklist links

**Files:**
- Create: `docs/integrations/pms/pioneerrx.md`, `bestrx.md`, `primerx.md` (and optionally liberty-rx30, qs1)
- Modify: `docs/integrations/pms.md`, `docs/integrations/multi-pms-adapters.md`
- Modify: `app/Support/PmsIntegrationChecklist.php` / Filament checklist blade to link runbooks
- Optional: marketing PMS show pages link to runbooks

Each runbook: Sanctum token + `dispense:check` ability, `POST /api/v1/dispense-check` body, map vendor webhook → unified fields, cutover via in-app checklist.

- [ ] **Step 1:** Write runbooks (no fake routes)
- [ ] **Step 2:** Checklist links
- [ ] **Step 3:** Smoke/assert checklist contains runbook paths if tested

---

### Task 9: CHANGELOG + roadmap Wave 1 note

**Files:**
- Modify: `CHANGELOG.md`, `docs/roadmap-status.md`
- Mark plan Wave 1 todo complete in Tenant Type Feature Gaps plan when all slices green

---

## Spec coverage self-review

| Spec slice | Tasks |
|------------|-------|
| Outbound SFTP | 1–3 |
| MDN emitters | 4–5 |
| Apply-form | 6 |
| Drop-ship indicator | 7 |
| PMS runbooks | 8 |
| Docs/CHANGELOG | 3,5,6,7,8,9 |

No placeholders remaining. `PARTNER_REJECTED_FILE` named correctly. De-dupe called out for MDN.

---

## Execution

Plan saved to `docs/superpowers/plans/2026-08-28-wave1-midmarket-deal-blockers.md`.

Proceeding with **inline execution** (user said “next”) — Task 1 onward in this session.
