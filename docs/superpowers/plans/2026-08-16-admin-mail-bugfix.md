# Admin database mail bugfix plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix confirmed correctness bugs in first-party admin database mail so applicant-controlled merge values display once, subjects stay header-safe, and preview/test-send match the copy on screen.

**Architecture:** Keep the catalog + `mail_templates` + existing Notification `toMail()` path. Change only how merge values are escaped (markdown-safe, not `e()`), how action URLs are treated, and how the Filament edit page builds preview/test payloads from the current form.

**Tech Stack:** Laravel 13, Filament 5, Pest/PHPUnit, `MailMessage` markdown mail.

## Global Constraints

- Isolated fixtures only. No `RefreshDatabase`.
- No `composer require` of laravel-database-mail. No Blade-from-DB.
- Do not change From address (`config('tracepharma.onboarding_mail')`).
- Do not put tenant DSCSA/exception/recall copy in the DB editor.
- Platform Admin / `Permissions::AdminsManage` remains the only Filament gate.
- Catalog is still the only source of new keys (no UI create).
- TDD: failing test first, then minimal fix.

---

## File map

- Modify: `app/Support/Mail/MailTemplateRenderer.php` — stop HTML-escaping; markdown-escape + strip C0
- Modify: `app/Support/Mail/ComposeDatabaseMail.php` — subject/url contexts; optional copy override; http(s) action URLs
- Modify: `app/Support/Mail/MailTemplateCatalog.php` — `find()` that does not throw
- Modify: `app/Models/MailTemplate.php` — `definition()` via `find()` / safe label
- Modify: `app/Filament/Admin/Resources/MailTemplates/Pages/EditMailTemplate.php` — preview/test from form state
- Modify: `app/Filament/Admin/Resources/MailTemplates/Tables/MailTemplatesTable.php` — unknown key must not 500
- Modify: `app/Filament/Admin/Resources/MailTemplates/MailTemplateResource.php` — `canEdit()`
- Modify: `app/Support/Mail/MailTemplateCatalog.php` — DemoReceived fixture label
- Modify: `app/Notifications/DemoRequestReceived.php` — organization type label
- Test: `tests/Unit/Support/Mail/MailTemplateRendererTest.php`
- Test: `tests/Feature/Mail/ComposeDatabaseMailTest.php`
- Test: `tests/Feature/Admin/MailTemplateResourceTest.php`

---

### Task 1: Stop double-escaping merge values in MailMessage

**Files:**
- Modify: `app/Support/Mail/MailTemplateRenderer.php`
- Modify: `app/Support/Mail/ComposeDatabaseMail.php`
- Test: `tests/Unit/Support/Mail/MailTemplateRendererTest.php`
- Test: `tests/Feature/Mail/ComposeDatabaseMailTest.php`

**Root cause (confirmed in tinker on 2026-08-16):**
`MailTemplateRenderer` runs `e()` on every merge value. `MailMessage` then echoes those strings through Laravel markdown mail (`{{ $line }}` / EncodedHtmlString). Rendered HTML contained `Smith &amp;amp; Jones &amp;lt;Pharma&amp;gt;` (user sees `Smith &amp; Jones &lt;Pharma&gt;`). Subject stored `Smith &amp; Jones &lt;Pharma&gt;` (clients show entities). Action href stored `&amp;` then Blade escaped again → `...mail&amp;amp;utm_medium=email`.

Do **not** keep `e()` and also “decode later.” Applicant fields are interpolated into markdown (`**{{ company_display_name }}**`). After dropping `e()`, markdown-escape values so `[phishing](https://evil.com)` stays literal text.

**Interfaces:**
- Consumes: current `render(string $template, array $variables): string`
- Produces: `render(string $template, array $variables, array $rawKeys = []): string` — `$rawKeys` skip markdown escaping (used for URL variables)

- [ ] **Step 1: Write the failing tests**

