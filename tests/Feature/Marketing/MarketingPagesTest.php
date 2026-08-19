<?php

namespace Tests\Feature\Marketing;

use App\Models\CustomerOnboarding;
use App\Models\DemoRequest;
use App\Notifications\CustomerOnboardingAcknowledgment;
use App\Notifications\CustomerOnboardingReceived;
use App\Notifications\DemoRequestAcknowledgment;
use App\Notifications\DemoRequestReceived;
use App\Support\Marketing\TermsOfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        if (! Schema::hasTable('demo_requests')) {
            $this->artisan('migrate', [
                '--force' => true,
                '--path' => 'database/migrations/2026_08_12_210000_create_demo_requests_table.php',
            ])->assertSuccessful();
        }

        if (! Schema::hasTable('customer_onboardings')) {
            $this->artisan('migrate', [
                '--force' => true,
                '--path' => 'database/migrations/2026_08_12_210001_create_customer_onboardings_table.php',
            ])->assertSuccessful();
        }
    }

    #[Test]
    public function homepage_renders_on_central_domain(): void
    {
        $response = $this->get('http://localhost/');

        $response->assertOk();
        $response->assertSee('TracePharma');
        $response->assertSee('Request a demo');
        $response->assertSee('L4 DSCSA traceability for the US supply chain');
    }

    #[Test]
    public function features_page_renders(): void
    {
        $response = $this->get('http://localhost/features');

        $response->assertOk();
        $response->assertSee('TracePharma');
    }

    #[Test]
    public function legal_page_renders(): void
    {
        config(['tracepharma.app_version' => '1.0.0']);

        $response = $this->get('http://localhost/legal');

        $response->assertOk();
        $response->assertSee(TermsOfService::copyrightNotice(), false);
        $response->assertSee(TermsOfService::version(), false);
        $response->assertSee('1.0.0', false);
        $response->assertSee(route('marketing.tos'), false);
        $response->assertSee(route('marketing.privacy'), false);
    }

    #[Test]
    public function sitemap_returns_indexable_urls(): void
    {
        $response = $this->get('http://localhost/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/xml');
        $response->assertSee('<loc>'.route('marketing.home', absolute: true).'</loc>', false);
        $response->assertSee('<loc>'.route('marketing.pricing', absolute: true).'</loc>', false);
    }

    #[Test]
    public function demo_request_is_stored_and_acknowledged(): void
    {
        Notification::fake();

        $response = $this->post('http://localhost/demo', [
            'name' => 'Alex Pharmacist',
            'email' => 'alex@example-pharmacy.test',
            'company' => 'Example Pharmacy',
            'organization_type' => 'independent_pharmacy',
            'message' => 'Ready for a walkthrough.',
        ]);

        $response->assertRedirect('http://localhost/demo');
        $response->assertSessionHas('demo_submitted', true);

        $this->assertDatabaseHas('demo_requests', [
            'email' => 'alex@example-pharmacy.test',
            'company' => 'Example Pharmacy',
            'source' => 'demo',
        ]);

        Notification::assertSentOnDemand(
            DemoRequestAcknowledgment::class,
            fn (DemoRequestAcknowledgment $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'alex@example-pharmacy.test',
        );
    }

    #[Test]
    public function demo_request_notifies_staff_when_configured(): void
    {
        Notification::fake();

        config(['tracepharma.marketing.demo_notify_email' => 'sales@tracepharma.test']);

        $this->post('http://localhost/demo', [
            'name' => 'Alex Pharmacist',
            'email' => 'alex@example-pharmacy.test',
            'company' => 'Example Pharmacy',
        ]);

        Notification::assertSentOnDemand(DemoRequestReceived::class);
        $this->assertTrue(DemoRequest::query()->where('email', 'alex@example-pharmacy.test')->exists());
    }

    #[Test]
    public function get_started_submission_is_stored_with_legal_acceptance(): void
    {
        Notification::fake();

        $response = $this->post('http://localhost/get-started', [
            'legal_company_name' => 'Example Pharmacy LLC',
            'company_display_name' => 'Example Pharmacy',
            'contact_name' => 'Alex Pharmacist',
            'contact_email' => 'alex@example-pharmacy.test',
            'organization_type' => 'independent_pharmacy',
            'accept_terms' => '1',
            'accept_privacy' => '1',
        ]);

        $response->assertRedirect('http://localhost/get-started');
        $response->assertSessionHas('onboarding_submitted', true);

        $this->assertDatabaseHas('customer_onboardings', [
            'legal_company_name' => 'Example Pharmacy LLC',
            'contact_email' => 'alex@example-pharmacy.test',
            'status' => 'submitted',
            'terms_version' => TermsOfService::version(),
        ]);

        Notification::assertSentOnDemand(
            CustomerOnboardingAcknowledgment::class,
            fn (CustomerOnboardingAcknowledgment $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'alex@example-pharmacy.test',
        );
    }

    #[Test]
    public function get_started_sends_staff_notification_when_configured(): void
    {
        Notification::fake();

        config(['tracepharma.marketing.onboarding_notify_email' => 'sales@tracepharma.test']);

        $this->post('http://localhost/get-started', [
            'legal_company_name' => 'Example Pharmacy LLC',
            'company_display_name' => 'Example Pharmacy',
            'contact_name' => 'Alex Pharmacist',
            'contact_email' => 'alex@example-pharmacy.test',
            'organization_type' => 'independent_pharmacy',
            'accept_terms' => '1',
            'accept_privacy' => '1',
        ]);

        Notification::assertSentOnDemand(CustomerOnboardingReceived::class);
        $this->assertTrue(CustomerOnboarding::query()->where('contact_email', 'alex@example-pharmacy.test')->exists());
    }

    #[Test]
    public function get_started_requires_legal_acceptance(): void
    {
        $response = $this->from('http://localhost/get-started')->post('http://localhost/get-started', [
            'legal_company_name' => 'Example Pharmacy LLC',
            'company_display_name' => 'Example Pharmacy',
            'contact_name' => 'Alex Pharmacist',
            'contact_email' => 'alex@example-pharmacy.test',
            'organization_type' => 'independent_pharmacy',
        ]);

        $response->assertRedirect('http://localhost/get-started');
        $response->assertSessionHasErrors(['accept_terms', 'accept_privacy']);
    }

    #[Test]
    public function testing_treats_loopback_hosts_as_central_domains(): void
    {
        $this->assertContains('localhost', config('tenancy.central_domains'));
        $this->assertContains('127.0.0.1', config('tenancy.central_domains'));
    }

    #[Test]
    public function homepage_renders_on_loopback_ip(): void
    {
        $this->get('http://127.0.0.1/')->assertOk()->assertSee('TracePharma');
    }

    #[Test]
    public function demo_form_posts_to_the_current_host(): void
    {
        $response = $this->get('http://127.0.0.1/demo');

        $response->assertOk();
        $response->assertSee('action="http://127.0.0.1/demo"', false);
        $response->assertDontSee('action="http://localhost/demo"', false);
    }

    #[Test]
    public function marketing_vite_entry_imports_alpine(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertNotFalse($source);
        $this->assertNotSame('', trim($source));
        $this->assertStringContainsString('alpinejs', $source);
    }

    #[Test]
    public function homepage_keeps_alpine_nav_hooks(): void
    {
        $this->get('http://localhost/')->assertOk()->assertSee('x-data', false);
    }

    #[Test]
    public function demo_requests_are_throttled_per_ip(): void
    {
        Notification::fake();

        $payload = [
            'name' => 'Alex Pharmacist',
            'email' => 'alex@example-pharmacy.test',
            'company' => 'Example Pharmacy',
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->post('http://localhost/demo', $payload)->assertRedirect('http://localhost/demo');
        }

        $this->post('http://localhost/demo', $payload)->assertStatus(429);
    }

    #[Test]
    public function demo_user_agent_is_capped(): void
    {
        Notification::fake();

        $this->withHeaders(['User-Agent' => str_repeat('A', 2000)])
            ->post('http://localhost/demo', [
                'name' => 'Alex Pharmacist',
                'email' => 'alex-ua@example-pharmacy.test',
                'company' => 'Example Pharmacy',
            ])
            ->assertRedirect('http://localhost/demo');

        $stored = DemoRequest::query()->where('email', 'alex-ua@example-pharmacy.test')->value('user_agent');

        $this->assertIsString($stored);
        $this->assertLessThanOrEqual(512, strlen($stored));
    }
}
