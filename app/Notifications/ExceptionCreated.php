<?php

namespace App\Notifications;

use App\Models\Exceptions\ExceptionCase;
use App\Models\Tenant;
use App\Support\Exceptions\ExceptionEmailContextBuilder;
use App\Support\TenantAppUrl;
use App\Support\TenantNotificationSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExceptionCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    /**
     * @param  list<string>|null  $channels
     */
    public function __construct(
        public readonly ExceptionCase $exception,
        ?array $channels = null,
    ) {
        $this->channels = $channels ?? TenantNotificationSettings::forTenant(
            tenancy()->initialized ? tenant() : Tenant::query()->find(tenant('id')),
        )['channels'];
    }

    public static function inAppOnly(ExceptionCase $exception): self
    {
        return new self($exception, ['database']);
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
        $contextBuilder = app(ExceptionEmailContextBuilder::class);
        $context = $contextBuilder->build($this->exception);

        $mail = (new MailMessage)
            ->subject($contextBuilder->subject($context))
            ->line($this->exception->description ?? 'A new compliance exception requires review.')
            ->line('Reason: '.$context['reason_label'])
            ->line('Case: '.$context['case_reference']);

        if (filled($context['partner_name'])) {
            $mail->line('Partner: '.$context['partner_name']);
        }

        if (filled($context['po_number'])) {
            $mail->line('PO: '.$context['po_number']);
        }

        if (filled($context['asn_number'])) {
            $mail->line('ASN: '.$context['asn_number']);
        }

        if (filled($context['sscc'])) {
            $mail->line('SSCC: '.$context['sscc']);
        }

        foreach ($context['receiver_actions'] as $action) {
            $mail->line($action);
        }

        return $mail
            ->line('DSCSA requirement affected: '.$context['dscsa_section'])
            ->action('Review Exception', $this->reviewUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $url = $this->reviewUrl();
        $body = $this->exception->description ?? 'A new compliance exception requires review.';

        return [
            ...FilamentNotification::make()
                ->title($this->exception->title)
                ->body($body)
                ->warning()
                ->actions([
                    Action::make('review')
                        ->label('Review Exception')
                        ->url($url)
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            'exception_id' => $this->exception->id,
            'exception_status' => $this->exception->status->value,
            'url' => $url,
        ];
    }

    private function reviewUrl(): string
    {
        return TenantAppUrl::exception(
            (int) $this->exception->id,
            tenancy()->initialized ? tenant() : null,
        );
    }
}
