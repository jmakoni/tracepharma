# DEA Controlled-Substance Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show CII–CV on tenant product and floor HUDs, resolve inbound `DEA:` location strings to existing GLN master data, and warn (never hard-block) when scheduled product meets a blank party DEA number.

**Architecture:** Join schedule via `Product.fda_product_id` → central `FdaProduct.dea_schedule` (no tenant product column). Extend GLN resolution with a DEA-registration ladder that still returns GLN ids. Emit operational warning `SCHEDULED_PRODUCT_MISSING_DEA` on inbound process and open-receive, matching destination-GLN mismatch.

**Tech Stack:** Laravel 13, Pest/PHPUnit, Filament HUD concerns, existing exception catalog (`ExceptionTypeSeeder`, `ExceptionReceiveImpactMap`, `ExceptionCorrectionProfile`).

**Spec:** `docs/superpowers/specs/2026-09-04-dea-controlled-substance-phase-a-design.md`

## Global Constraints

- Phase A only — no CSOS, Form 222, ARCOS, SOM, vault inventory, or dual-control password gate.
- Never persist DEA as EPCIS `bizLocation` / SGLN; internal fields stay GLN.
- Do not denormalize `dea_schedule` onto tenant `products`.
- `fdaProduct()` is cross-connection: load FDA rows by id list; no `whereHas` / join across connections.
- `deaScheduleLabel()` maps II–V only; Schedule I / junk → null.
- Outbound send stays allowed when destination DEA is blank (HUD + warning only). Receive stays allowed.
- No new tenant setting in Phase A.

---

### Task 1: Schedule label tests + DEA number normalize + indexes

**Files:**
- Modify: `tests/Unit/Support/Fda/FdaRegistryStatusTest.php`
- Create: `app/Support/Fda/DeaRegistration.php`
- Create: `tests/Unit/Support/Fda/DeaRegistrationTest.php`
- Create: `database/migrations/tenant/2026_09_04_151000_add_dea_number_indexes_to_sites_and_trading_partners.php`

**Interfaces:**
- Consumes: `FdaRegistryStatus::deaScheduleLabel(?string $schedule): ?string`
- Produces: `DeaRegistration::normalize(?string $raw): ?string`, `DeaRegistration::parseFromLocationToken(string $token): ?string`

- [ ] **Step 1: Write failing schedule-label cases**

Add to `FdaRegistryStatusTest`:

```php
#[Test]
public function dea_schedule_label_normalizes_cii_through_cv(): void
{
    $this->assertSame('CII', FdaRegistryStatus::deaScheduleLabel('2'));
    $this->assertSame('CII', FdaRegistryStatus::deaScheduleLabel('C-II'));
    $this->assertSame('CIII', FdaRegistryStatus::deaScheduleLabel('III'));
    $this->assertSame('CIV', FdaRegistryStatus::deaScheduleLabel('CIV'));
    $this->assertSame('CV', FdaRegistryStatus::deaScheduleLabel('c5'));
    $this->assertNull(FdaRegistryStatus::deaScheduleLabel('CI'));
    $this->assertNull(FdaRegistryStatus::deaScheduleLabel('junk'));
    $this->assertNull(FdaRegistryStatus::deaScheduleLabel(null));
}
```

- [ ] **Step 2: Run test — expect FAIL only if label helper regresses; otherwise it already exists and this PASSES**

Run: `php artisan test --filter=dea_schedule_label_normalizes_cii_through_cv`

- [ ] **Step 3: Write `DeaRegistration` + failing parse tests**

```php
// app/Support/Fda/DeaRegistration.php
final class DeaRegistration
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $compact = strtoupper(preg_replace('/[\s\-]+/', '', trim($raw)) ?? '');

        return $compact === '' ? null : $compact;
    }

    public static function parseFromLocationToken(string $token): ?string
    {
        $trimmed = trim($token);
        if (preg_match('/^(?:urn:epc:id:dea:|dea[:\/])(.+)$/i', $trimmed, $m) === 1) {
            return self::normalize($m[1]);
        }

        $normalized = self::normalize($trimmed);
        if ($normalized === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        if (strlen($digits) === 13) {
            return null; // GLN-shaped; caller uses GLN ladder first
        }

        return $normalized;
    }
}
```

