<?php

namespace App\Notifications;

use App\Models\Exceptions\ExceptionCase;
use App\Support\Exceptions\ExceptionEmailContextBuilder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DscsaExceptionSupplierMail extends Notification
{
    public function __construct(
        public readonly ExceptionCase $case,
        public readonly string $portalUrl,
        /** @var list<string> */
        public readonly array $ccEmails = [],
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
        $case = $this->case->loadMissing(['type', 'tradingPartner', 'document', 'epcs']);
        $contextBuilder = app(ExceptionEmailContextBuilder::class);
        $context = $contextBuilder->build($case);
        $tenantName = (string) (tenant('name') ?? config('app.name'));
        $tenantGln = (string) (tenant('gln') ?? '—');
        $partner = $case->tradingPartner;

        $mail = (new MailMessage)
            ->from(
                config('tracepharma.exception_mail.from_address'),
                config('tracepharma.exception_mail.from_name'),
            )
            ->subject('DSCSA Correction Required | '.$contextBuilder->subject($context))
            ->greeting('DSCSA Exception Notice')
            ->line('**Receiving entity (customer):** '.$tenantName)
            ->line('**Receiving entity GLN:** '.$tenantGln)
            ->line('**Supplier Name:** '.($partner?->name ?: ($context['partner_name'] !== '' ? $context['partner_name'] : '—')))
            ->line('**Issue:** '.$case->title)
            ->line($case->description ?? 'A DSCSA exception requires your correction.')
            ->line('**Severity:** '.($case->severity?->label() ?? '—'))
            ->line('Case reference: '.$context['case_reference']);

        if (filled($context['po_number'])) {
            $mail->line('**PO:** '.$context['po_number']);
        }

        if (filled($context['asn_number'])) {
            $mail->line('**ASN:** '.$context['asn_number']);
        }

        if (filled($context['sscc'])) {
            $mail->line('**SSCC:** '.$context['sscc']);
        }

        $mail->line('DSCSA requirement affected: '.$context['dscsa_section']);

        if ($this->ccEmails !== []) {
            $mail->cc($this->ccEmails);
        }

        return $mail
            ->action('Open supplier exception portal', $this->portalUrl)
            ->line('Or paste this link: '.$this->portalUrl)
            ->salutation('TracePharma DSCSA Exceptions');
    }
}
