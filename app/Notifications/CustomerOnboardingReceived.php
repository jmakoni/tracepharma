<?php

namespace App\Notifications;

use App\Models\CustomerOnboarding;
use App\Support\CustomerOnboarding\OrganizationTypeMapper;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerOnboardingReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly CustomerOnboarding $onboarding,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(ComposeDatabaseMail::class)->shouldSend(MailTemplateCatalog::OnboardingReceived)
            ? ['mail']
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $onboarding = $this->onboarding;
        $organizationLabel = OrganizationTypeMapper::options()[$onboarding->organization_type]
            ?? $onboarding->organization_type;

        return app(ComposeDatabaseMail::class)->mailMessage(
            MailTemplateCatalog::OnboardingReceived,
            [
                'legal_company_name' => $onboarding->legal_company_name,
                'company_display_name' => $onboarding->company_display_name,
                'contact_name' => $onboarding->contact_name,
                'contact_email' => $onboarding->contact_email,
                'contact_phone' => $onboarding->contact_phone ?? '—',
                'contact_role' => $onboarding->contact_role ?? '—',
                'organization_type' => $organizationLabel,
                'gln' => $onboarding->gln ?? '—',
                'message' => $onboarding->message ?? '—',
                'terms_version' => $onboarding->terms_version,
                'privacy_version' => $onboarding->privacy_version,
            ],
            $this->from(),
        );
    }

    /**
     * @return array{address: string, name: string}
     */
    private function from(): array
    {
        return [
            'address' => (string) config('tracepharma.onboarding_mail.from_address'),
            'name' => (string) config('tracepharma.onboarding_mail.from_name'),
        ];
    }
}
