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
 * Tells pharmacy Owners that a buying group invited them to hard-link.
 * Must be created inside the member tenant context so channel settings apply.
 */
class BuyingGroupMembershipInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    public function __construct(
        public readonly string $buyingGroupName,
        public readonly string $buyingGroupTenantId,
        public readonly int $membershipId,
        public readonly string $memberTenantId,
    ) {
        $this->channels = TenantNotificationSettings::forCurrentTenant()['channels'];
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
        return (new MailMessage)
            ->subject("Buying group invite: {$this->buyingGroupName}")
            ->line(sprintf(
                '%s invited your pharmacy to join their TracePharma buying-group network.',
                $this->buyingGroupName,
            ))
            ->line('Accepting lets the buying group link your tenant for network readiness rollups. You can revoke consent later from Organization settings.')
            ->action(
                'Review invite',
                TenantAppUrl::forPath(
                    '/organization-settings',
                    Tenant::query()->find($this->memberTenantId),
                ),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subject' => "Buying group invite: {$this->buyingGroupName}",
            'message' => sprintf(
                '%s invited your pharmacy to join their TracePharma buying-group network.',
                $this->buyingGroupName,
            ),
            'buying_group_tenant_id' => $this->buyingGroupTenantId,
            'membership_id' => $this->membershipId,
            'action_path' => '/organization-settings',
        ];
    }
}
