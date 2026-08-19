<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EpcisJobFailedPlatformAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $documentId,
        public readonly string $errorMessage,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $env = (string) config('app.env');

        return (new MailMessage)
            ->error()
            ->subject("[TracePharma:{$env}] EPCIS job failed | tenant {$this->tenantId} | document #{$this->documentId}")
            ->line('EPCIS processing failed permanently after maximum retries.')
            ->line('Tenant ID: '.$this->tenantId)
            ->line('Document ID: '.$this->documentId)
            ->line('Error: '.$this->errorMessage);
    }
}