Add to `MailTemplateRendererTest`:

```php
#[Test]
public function it_markdown_escapes_applicant_values_instead_of_html_entities(): void
{
    $rendered = app(MailTemplateRenderer::class)->render(
        'Company: {{ company_display_name }}',
        ['company_display_name' => 'Smith & Jones [click](https://evil.test)'],
    );

    $this->assertSame('Company: Smith & Jones \\[click\\](https://evil.test)', $rendered);
    $this->assertStringNotContainsString('&amp;', $rendered);
}

#[Test]
public function it_leaves_raw_keys_unescaped_for_urls(): void
{
    $rendered = app(MailTemplateRenderer::class)->render(
        '{{ solution_url }}',
        ['solution_url' => 'https://tracepharma.io/x?a=1&b=2'],
        rawKeys: ['solution_url'],
    );

    $this->assertSame('https://tracepharma.io/x?a=1&b=2', $rendered);
}

#[Test]
public function it_strips_control_characters_from_values(): void
{
    $rendered = app(MailTemplateRenderer::class)->render(
        'Hi {{ first_name }}',
        ['first_name' => "Alex\r\nBcc: attacker@example.test"],
    );

    $this->assertStringNotContainsString("\r", $rendered);
    $this->assertStringNotContainsString("\n", $rendered);
    $this->assertStringContainsString('Alex', $rendered);
}
```

Replace `it_escapes_html_in_values` — that test encodes the **wrong** contract for `MailMessage`.

Add to `ComposeDatabaseMailTest`:

```php
#[Test]
public function rendered_html_escapes_ampersands_once_and_keeps_action_query_strings(): void
{
    $html = app(ComposeDatabaseMail::class)->mailMessage(
        MailTemplateCatalog::DemoAcknowledgment,
        [
            'first_name' => 'Alex',
            'company' => 'Smith & Jones',
            'email' => 'alex@example.test',
            'solution_label' => 'Pharmacies',
            'solution_url' => 'https://tracepharma.io/solutions/pharmacies?utm_source=mail&utm_medium=email',
        ],
    )->render()->toHtml();

    $this->assertStringContainsString('Smith &amp; Jones', $html);
    $this->assertStringNotContainsString('Smith &amp;amp; Jones', $html);

Also add a sibling case with `company` / `company_display_name` = `Smith & Jones <Pharma>`:
- HTML contains `Smith &amp; Jones &lt;Pharma&gt;` and not `&amp;amp;` / `&amp;lt;`
- `$mail->subject` is literal `Smith & Jones <Pharma>` (no `&amp;` / `&lt;`)

Non-`http(s)` action URLs (mutate `demo_request.acknowledgment` `action_url`, snapshot/restore):
- `javascript:alert(1)`, `data:text/html,x`, `/relative` → `actionText` and `actionUrl` empty
- `https://tracepharma.io/x` still sets the action
    $this->assertStringContainsString(
        'https://tracepharma.io/solutions/pharmacies?utm_source=mail&amp;utm_medium=email',
        $html,
    );
    $this->assertStringNotContainsString('&amp;amp;utm_medium', $html);
}

