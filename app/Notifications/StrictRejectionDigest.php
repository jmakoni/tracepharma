<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\TenantAppUrl;
use App\Support\TenantNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Periodic rollup sent to tenant owners summarising open EPCIS validation
 * cases still awaiting review.
 */
class StrictRejectionDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    /**
     * @param  list<array{id:int,title:string,created_at:?string}>  $exceptions
     * @param  list<string>|null  $channels
     */
    public function __construct(
        public readonly int $openCount,
        public readonly array $exceptions,
        public readonly ?string $tenantId = null,
        ?array $channels = null,
    ) {
        $this->channels = $channels ?? TenantNotificationSettings::forTenant(
            tenancy()->initialized ? tenant() : ($tenantId !== null ? Tenant::query()->find($tenantId) : null),
        )['channels'];
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels !== [] ? $this->channels : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(sprintf('%d EPCIS validation case(s) awaiting review', $this->openCount))
            ->line('The following inbound files failed EPCIS validation and still need review:');

        foreach (array_slice($this->exceptions, 0, 10) as $exception) {
            $mail->line('• '.$exception['title']);
        }

        if ($this->openCount > 10) {
            $mail->line(sprintf('…and %d more.', $this->openCount - 10));
        }

        return $mail->action('Review exceptions', TenantAppUrl::forPath(
            '/exceptions',
            $this->tenantId !== null ? Tenant::query()->find($this->tenantId) : null,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'strict_rejection_digest',
            'open_count' => $this->openCount,
            'exception_ids' => array_column($this->exceptions, 'id'),
            'title' => sprintf('%d EPCIS validation case(s) awaiting review', $this->openCount),
        ];
    }
}
