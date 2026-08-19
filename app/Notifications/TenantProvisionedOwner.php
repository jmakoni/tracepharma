<?php

namespace App\Notifications;

use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantProvisionedOwner extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function __construct(
        public readonly array $variables,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(ComposeDatabaseMail::class)->shouldSend(MailTemplateCatalog::TenantProvisionedOwner)
            ? ['mail']
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(ComposeDatabaseMail::class)->mailMessage(
            MailTemplateCatalog::TenantProvisionedOwner,
            $this->variables,
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
