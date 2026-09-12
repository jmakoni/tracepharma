<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ConnectionApprovalStatus;
use App\Models\Tenant;
use App\Support\TenantAppUrl;
use App\Support\TenantNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells tenant owners the outcome of a platform connection review
 * (approved / rejected / suspended / resumed). Must be created inside the
 * tenant context so tenant notification channel settings apply.
 */
class ConnectionReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    public function __construct(
        public readonly string $connectionName,
        public readonly string $direction,
        public readonly ConnectionApprovalStatus $decision,
        public readonly ?string $reviewNote,
        public readonly string $tenantId,
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
        $mail = (new MailMessage)
            ->subject("Connection {$this->decision->label()}: {$this->connectionName}")
            ->line(sprintf(
                'Your %s connection "%s" was %s by platform review.',
                $this->direction,
                $this->connectionName,
                strtolower($this->decision->label()),
            ));

        if ($this->reviewNote !== null && $this->reviewNote !== '') {
            $mail->line('Review note: '.$this->reviewNote);
        }

        $mail->line(match ($this->decision) {
            ConnectionApprovalStatus::Approved => 'The connection can now send and receive documents.',
            ConnectionApprovalStatus::Rejected => 'Edit the connection to address the note and resubmit it for review.',
            ConnectionApprovalStatus::Suspended => 'The connection cannot move documents until the platform resumes it. Contact support if you believe this is a mistake.',
            default => '',
        });

        return $mail->action(
            'Open connections',
            TenantAppUrl::forPath(
                $this->direction === 'inbound' ? '/inbound-connections' : '/outbound-connections',
                Tenant::query()->find($this->tenantId),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subject' => "Connection {$this->decision->label()}: {$this->connectionName}",
            'message' => $this->reviewNote,
            'direction' => $this->direction,
            'decision' => $this->decision->value,
            'action_path' => $this->direction === 'inbound' ? '/inbound-connections' : '/outbound-connections',
        ];
    }
}