```php
#[Test]
public function parse_accepts_prefixed_dea_and_rejects_gln_shaped_bare_digits(): void
{
    $this->assertSame('AB1234567', DeaRegistration::parseFromLocationToken('DEA:AB1234567'));
    $this->assertSame('AB1234567', DeaRegistration::parseFromLocationToken('dea/ab-1234567'));
    $this->assertSame('AB1234567', DeaRegistration::parseFromLocationToken('urn:epc:id:dea:AB1234567'));
    $this->assertNull(DeaRegistration::parseFromLocationToken('0614141000005'));
}
```

- [ ] **Step 4: Run DeaRegistration tests**

Run: `php artisan test --filter=DeaRegistrationTest`

- [ ] **Step 5: Tenant indexes**

```php
Schema::table('sites', function (Blueprint $table): void {
    $table->index('dea_number', 'sites_dea_number_index');
});
Schema::table('trading_partners', function (Blueprint $table): void {
    $table->index('dea_number', 'trading_partners_dea_number_index');
});
```

Indexes are nullable, non-unique.

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/Support/Fda/FdaRegistryStatusTest.php app/Support/Fda/DeaRegistration.php tests/Unit/Support/Fda/DeaRegistrationTest.php database/migrations/tenant/2026_09_04_151000_add_dea_number_indexes_to_sites_and_trading_partners.php
git commit -m "feat: DEA registration normalize and schedule label tests"
```

---

### Task 2: `ScheduledProductPresence` helper

**Files:**
- Create: `app/Support/Fda/ScheduledProductPresence.php`
- Create: `tests/Unit/Support/Fda/ScheduledProductPresenceTest.php`

**Interfaces:**
- Consumes: `FdaRegistryStatus::deaScheduleLabel()`, tenant `Product.gtin` + `fda_product_id`, central `FdaProduct`
- Produces: `ScheduledProductPresence::forGtins(array $gtins): array{highest: ?string, has_scheduled: bool}`  
  Rank: CII > CIII > CIV > CV. Empty/unlinked GTINs → `highest: null`, `has_scheduled: false`.

- [ ] **Step 1: Write failing test** (demo2 or central+tenant factory pattern used by other FDA-link tests)

```php
#[Test]
public function reports_highest_schedule_for_fda_linked_gtins_only(): void
{
    // Create FdaProduct CII + CIV, stamp two tenant Products, pass both GTINs + one unknown GTIN
    $result = ScheduledProductPresence::forGtins([$ciiGtin, $civGtin, '00000000000000']);
    $this->assertTrue($result['has_scheduled']);
    $this->assertSame('CII', $result['highest']);
}
```

- [ ] **Step 2: Run test — expect FAIL (class missing)**

Run: `php artisan test --filter=ScheduledProductPresenceTest`

- [ ] **Step 3: Implement helper**

```php
final class ScheduledProductPresence
{
    private const RANK = ['CII' => 4, 'CIII' => 3, 'CIV' => 2, 'CV' => 1];

