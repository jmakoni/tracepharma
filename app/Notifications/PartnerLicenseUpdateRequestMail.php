<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks a trading partner to submit a current ATP license via a secure,
 * expiring signed link (LSPedia-style self-service document collection).
 */
class PartnerLicenseUpdateRequestMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $partnerName,
        public readonly string $requesterName,
        public readonly string $signedUrl,
        public readonly int $expiresInDays,
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
        return (new MailMessage)
            ->subject("License update requested by {$this->requesterName}")
            ->line("{$this->requesterName} needs a current ATP license on file for {$this->partnerName} to keep transactions compliant.")
            ->line('Use the secure link below to submit your license document and details. Submissions are reviewed before acceptance.')
            ->action('Submit license', $this->signedUrl)
            ->line("This link expires in {$this->expiresInDays} days.");
    }
}
