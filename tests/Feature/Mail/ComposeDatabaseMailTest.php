<?php

namespace Tests\Feature\Mail;

use App\Models\CustomerOnboarding;
use App\Models\DemoRequest;
use App\Models\MailTemplate;
use App\Notifications\CustomerOnboardingAcknowledgment;
use App\Notifications\DemoRequestAcknowledgment;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComposeDatabaseMailTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $originalRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMailTemplatesTable();
        MailTemplate::syncFromCatalog();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalRows as $key => $attributes) {
            MailTemplate::query()->updateOrCreate(['key' => $key], $attributes);
        }

        parent::tearDown();
    }

    #[Test]
    public function preview_uses_catalog_fixtures(): void
    {
        $mail = app(ComposeDatabaseMail::class)->preview(MailTemplateCatalog::OnboardingAcknowledgment);

        $this->assertSame('We received your TracePharma application', $mail->subject);
        $this->assertSame('Hi Alex,', $mail->greeting);
        $this->assertStringContainsString('Example Pharmacy', implode("\n", $mail->introLines));
        $this->assertStringContainsString('alex@example-pharmacy.test', implode("\n", $mail->introLines));
    }

    #[Test]
    public function database_subject_overrides_catalog_defaults(): void
    {
        $this->mutateTemplate(MailTemplateCatalog::OnboardingAcknowledgment, [
            'subject' => 'Custom hello {{ company_display_name }}',
        ]);

        $onboarding = new CustomerOnboarding([
            'contact_name' => 'Alex Rivera',
            'company_display_name' => 'Example Pharmacy',
            'contact_email' => 'alex@example-pharmacy.test',
        ]);

        $mail = (new CustomerOnboardingAcknowledgment($onboarding))->toMail((object) []);

        $this->assertSame('Custom hello Example Pharmacy', $mail->subject);
    }

    #[Test]
    public function missing_row_falls_open_to_catalog_copy(): void
    {
        $this->snapshot(MailTemplateCatalog::OnboardingAcknowledgment);
        MailTemplate::query()->where('key', MailTemplateCatalog::OnboardingAcknowledgment)->delete();

        $onboarding = new CustomerOnboarding([
            'contact_name' => 'Alex Rivera',
            'company_display_name' => 'Example Pharmacy',
            'contact_email' => 'alex@example-pharmacy.test',
        ]);

        $notification = new CustomerOnboardingAcknowledgment($onboarding);

        $this->assertSame(['mail'], $notification->via((object) []));
        $this->assertSame(
            'We received your TracePharma application',
            $notification->toMail((object) [])->subject,
        );
    }

    #[Test]
    public function inactive_template_does_not_send(): void
    {
        $this->mutateTemplate(MailTemplateCatalog::DemoAcknowledgment, [
            'is_active' => false,
        ]);

        $request = new DemoRequest([
            'name' => 'Alex Rivera',
            'company' => 'Example Pharmacy',
            'email' => 'alex@example-pharmacy.test',
            'organization_type' => 'independent_pharmacy',
        ]);

        $notification = new DemoRequestAcknowledgment($request);

        $this->assertSame([], $notification->via((object) []));
        $this->assertFalse(app(ComposeDatabaseMail::class)->shouldSend(MailTemplateCatalog::DemoAcknowledgment));
    }

    #[Test]
    public function sync_from_catalog_does_not_overwrite_edited_copy(): void
    {
        $this->mutateTemplate(MailTemplateCatalog::OnboardingReceived, [
            'subject' => 'Edited ops subject',
            'body' => 'Edited ops body',
        ]);

        MailTemplate::syncFromCatalog();

        $row = MailTemplate::query()->where('key', MailTemplateCatalog::OnboardingReceived)->firstOrFail();

        $this->assertSame('Edited ops subject', $row->subject);
        $this->assertSame('Edited ops body', $row->body);
    }

    private function ensureMailTemplatesTable(): void
    {
        if (Schema::hasTable('mail_templates')) {
            return;
        }

        $this->artisan('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_08_16_160000_create_mail_templates_table.php',
        ])->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function mutateTemplate(string $key, array $attributes): void
    {
        $this->snapshot($key);
        MailTemplate::query()->where('key', $key)->update($attributes);
    }

    private function snapshot(string $key): void
    {
        if (isset($this->originalRows[$key])) {
            return;
        }

        $row = MailTemplate::query()->where('key', $key)->first();

        if ($row instanceof MailTemplate) {
            $this->originalRows[$key] = $row->only([
                'subject',
                'greeting',
                'body',
                'salutation',
                'action_label',
                'action_url',
                'recipients',
                'is_active',
            ]);

            return;
        }

        $definition = MailTemplateCatalog::get($key);
        $this->originalRows[$key] = [
            'subject' => $definition->defaultSubject,
            'greeting' => $definition->defaultGreeting,
            'body' => $definition->defaultBody,
            'salutation' => $definition->defaultSalutation,
            'action_label' => $definition->defaultActionLabel,
            'action_url' => $definition->defaultActionUrl,
            'recipients' => $definition->recipients,
            'is_active' => true,
        ];
    }
}
