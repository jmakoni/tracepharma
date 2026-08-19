<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Platform alert when tracepharma:scout-health detects Meilisearch or tenant index issues.
 */
class ScoutHealthAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $issues
     */
    public function __construct(private readonly array $issues) {}

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

        $mail = (new MailMessage)
            ->error()
            ->subject("[TracePharma:{$env}] Scout / Meilisearch health issues detected")
            ->line('The scheduled Scout health check found issues that need attention.');

        foreach ($this->issues as $issue) {
            $mail->line('• '.$issue);
        }

        return $mail->line('Run `php artisan tracepharma:scout-health` after repairing Meilisearch or tenant indexes.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'issues' => $this->issues,
        ];
    }
}
