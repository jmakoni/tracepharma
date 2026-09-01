<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes = 10,
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
            ->subject('Your client portal login code')
            ->greeting('Hello,')
            ->line('Use this one-time code to sign in to the client portal:')
            ->line($this->code)
            ->line('This code expires in '.$this->ttlMinutes.' minutes.')
            ->line('If you did not request this code, you can ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ttl_minutes' => $this->ttlMinutes,
        ];
    }
}
