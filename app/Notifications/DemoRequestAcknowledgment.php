<?php

namespace App\Notifications;

use App\Models\DemoRequest;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use App\Support\Marketing\DemoOrganizationSolutions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DemoRequestAcknowledgment extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly DemoRequest $demoRequest,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(ComposeDatabaseMail::class)->shouldSend(MailTemplateCatalog::DemoAcknowledgment)
            ? ['mail']
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->demoRequest;
        $firstName = str($request->name)->before(' ')->toString() ?: $request->name;

        return app(ComposeDatabaseMail::class)->mailMessage(
            MailTemplateCatalog::DemoAcknowledgment,
            [
                'first_name' => $firstName,
                'company' => $request->company,
                'email' => $request->email,
                'solution_label' => DemoOrganizationSolutions::label($request->organization_type) ?? '',
                'solution_url' => DemoOrganizationSolutions::url($request->organization_type) ?? '',
            ],
        );
    }
}
