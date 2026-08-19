<?php

namespace App\Notifications;

use App\Models\DemoRequest;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DemoRequestReceived extends Notification implements ShouldQueue
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
        return app(ComposeDatabaseMail::class)->shouldSend(MailTemplateCatalog::DemoReceived)
            ? ['mail']
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->demoRequest;

        return app(ComposeDatabaseMail::class)->mailMessage(
            MailTemplateCatalog::DemoReceived,
            [
                'name' => $request->name,
                'email' => $request->email,
                'company' => $request->company,
                'phone' => $request->phone ?? '—',
                'role' => $request->role ?? '—',
                'organization_type' => $request->organization_type ?? '—',
                'message' => $request->message ?? '—',
            ],
        );
    }
}
