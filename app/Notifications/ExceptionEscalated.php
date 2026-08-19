<?php

namespace App\Notifications;

use App\Models\Exceptions\ExceptionCase;
use App\Models\Tenant;
use App\Support\Exceptions\ExceptionEmailContextBuilder;
use App\Support\TenantAppUrl;
use App\Support\TenantNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExceptionEscalated extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    public function __construct(public readonly ExceptionCase $exception)
    {
        $this->channels = TenantNotificationSettings::forTenant(
            tenancy()->initialized ? tenant() : Tenant::query()->find(tenant('id')),
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
        $contextBuilder = app(ExceptionEmailContextBuilder::class);
        $context = $contextBuilder->build($this->exception);
        $reference = $context['case_reference'];

        $mail = (new MailMessage)
            ->subject('[Escalated] '.$contextBuilder->subject($context))
            ->line($this->exception->title)
            ->line('This case has been escalated and requires compliance review.')
            ->line('Case: '.$reference);

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
            ->line('DSCSA: '.$context['dscsa_section'])
            ->action('Review case', $this->reviewUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'exception_id' => $this->exception->id,
            'case_reference' => $this->exception->caseReference(),
            'title' => $this->exception->title,
            'escalated_at' => now()->toIso8601String(),
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
