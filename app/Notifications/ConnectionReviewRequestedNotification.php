<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\ConnectionRequests\ConnectionRequestResource;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts platform reviewers that a tenant connection awaits approval.
 * Sent to admins (database) and the platform support mailbox (mail).
 */
class ConnectionReviewRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $tenantId,
        public readonly string $connectionName,
        public readonly string $direction,
        public readonly ?string $counterparty,
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
            ->subject("Connection review requested: {$this->connectionName}")
            ->line(sprintf(
                'Tenant [%s] submitted a %s connection "%s"%s for platform review.',
                $this->tenantId,
                $this->direction,
                $this->connectionName,
                $this->counterparty !== null ? " with {$this->counterparty}" : '',
            ))
            ->action(
                'Review connection requests',
                ConnectionRequestResource::getUrl(panel: 'admin'),
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $url = ConnectionRequestResource::getUrl(panel: 'admin');

        return [
            'subject' => "Connection review requested: {$this->connectionName}",
            'message' => sprintf('Tenant [%s] %s connection awaits review.', $this->tenantId, $this->direction),
            'request_id' => $this->requestId,
            'action_url' => $url,
            'action_path' => parse_url($url, PHP_URL_PATH) ?: '/connection-requests',
        ];
    }

    public static function fromRequest(ConnectionApprovalRequest $request): self
    {
        return new self(
            (int) $request->getKey(),
            (string) $request->tenant_id,
            (string) $request->connection_name,
            (string) $request->direction,
            $request->counterparty,
        );
    }
}
