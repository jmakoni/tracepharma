<?php

namespace Tests\Feature\Tenants;

use App\Actions\Tenants\DeleteTenantPair;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\TenantProfile;
use App\Notifications\TenantProvisionedOwner;
use App\Notifications\TenantProvisionedReceived;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use App\Support\TenantHostname;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantProvisionedMailTest extends TestCase
{
    /** @var list<string> */
    private array $slugs = [];

    protected function tearDown(): void
    {
        foreach ($this->slugs as $slug) {
            $prod = app(\App\Actions\Tenants\ProvisionTenantOnEnvironment::class)
                ->findBySlugAndEnvironment($slug, 'prod');

            if ($prod !== null) {
                app(DeleteTenantPair::class)->deleteWithSibling($prod);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function pair_create_emails_the_owner_and_ops_inbox(): void
    {
        Notification::fake();
        config(['tracepharma.marketing.onboarding_notify_email' => 'ops@tracepharma.test']);

        $slug = 'ssor-mail-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        app(ProvisionTenantPair::class)->create(
            $slug,
            [
                'name' => 'SSOR Mail '.$slug,
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
            ],
            [],
            [
                'name' => 'Pat Owner',
                'email' => $ownerEmail,
                'password' => 'password12',
            ],
        );

        $prodHost = TenantHostname::forSlug($slug, 'prod');
        $stageHost = TenantHostname::forSlug($slug, 'stage');

        Notification::assertSentOnDemand(
            TenantProvisionedOwner::class,
            function (TenantProvisionedOwner $notification, array $channels, object $notifiable) use ($ownerEmail, $prodHost, $stageHost): bool {
                if (($notifiable->routes['mail'] ?? null) !== $ownerEmail) {
                    return false;
                }

                $mail = $notification->toMail($notifiable);
                $body = implode("\n", $mail->introLines);

                return str_contains($body, $prodHost)
                    && str_contains($body, $stageHost)
                    && str_contains($body, $ownerEmail)
                    && ! str_contains($body, 'password12')
                    && $mail->actionUrl === 'https://'.$prodHost;
            },
        );

        Notification::assertSentOnDemand(
            TenantProvisionedReceived::class,
            fn (TenantProvisionedReceived $notification, array $channels, object $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'ops@tracepharma.test',
        );
    }

    #[Test]
    public function pair_create_without_an_owner_sends_no_provision_mail(): void
    {
        Notification::fake();
        config(['tracepharma.marketing.onboarding_notify_email' => 'ops@tracepharma.test']);

        $slug = 'ssor-nomail-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;

        app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'SSOR No Mail '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ]);

        Notification::assertNothingSent();
    }

    #[Test]
    public function owner_mail_is_sent_when_ops_inbox_is_unset(): void
    {
        Notification::fake();
        config([
            'tracepharma.marketing.onboarding_notify_email' => null,
            'tracepharma.marketing.demo_notify_email' => null,
        ]);

        $slug = 'ssor-owneronly-'.Str::lower(Str::random(6));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        app(ProvisionTenantPair::class)->create(
            $slug,
            [
                'name' => 'SSOR Owner Only '.$slug,
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
            ],
            [],
            [
                'name' => 'Pat Owner',
                'email' => $ownerEmail,
                'password' => 'password12',
            ],
        );

        Notification::assertSentOnDemand(TenantProvisionedOwner::class);
        Notification::assertSentOnDemandTimes(TenantProvisionedReceived::class, 0);
    }

    #[Test]
    public function catalog_preview_covers_the_owner_welcome(): void
    {
        $mail = app(ComposeDatabaseMail::class)->preview(MailTemplateCatalog::TenantProvisionedOwner);

        $this->assertSame('Your TracePharma workspace is ready', $mail->subject);
        $this->assertStringContainsString('example.prod.tracepharma.io', implode("\n", $mail->introLines));
    }
}
