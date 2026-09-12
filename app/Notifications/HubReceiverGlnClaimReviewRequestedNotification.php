<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Admin;
use App\Models\HubReceiverGlnClaimRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts platform reviewers that a tenant's hub receiver GLN claim awaits approval.
 */
class HubReceiverGlnClaimReviewRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $tenantId,
        public readonly string $gln,
        public readonly string $provider,
        public readonly string $reason,
        public readonly string $requestedBy,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof Admin ? ['database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Hub receiver GLN claim review requested: {$this->gln}")
            ->line(sprintf(
                'Tenant [%s] requested provider [%s] claim receiver GLN [%s] for platform review.',
                $this->tenantId,
                $this->provider,
                $this->gln,
            ))
            ->line("Requested by: {$this->requestedBy}")
            ->line("Reason: {$this->reason}")
            ->action('Review tenant claim requests', $this->reviewUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $url = $this->reviewUrl();

        return [
            'subject' => "Hub receiver GLN claim review requested: {$this->gln}",
            'message' => sprintf(
                'Tenant [%s] provider [%s] GLN [%s] claim awaits review.',
                $this->tenantId,
                $this->provider,
                $this->gln,
            ),
            'request_id' => $this->requestId,
            'tenant_id' => $this->tenantId,
            'action_url' => $url,
            'action_path' => parse_url($url, PHP_URL_PATH) ?: '/tenants',
        ];
    }

    public static function fromRequest(HubReceiverGlnClaimRequest $request): self
    {
        return new self(
            (int) $request->getKey(),
            (string) $request->tenant_id,
            (string) $request->gln,
            (string) $request->provider,
            (string) $request->reason,
            (string) $request->requested_by,
        );
    }

    private function reviewUrl(): string
    {
        return TenantResource::getUrl(
            'edit',
            ['record' => $this->tenantId],
            panel: 'admin',
        );
    }
}