    /**
     * @param  list<string>  $gtins
     * @return array{highest: ?string, has_scheduled: bool}
     */
    public static function forGtins(array $gtins): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $g): string => preg_replace('/\D+/', '', $g) ?? '',
            $gtins,
        ))));

        if ($normalized === []) {
            return ['highest' => null, 'has_scheduled' => false];
        }

        $fdaIds = Product::query()
            ->whereIn('gtin', $normalized)
            ->whereNotNull('fda_product_id')
            ->pluck('fda_product_id')
            ->unique()
            ->values()
            ->all();

        if ($fdaIds === []) {
            return ['highest' => null, 'has_scheduled' => false];
        }

        $schedules = FdaProduct::query()
            ->whereIn('id', $fdaIds)
            ->pluck('dea_schedule');

        $highest = null;
        $highestRank = 0;
        foreach ($schedules as $raw) {
            $label = FdaRegistryStatus::deaScheduleLabel(is_string($raw) ? $raw : null);
            $rank = $label !== null ? (self::RANK[$label] ?? 0) : 0;
            if ($rank > $highestRank) {
                $highestRank = $rank;
                $highest = $label;
            }
        }

        return ['highest' => $highest, 'has_scheduled' => $highest !== null];
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: ScheduledProductPresence from FDA-linked GTINs"
```

---

### Task 3: DEA alternate-ID resolution on inbound locations

**Files:**
- Modify: `app/Actions/Epcis/ResolveGlnToMasterData.php`
- Create: `tests/Unit/Support/Epcis/ResolveGlnToMasterDataDeaTest.php` (or Feature under `tests/Feature/Epcis/`)
- Create: `tests/Fixtures/epcis/dea_prefixed_source_location.xml` (minimal ObjectEvent shipping with source/readPoint `DEA:AB1234567`)

**Interfaces:**
- Consumes: `DeaRegistration::parseFromLocationToken()`, `DeaRegistration::normalize()`, existing GLN ladder
- Produces: same `handle(string $token): array` shape; `gln` is the **matched site/partner GLN**, never the DEA string

Resolution order (spec):

1. Existing GLN / SGLN / device / read-point ladder when the token normalizes to 13 digits (`Sgln::normalizeGln`).
2. Else if `DeaRegistration::parseFromLocationToken($token)` is non-null: match `Site.dea_number` then `TradingPartner.dea_number` using `DeaRegistration::normalize` on both sides (PHP-side filter if SQL cannot express hyphen-stripped equality — prefer storing already-normalized values; query `whereRaw('UPPER(REPLACE(REPLACE(dea_number, \" \", \"\"), \"-\", \"\")) = ?', [$normalized])` or match exact normalized if forms already store compact).
3. 13-digit token that **fails** GLN check digit (`ValidGln::hasValidCheckDigit` / `Gtin` check): try DEA match on the raw token only if `parseFromLocationToken` would have returned null due to length-13 rule — implement an explicit third branch: if length 13 and check digit fails, `normalize($token)` and look up `dea_number`.
4. Never write DEA into `$empty['gln']`.

- [ ] **Step 1: Write failing tests**

```php
#[Test]
public function dea_prefixed_token_resolves_to_site_gln(): void
{
    $site = Site::factory()->create([
        'gln' => '0614141000005',
        'dea_number' => 'AB1234567',
        'is_organization_facility' => false,
    ]);
    $resolved = app(ResolveGlnToMasterData::class)->handle('DEA:AB1234567');
    $this->assertSame((int) $site->getKey(), $resolved['site_id']);
    $this->assertSame('0614141000005', $resolved['gln']);
}

#[Test]
public function thirteen_digit_valid_gln_does_not_use_dea_ladder(): void
{
    // existing GLN path unchanged
}
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement DEA ladder in `ResolveGlnToMasterData::handle`**

Keep the current 13-digit early return. After GLN ladder returns empty ids, call a private `resolveByDeaRegistration(string $token): array`.

