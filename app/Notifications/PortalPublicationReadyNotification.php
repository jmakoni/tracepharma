<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalPublicationReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantLabel,
        public readonly string $loginUrl,
        public readonly ?string $asnNumber = null,
        public readonly ?string $customerPo = null,
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
        $mail = (new MailMessage)
            ->subject('Transaction information available in the client portal')
            ->greeting('Hello,')
            ->line($this->tenantLabel.' has published transaction information (TI) you can view in the TracePharma client portal.')
            ->line('Sign in with a one-time email code — no attachment is included in this message.');

        if (filled($this->asnNumber)) {
            $mail->line('ASN: '.$this->asnNumber);
        }

        if (filled($this->customerPo)) {
            $mail->line('PO: '.$this->customerPo);
        }

        return $mail
            ->action('Open client portal', $this->loginUrl)
            ->line('If you were not expecting this, contact your trading partner.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_label' => $this->tenantLabel,
            'login_url' => $this->loginUrl,
            'asn_number' => $this->asnNumber,
            'customer_po' => $this->customerPo,
        ];
    }
}
