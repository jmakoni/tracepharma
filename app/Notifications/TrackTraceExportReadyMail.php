<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DataExport;
use App\Models\Tenant;
use App\Support\TenantNotificationSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrackTraceExportReadyMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    public function __construct(
        public readonly DataExport $export,
        public readonly string $tenantId,
        ?array $channels = null,
    ) {
        $this->channels = $channels ?? TenantNotificationSettings::forTenant(
            Tenant::query()->find($tenantId),
        )['channels'];
    }

    public static function mailOnly(DataExport $export, string $tenantId): self
    {
        return new self($export, $tenantId, ['mail']);
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
        $downloadUrl = $this->resolveDownloadUrl();

        $tenantName = (string) (tenant('name') ?? config('app.name'));
        $rowCount = number_format((int) ($this->export->row_count ?? 0));
        $urlTtlMinutes = max(5, (int) config('tracepharma.exports.url_ttl_minutes', 60));
        $expiresAt = $this->export->expires_at?->timezone((string) config('app.timezone'))->toDayDateTimeString()
            ?? 'soon';

        $mail = (new MailMessage)
            ->from(
                config('tracepharma.exception_mail.from_address'),
                config('tracepharma.exception_mail.from_name'),
            )
            ->subject('Track-and-trace export ready | '.$tenantName)
            ->greeting('Export ready')
            ->line('Your Serialized Track & Trace (DSCSA Compliance Report) PDF is ready to download.')
            ->line('**Serialized units:** '.$rowCount)
            ->line('**Download link valid for:** '.$urlTtlMinutes.' minutes')
            ->line('**File removed after:** '.$expiresAt);

        if ($downloadUrl !== null) {
            $mail->action('Download export', $downloadUrl);
        }

        return $mail
            ->line('You can also open the notification bell in TracePharma for the same download link.')
            ->salutation('TracePharma Exports');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $downloadUrl = $this->resolveDownloadUrl();
        $rowCount = number_format((int) ($this->export->row_count ?? 0));

        $notification = FilamentNotification::make()
            ->title('Track & trace export ready')
            ->body("Your Serialized Track & Trace PDF is ready ({$rowCount} serialized units).")
            ->success();

        if ($downloadUrl !== null) {
            $notification->actions([
                Action::make('download')
                    ->label('Download export')
                    ->url($downloadUrl)
                    ->markAsRead(),
            ]);
        }

        return [
            ...$notification->getDatabaseMessage(),
            'export_id' => (string) $this->export->getKey(),
            'url' => $downloadUrl,
        ];
    }

    private function resolveDownloadUrl(): ?string
    {
        if (tenancy()->initialized && (string) tenant('id') === $this->tenantId) {
            $export = DataExport::query()->find($this->export->getKey());

            return $export?->temporaryDownloadUrl(tenantId: $this->tenantId);
        }

        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return null;
        }

        return $tenant->run(function (): ?string {
            $export = DataExport::query()->find($this->export->getKey());

            return $export?->temporaryDownloadUrl(tenantId: $this->tenantId);
        });
    }
}
