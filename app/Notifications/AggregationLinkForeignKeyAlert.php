<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Platform alert when tracepharma:doctor-aggregation-link-fk detects CASCADE
 * on aggregation_links.established_by_event_id.
 */
class AggregationLinkForeignKeyAlert extends Notification
{
    use Queueable;

    /**
     * @param  list<array{tenant_id: string, tenant_name: string, constraint_name: string, delete_rule: string}>  $issues
     */
    public function __construct(private readonly array $issues) {}

    /**
     * @return list<array{tenant_id: string, tenant_name: string, constraint_name: string, delete_rule: string}>
     */
    public function issues(): array
    {
        return $this->issues;
    }

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
            ->subject("[TracePharma:{$env}] Aggregation link FK cascade drift detected")
            ->line('One or more tenant databases still use ON DELETE CASCADE on aggregation_links.established_by_event_id.');

        foreach ($this->issues as $issue) {
            $mail->line(sprintf(
                '• %s (%s): FK %s uses ON DELETE %s',
                $issue['tenant_name'],
                $issue['tenant_id'],
                $issue['constraint_name'],
                $issue['delete_rule'],
            ));
        }

        return $mail->line('Run `php artisan tracepharma:doctor-aggregation-link-fk --fix` after reviewing the affected tenants.');
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