#[Test]
public function subject_is_plain_text_without_html_entities_or_newlines(): void
{
    $mail = app(ComposeDatabaseMail::class)->mailMessage(
        MailTemplateCatalog::OnboardingReceived,
        [
            'legal_company_name' => 'X',
            'company_display_name' => "Smith & Jones\r\nBcc: attacker@example.test",
            'contact_name' => 'Alex',
            'contact_email' => 'a@b.test',
            'contact_phone' => '1',
            'contact_role' => 'Owner',
            'organization_type' => 'Pharmacy',
            'gln' => '1',
            'message' => 'hi',
            'terms_version' => '1',
            'privacy_version' => '1',
        ],
    );

    $this->assertSame(
        'New TracePharma customer application — Smith & Jones Bcc: attacker@example.test',
        $mail->subject,
    );
    $this->assertStringNotContainsString("\n", $mail->subject);
    $this->assertStringNotContainsString('&amp;', $mail->subject);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Support/Mail/MailTemplateRendererTest.php tests/Feature/Mail/ComposeDatabaseMailTest.php`

Expected: FAIL — current renderer still `e()`s; HTML contains `&amp;amp;`; subject contains entities and `\r\n`.

- [ ] **Step 3: Minimal implementation**

`MailTemplateRenderer::render`:

1. Replace each `{{ key }}` with the string value (or `''` if missing/null).
2. Strip C0 controls (`\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`) and replace `\r` / `\n` with a space, then collapse spaces.
3. If `key` is **not** in `$rawKeys`, markdown-escape: prefix `\`, `` ` ``, `*`, `_`, `[`, `]`, `(`, `)` with `\`.
4. Never call `e()`.

`ComposeDatabaseMail::mailMessage`:

- Render `action_url` with `rawKeys: ['solution_url']` (and any future URL variable names used in V1 — today only `solution_url`).
- After render, if `$actionUrl` is non-empty and does not match `#^https?://#i`, set `$actionUrl = ''` so the button is omitted (`javascript:`, `data:`, relative junk).
- Subject uses the same renderer (newlines already stripped).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Unit/Support/Mail/MailTemplateRendererTest.php tests/Feature/Mail/ComposeDatabaseMailTest.php tests/Feature/Marketing/MarketingPagesTest.php`

Expected: PASS

- [ ] **Step 5: Commit** (only if the user asks)

---

### Task 2: Preview and test-send must use the form on screen

**Files:**
- Modify: `app/Support/Mail/ComposeDatabaseMail.php`
- Modify: `app/Filament/Admin/Resources/MailTemplates/Pages/EditMailTemplate.php`
- Test: `tests/Feature/Admin/MailTemplateResourceTest.php`

**Root cause:** `previewText()` and `MailTemplateTestSend` load the **saved** `mail_templates` row. An admin who edits the body and clicks Preview or Send test without saving sees/sends the old copy. That is the wrong contract for a mail editor.

**Interfaces:**
- Consumes: `previewPlainText(string $key, ?array $variables = null): string`
- Produces: `previewPlainText(string $key, ?array $variables = null, ?array $copy = null): string` and the same optional `$copy` on `mailMessage` / `preview`

`$copy` keys: `subject`, `greeting`, `body`, `salutation`, `action_label`, `action_url`. When present (including empty string), they win over the DB row and catalog default.

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function preview_uses_unsaved_form_copy_with_fixtures(): void
{
    $this->actAsAdmin(AdminRole::PlatformAdmin);

    $template = MailTemplate::query()
        ->where('key', MailTemplateCatalog::OnboardingAcknowledgment)
        ->firstOrFail();

    Livewire::test(EditMailTemplate::class, ['record' => $template->getKey()])
        ->fillForm([
            'subject' => 'Unsaved hello {{ company_display_name }}',
            'body' => 'Unsaved body for {{ first_name }}',
        ])
        ->mountAction(TestAction::make('preview'))
        ->assertActionMounted(TestAction::make('preview'))
        ->assertActionDataSet(function (array $data): bool {
            $preview = (string) ($data['preview_body'] ?? '');

            return str_contains($preview, 'Unsaved hello Example Pharmacy')
                && str_contains($preview, 'Unsaved body for Alex')
                && ! str_contains($preview, 'We received your TracePharma application');
        });
}
```

If `assertActionDataSet` does not accept a closure in this Filament version, compute the expected `preview_body` string with `previewPlainText(..., copy: [...])` and assert equality.

Also add:
- Same `fillForm` then `callAction(sendTest)`: `toMail()` subject/body contain the unsaved strings (and `[TEST] ` prefix), not the catalog sentence. Do not persist.
- `mailMessage(..., copy: ['greeting' => '', 'action_url' => ''])` on `DemoAcknowledgment`: greeting empty, no action button. Resolve copy with `array_key_exists` (or `??`). Never `?:` / `filled()` — those treat `''` as missing.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=preview_uses_unsaved_form_copy_with_fixtures`

Expected: FAIL — preview still shows saved catalog subject.

- [ ] **Step 3: Minimal implementation**

In `ComposeDatabaseMail::mailMessage`, resolve copy as:

```php
$subjectTemplate = array_key_exists('subject', $copy)
    ? $copy['subject']
    : ($row?->subject ?? $definition->defaultSubject);
```

(same for greeting/body/salutation/action_*).

In `EditMailTemplate`:

```php
private function formCopy(): array
{
    $state = $this->form->getRawState();

    return array_intersect_key($state, array_flip([
        'subject', 'greeting', 'body', 'salutation', 'action_label', 'action_url',
    ]));
}
```

Pass `$this->formCopy()` into `previewPlainText` and into `MailTemplateTestSend` (add an optional `array $copy = []` constructor arg; `toMail()` forwards it).

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Admin/MailTemplateResourceTest.php`

Expected: PASS, including existing fixture preview and test-send tests.

---

### Task 3: Unknown catalog key must not 500 the list

**Files:**
- Modify: `app/Support/Mail/MailTemplateCatalog.php`
- Modify: `app/Models/MailTemplate.php`
- Modify: `app/Filament/Admin/Resources/MailTemplates/Tables/MailTemplatesTable.php`
- Test: `tests/Feature/Admin/MailTemplateResourceTest.php`

**Root cause:** `MailTemplatesTable` calls `MailTemplateCatalog::get($state)` which throws `InvalidArgumentException`. A leftover/non-catalog row (manual insert, future V2 key before deploy) 500s Settings → Mail templates.

- [ ] **Step 1: Write the failing test**

Create a row with key `not.a.catalog.key` in setUp tracking, delete in tearDown.

```php
#[Test]
public function list_survives_an_unknown_catalog_key(): void
{
    $this->actAsAdmin(AdminRole::PlatformAdmin);

    $orphan = MailTemplate::query()->create([
        'key' => 'not.a.catalog.key',
        'subject' => 'Orphan',
        'greeting' => null,
        'body' => 'x',
        'is_active' => false,
    ]);

    Livewire::test(ListMailTemplates::class)
        ->assertSuccessful()
        ->assertSee('not.a.catalog.key');
}
```

Insert the orphan with `DB::table` (not Eloquent `create`) so Task 4’s fillable change cannot drop `key`. Track id and delete in `tearDown`.

Also:
- `Livewire::test(EditMailTemplate::class, ['record' => $orphanId])->assertSuccessful()` and assert the raw key is visible (form must not call throwing `get()`).
- `ComposeDatabaseMail::mailMessage('not.a.catalog.key', [])` still throws `InvalidArgumentException`. `MailTemplateCatalog::find('not.a.catalog.key')` is `null`.

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL with `Unknown mail template key: not.a.catalog.key`.

- [ ] **Step 3: Minimal implementation**

```php
public static function find(string $key): ?MailTemplateDefinition
{
    return self::definitions()[$key] ?? null;
}
```

Keep `get()` throwing for compose (unknown key is a programmer error).

Table column:

```php
->formatStateUsing(fn (string $state): string => MailTemplateCatalog::find($state)?->label ?? $state)
```

`MailTemplate::definition()` used by the edit form: if `find()` is null, show key as label and empty variable list — do not throw. Edit of an unknown key is not a V1 feature; listing must still work.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Admin/MailTemplateResourceTest.php`

Expected: PASS

---

### Task 4: Tight fillable, canEdit, demo ops label

**Files:**
- Modify: `app/Models/MailTemplate.php` — drop `key` and `recipients` from `$fillable`
- Modify: `app/Filament/Admin/Resources/MailTemplates/MailTemplateResource.php` — `canEdit()` and `canView()` same as `canViewAny()`
- Modify: `app/Filament/Admin/Resources/MailTemplates/Pages/EditMailTemplate.php` — `->authorize(fn () => MailTemplateResource::canViewAny())` on preview/sendTest
- Modify: `app/Notifications/DemoRequestReceived.php` — map `organization_type` through `OrganizationTypeMapper::options()` like onboarding received
- Test: `tests/Feature/Mail/ComposeDatabaseMailTest.php`
- Test: `tests/Feature/Admin/MailTemplateResourceTest.php` — Support `EditMailTemplate` forbidden; snapshot/restore catalog rows (no `RefreshDatabase`)

Do **not** rewrite `2026_08_16_160000_create_mail_templates_table` (already applied on central). Future seed paths should use `DB::table` inserts, not Eloquent in `up()`.

- [ ] **Step 1: Write the failing test**

```php
#[Test]
public function demo_received_mail_uses_organization_type_label(): void
{
    $request = new DemoRequest([
        'name' => 'Alex Rivera',
        'email' => 'alex@example-pharmacy.test',
        'company' => 'Example Pharmacy',
        'organization_type' => 'independent_pharmacy',
        'message' => 'Ready',
    ]);

    $mail = (new DemoRequestReceived($request))->toMail((object) []);

    $this->assertStringContainsString(
        'Independent pharmacy',
        implode("\n", $mail->introLines),
    );
    $this->assertStringNotContainsString('independent_pharmacy', implode("\n", $mail->introLines));
}
```

Confirm the exact `OrganizationTypeMapper` label string before asserting.

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — ops body still contains the raw slug.

- [ ] **Step 3: Minimal implementation**

`DemoRequestReceived` variables: `'organization_type' => OrganizationTypeMapper::options()[$request->organization_type] ?? $request->organization_type ?? '—'`.

`MailTemplate` fillable: remove `key` and `recipients`. `syncFromCatalog()` must set those via `forceFill()` or `query()->insert()` so create still works.

`MailTemplateResource::canEdit()` and `canView()`: return `static::canViewAny()`.

In `MailTemplateResourceTest`: `Livewire::test(EditMailTemplate::class, ['record' => $id])->assertForbidden()` as Support. Snapshot **catalog defaults** for all four keys in `setUp`; always write those back in `tearDown` via `forceFill`/`DB::table` (not `updateOrCreate`+`fill` after `key` leaves `$fillable`).

Also:
- Delete `demo_request.acknowledgment`, `syncFromCatalog()`, assert `key` and `recipients` (`['applicant']`) persist.
- Update `DemoReceived` fixtures to `Independent pharmacy`. `previewPlainText(DemoReceived)` must not contain `independent_pharmacy`.
- `MarketingPagesTest` setUp: pin the four catalog rows `is_active = true`, or assert faked `via()` contains `mail`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Mail/ComposeDatabaseMailTest.php tests/Feature/Admin/MailTemplateResourceTest.php`

Expected: PASS

---

### Task 5: Fail-open only for a missing table

**Files:**
- Modify: `app/Support/Mail/ComposeDatabaseMail.php` — `row()`
- Test: `tests/Feature/Mail/ComposeDatabaseMailTest.php`

**Root cause:** `catch (QueryException)` treats lock timeouts, lost connections, and bad SQL as “no row.” `shouldSend()` then returns true, so an inactive template can still send, and a customized row silently falls back to catalog copy with no log.

Missing-table fail-open (`42S02`) stays. Other `QueryException`s must rethrow.

- [ ] **Step 1: Write the failing test**

Use a mock or a subclass test double only if needed. Prefer asserting the SQLSTATE filter with a real `QueryException` constructed in the test, or a feature that calls `shouldSend` while the table exists (must not swallow a forced exception). Simplest: extract `isMissingTable(QueryException $e): bool` and unit-test it with `new QueryException('mysql', 'select', [], new \PDOException('...', '42S02'))` vs `'40001'`.

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — current `row()` has no SQLSTATE check.

- [ ] **Step 3: Minimal implementation**

```php
} catch (QueryException $exception) {
    if ($this->isMissingRelation($exception)) {
        return null;
    }

    throw $exception;
}
```

`isMissingRelation`: SQLSTATE `42S02` (unknown table) only. Log nothing extra on that path (existing fail-open). Do not catch `42S22` unless a later column rename needs it.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Mail/ComposeDatabaseMailTest.php tests/Feature/Marketing/MarketingPagesTest.php`

Expected: PASS — marketing pages still fail-open when the table is absent.

---

### Task 6: Inactive templates must skip already-queued jobs

**Files:**
- Modify: the four V1 notification classes
- Test: `tests/Feature/Mail/ComposeDatabaseMailTest.php`

**Root cause:** `QUEUE_CONNECTION=redis`. Laravel snapshots `via()` at queue time and `sendNow` uses those channels. `Notification::shouldSend()` is re-checked; none of the four classes implement it. Deactivate in admin does not stop in-flight jobs.

- [ ] **Step 1: Write the failing test**

Queue a `CustomerOnboardingAcknowledgment` (or call `shouldSend($notifiable, 'mail')` on the instance) after setting the template `is_active = false`. Assert `shouldSend` is false. With `Notification::fake()` this is the method Laravel will consult; also assert `toMail` is not required if `shouldSend` is false.

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — classes have no `shouldSend($notifiable, $channel)`.

- [ ] **Step 3: Minimal implementation**

On each of the four notifications:

```php
public function shouldSend(object $notifiable, string $channel): bool
{
    return app(ComposeDatabaseMail::class)->shouldSend(MailTemplateCatalog::OnboardingAcknowledgment);
}
```

(use that class’s catalog key). Do not change `toMail()`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/Mail/ComposeDatabaseMailTest.php tests/Feature/Marketing/MarketingPagesTest.php`

Expected: PASS

---

## Out of scope (looked at, not bugs to fix now)

- Missing-table fail-open (`42S02`) — required by the original spec; Task 5 only narrows the catch.
- Inactive `via() === []` at **dispatch** time — already correct; Task 6 covers the queued re-check.
- Filament From editor (still none). Demo mail uses `MAIL_FROM` (`noreply`); onboarding uses `tracepharma.onboarding_mail`. Catalog says “reply to this email.” Optional later: pass the same `$from` on demo notifications.
- Support cannot open the list — already covered; Task 4 adds the edit-page assertion.
- Admin-authored HTML/markdown in the **template** (not merge values) — Platform Admin is trusted; still no Blade.
- Recipients JSON is display-only in V1 — send path stays the existing Notification routes.
- Rewriting the already-applied mail_templates migration.
- Marketing confirmation-page copy tweaks (stage+prod hosts, per-user Terms) unless product asks.

## Self-review

- Spec coverage: double-escape, subject entities, broken query URLs, CR/LF in subject, stale preview, list 500, fillable/canEdit/canView, demo ops label, QueryException narrowing, queued `shouldSend` — each has a task.
- No TBD placeholders.
- Renderer tests that required `e()` are explicitly replaced so they do not fight MailMessage.

---

## Verification

After all tasks:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/Support/Mail/MailTemplateRendererTest.php tests/Feature/Mail/ComposeDatabaseMailTest.php tests/Feature/Admin/MailTemplateResourceTest.php tests/Feature/Marketing/MarketingPagesTest.php
```

Expected: all PASS. Then re-render in tinker: company `Smith & Jones <Pharma>` must appear as `Smith &amp; Jones &lt;Pharma&gt;` in HTML (once) and `Smith & Jones <Pharma>` in `MailMessage->subject`.
