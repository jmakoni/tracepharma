<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Models\Tenant;
use App\Support\TenantAppUrl;
use App\Support\TenantNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HubReceiverGlnClaimReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    public function __construct(
        public readonly string $gln,
        public readonly string $provider,
        public readonly HubReceiverGlnClaimRequestStatus $decision,
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
            ->subject("Receiver GLN claim {$this->decision->label()}: {$this->gln}")
            ->line(sprintf(
                'Your receiver GLN [%s] claim for provider [%s] was %s by platform review.',
                $this->gln,
                $this->provider,
                strtolower($this->decision->label()),
            ));

        if ($this->reviewNote !== null && $this->reviewNote !== '') {
            $mail->line('Review note: '.$this->reviewNote);
        }

        $mail->line(match ($this->decision) {
            HubReceiverGlnClaimRequestStatus::Approved => 'The hub can now route documents for this receiver GLN to your tenant.',
            HubReceiverGlnClaimRequestStatus::Rejected => 'Address the review note, then submit the receiver GLN claim again.',
            default => '',
        });

        return $mail->action(
            'Open integration health',
            TenantAppUrl::forPath(
                '/integration-health',
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
            'subject' => "Receiver GLN claim {$this->decision->label()}: {$this->gln}",
            'message' => $this->reviewNote,
            'gln' => $this->gln,
            'provider' => $this->provider,
            'decision' => $this->decision->value,
            'action_path' => '/integration-health',
        ];
    }
}
