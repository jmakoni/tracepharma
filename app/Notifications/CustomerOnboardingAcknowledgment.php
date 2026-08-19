<?php

namespace App\Notifications;

use App\Models\CustomerOnboarding;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerOnboardingAcknowledgment extends Notification implements ShouldQueue
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
        return app(ComposeDatabaseMail::class)->shouldSend(MailTemplateCatalog::OnboardingAcknowledgment)
            ? ['mail']
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $onboarding = $this->onboarding;
        $firstName = str($onboarding->contact_name)->before(' ')->toString() ?: $onboarding->contact_name;

        return app(ComposeDatabaseMail::class)->mailMessage(
            MailTemplateCatalog::OnboardingAcknowledgment,
            [
                'first_name' => $firstName,
                'company_display_name' => $onboarding->company_display_name,
                'contact_email' => $onboarding->contact_email,
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