- [ ] **Step 4: Ingest fixture test** — process document whose source/readPoint is `DEA:AB1234567`; assert `epcis_documents.ship_from_gln` (or event location GLN) equals the site GLN and no event location column contains `DEA:`.

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: resolve inbound DEA location tokens to site or partner GLN"
```

---

### Task 4: Exception catalog `SCHEDULED_PRODUCT_MISSING_DEA`

**Files:**
- Modify: `database/seeders/ExceptionTypeSeeder.php` — add catalog row after `UNKNOWN_GLN`
- Modify: `app/Support/Exceptions/ExceptionReceiveImpactMap.php` — Warning
- Modify: `app/Support/Exceptions/ExceptionCorrectionProfile.php` — `CODE_FAMILY` + `CODE_OVERRIDES` blurb
- Modify: `app/Support/Epcis/Validation/EpcisValidationCatalog.php` — add to operational/soft list (same group as `DESTINATION_*`)
- Modify: `app/Support/Epcis/Validation/EpcisValidationSeverityMap.php` — `warning`

**Interfaces:**
- Produces: code `SCHEDULED_PRODUCT_MISSING_DEA`, category `MasterData`, severity Medium/Warning, receive_impact Warning
- Correction blurb: `This shipment includes DEA-scheduled product. Add the seller or destination DEA registration on the trading partner or site, then reprocess.`
- Family: `FAMILY_MASTER_DATA_LOCATION`

- [ ] **Step 1: Add seeder row**

```php
$this->row(
    'SCHEDULED_PRODUCT_MISSING_DEA',
    'Scheduled product missing DEA',
    ExceptionTypeCategory::MasterData,
    ExceptionSeverity::Medium,
    'DEA-scheduled product present and seller or destination has no DEA registration',
    'data_issues',
),
```

- [ ] **Step 2: Map + profile + catalog** (no new Filament correction action)

- [ ] **Step 3: Commit**

```bash
git commit -m "feat: catalog SCHEDULED_PRODUCT_MISSING_DEA warning"
```

---

### Task 5: Record warning on inbound + open receive

**Files:**
- Create: `app/Actions/Epcis/RecordScheduledProductMissingDea.php`
- Modify: `app/Actions/Epcis/ProcessEpcisDocument.php` — after `RecordDestinationGlnMismatch` (~line 247)
- Modify: `app/Actions/Receiving/OpenReceivingSessionFromDocument.php` — call `handle($document)` like destination GLN
- Modify: `app/Actions/Epcis/ProcessEpcisDocument.php` `clearSoftSignalExceptions` include this code if that list is explicit
- Test: `tests/Feature/Epcis/RecordScheduledProductMissingDeaTest.php`

**Interfaces:**
- Consumes: `ScheduledProductPresence::forGtins()`, document `trading_partner_id`, seller sites’ `dea_number`, `RecordOperationalEpcisException` (same as destination mismatch)
- Produces: `RecordScheduledProductMissingDea::EXCEPTION_TYPE = 'SCHEDULED_PRODUCT_MISSING_DEA'`  
  `handle(EpcisDocument $document): array` — inbound only; skip outbound ingest

Party DEA present if `TradingPartner.dea_number` is non-blank after `DeaRegistration::normalize`, else any seller `Site.dea_number` for that partner.

Clear open signals then re-derive (destination-GLN pattern), not ATP’s “already open → skip”.

GTINs: distinct `gtin14` from document EPCs (existing document EPC relation / event EPCs).

- [ ] **Step 1: Failing feature tests**

```php
#[Test]
public function inbound_cii_without_seller_dea_opens_warning_and_receive_still_opens(): void { /* ... */ }

#[Test]
public function inbound_cii_with_seller_dea_does_not_signal(): void { /* ... */ }

#[Test]
public function outbound_ingest_skips_signal(): void { /* ... */ }
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement action + wire process + open-receive**

