<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SsccRangeLowThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $ranges
     */
    public function __construct(
        public readonly array $ranges,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->ranges);
        $first = $this->ranges[0] ?? [];

        $message = (new MailMessage)
            ->subject('SSCC number range threshold alert')
            ->line("{$count} SSCC number range(s) have reached the configured utilization threshold.")
            ->line(
                'Range '.($first['name'] ?? 'n/a')
                .' ('.($first['owner'] ?? 'n/a').') is '
                .($first['utilization_percentage'] ?? 0).'% utilized with '
                .($first['remaining'] ?? 0).' serials remaining.'
            );

        if (! empty($first['has_gs1_api_key'])) {
            $message->line('A GS1 API key is configured for replenishment (external call not enabled in this release).');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'range_count' => count($this->ranges),
            'ranges' => $this->ranges,
        ];
    }
}
