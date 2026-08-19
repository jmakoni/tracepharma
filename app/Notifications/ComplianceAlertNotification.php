<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Support\TenantAppUrl;
use App\Support\TenantNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ComplianceAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    public function __construct(
        public readonly string $subject,
        public readonly string $message,
        public readonly string $actionPath,
        public readonly ?string $tenantId = null,
        public readonly ?int $totalCount = null,
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
        $lines = array_values(array_filter(
            preg_split("/\r\n|\n|\r/", $this->message) ?: [],
            fn (string $line): bool => $line !== '',
        ));
        $shown = array_slice($lines, 0, 10);
        $total = $this->totalCount ?? count($lines);

        $mail = (new MailMessage)->subject($this->subject);

        foreach ($shown as $line) {
            $mail->line($line);
        }

        if ($total > 10) {
            $mail->line(sprintf('…and %d more.', $total - 10));
        }

        return $mail->action(
            'Review',
            TenantAppUrl::forPath(
                $this->actionPath,
                $this->tenantId !== null ? Tenant::query()->find($this->tenantId) : null,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subject' => $this->subject,
            'message' => $this->message,
            'action_path' => $this->actionPath,
        ];
    }
}