- [ ] **Step 4: Run tests — expect PASS; receive `OpenReceivingSessionFromDocument` still succeeds**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: warn when inbound scheduled product lacks seller DEA"
```

---

### Task 6: Tenant product / FDA product UI + session HUD chips

**Files:**
- Modify: `app/Filament/App/Resources/Products/Schemas/ProductInfolist.php` — DEA badge from `$record->fdaProduct?->dea_schedule`
- Modify: `app/Filament/App/Resources/Products/Tables/ProductsTable.php` — optional toggleable DEA column
- Modify: `app/Filament/App/Resources/FdaProducts/Schemas/FdaProductInfolist.php` — `dea_schedule` via `FdaRegistryStatus::deaScheduleLabel`
- Modify: `app/Filament/App/Resources/FdaProducts/Tables/FdaProductsTable.php` if a table exists
- Create: `app/Support/Fda/ScheduledSessionChip.php` — `label(?string $highest, bool $missingDea, string $missingSuffix): ?string` e.g. `CII · No DEA on seller`
- Modify HUD concerns + Blade views:
  - `app/Filament/App/Resources/ReceivingSessions/Concerns/InteractsWithReceivingSessionHud.php`
  - `resources/views/filament/app/resources/receiving-sessions/pages/view-receiving-session.blade.php`
  - `resources/views/filament/app/resources/receiving-sessions/pages/mobile-view-receiving-session.blade.php`
  - `InteractsWithOutboundShippingSessionHud` + outbound mobile Blade
  - `InteractsWithTransferringSessionHud` + transfer mobile Blade
- Test: `tests/Feature/Receiving/ScheduledProductHudChipTest.php` (and thin shipping/transfer equivalents or one parameterized test)

**Interfaces:**
- Consumes: `ScheduledProductPresence::forGtins()` from session expected/confirmed EPC GTINs
- Chip color: CII → danger; CIII–CV → warning; missing-DEA suffix does not change the schedule color
- Receiving suffix: `No DEA on seller`
- Outbound suffix: `No DEA on ship-to` (also warn if own ship-from org site DEA blank — include in chip text `No DEA on ship-from` when that is the only gap, or combine)
- Transfer suffix: `No DEA on destination`

Do **not** add a `ValidateOutboundShippingSend` blocker.

- [ ] **Step 1: Failing Livewire/feature assertion** that receiving view HTML contains `CII` when session GTINs are FDA-linked CII

- [ ] **Step 2: Implement helper + HUD properties** `chipDeaSchedule`, `chipDeaMissingParty` computed on mount / after confirm

- [ ] **Step 3: Product + FDA infolist entries** (eager-load `fdaProduct` on product view; if relation cannot eager-load cross-db, resolve in `state()` via `FdaProduct::query()->find($record->fda_product_id)`)

- [ ] **Step 4: Run HUD + existing receiving tests**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: show DEA schedule on products and floor session HUDs"
```

---

### Task 7: Migrate demo tenants + spec status

**Files:**
- Modify: `docs/superpowers/specs/2026-09-04-dea-controlled-substance-phase-a-design.md` — Status: Phase A implemented
- Run tenant migrations on demo2 + wholesaler (user environments)

- [ ] **Step 1:** `php artisan tenants:migrate --tenants=13fe9068-cb05-4bab-9e0e-a89f2a458832 --force` and wholesaler `f43e01c9-c7d7-452c-bf2c-4c9163877699`

- [ ] **Step 2:** `php artisan test --filter='DeaRegistration|ScheduledProductPresence|RecordScheduledProductMissingDea|ResolveGlnToMasterDataDea|ScheduledProductHud|dea_schedule_label'`

- [ ] **Step 3: Commit spec status** if not already in Task 6

---

## Spec coverage

| Spec requirement | Task |
|------------------|------|
| `deaScheduleLabel` tests | 1 |
| DEA normalize / parse | 1 |
| Indexes on `dea_number` | 1 |
| Schedule from `fda_product_id` join | 2 |
| `DEA:` → GLN resolution | 3 |
| Never persist DEA as bizLocation | 3 |
| Exception catalog + correction blurb | 4 |
| Inbound + open-receive warning | 5 |
| Receive not blocked | 5 |
| Product / FDA UI + HUD chips | 6 |
| Outbound send not blocked | 6 (no ValidateOutboundShippingSend change) |
| No CSOS/ARCOS/222 | all tasks omit |

## Placeholder scan

No TBD / “handle edge cases” steps. Signatures named above are the ones later tasks consume.
