<?php

namespace App\Notifications;

use App\Support\Mail\ComposeDatabaseMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MailTemplateTestSend extends Notification
{
    public function __construct(
        public readonly string $key,
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
        $mail = app(ComposeDatabaseMail::class)->preview($this->key);
        $subject = $mail->subject ?? '';

        return $mail->subject('[TEST] '.$subject);
    }
}
