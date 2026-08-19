<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Exceptions\ExceptionCase;
use App\Support\Exceptions\ExceptionEmailContextBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformExceptionSupportAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ExceptionCase $exception,
        public readonly string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contextBuilder = app(ExceptionEmailContextBuilder::class);
        $context = $contextBuilder->build($this->exception);
        $env = (string) config('app.env');
        $tenantName = tenant('name') ?? tenant('id') ?? 'unknown';
        $tenantId = tenant('id') ?? '—';

        $mail = (new MailMessage)
            ->error()
            ->subject("[TracePharma Support:{$env}] {$this->reason} | {$tenantName} | {$context['case_reference']}")
            ->line('Platform alert — tenant compliance exception requires review.')
            ->line('Tenant: '.$tenantName.' ('.$tenantId.')')
            ->line('Case: '.$context['case_reference'])
            ->line('Reason: '.$context['reason_label'])
            ->line('DSCSA: '.$context['dscsa_section']);

        if (filled($context['partner_name'])) {
            $mail->line('Partner: '.$context['partner_name']);
        }

        $mail->line('Why platform was notified: '.$this->reason);

        return $mail->line('Review in tenant app: /exceptions/'.$this->exception->id);
    }
}
