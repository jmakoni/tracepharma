<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\App\Pages\OrganizationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SsccPoolLowWaterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $pools
     */
    public function __construct(
        public readonly array $pools,
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
        $count = count($this->pools);
        $first = $this->pools[0] ?? [];

        $message = (new MailMessage)
            ->subject('SSCC serial pool low-water alert')
            ->line("{$count} SSCC pool(s) are below the configured low-water mark.")
            ->line('Prefix '.($first['company_prefix'] ?? 'n/a').' has '.($first['remaining_serials'] ?? 0).' serials remaining.');

        try {
            $message->action('Review organization settings', OrganizationSettings::getUrl(panel: 'app'));
        } catch (\Throwable) {
            // Queue workers may lack Filament/panel context for URL generation.
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'pool_count' => count($this->pools),
            'pools' => $this->pools,
        ];
    }
}
