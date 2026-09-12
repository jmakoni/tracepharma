<?php

namespace App\Notifications;

use App\Models\Exceptions\ExceptionCase;
use App\Support\Exceptions\ExceptionEmailContextBuilder;
use App\Support\Exceptions\PdgExceptionNotificationPayload;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

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
        $case = $this->case->loadMissing(['type', 'tradingPartner', 'document', 'epcs.ilmd']);
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
            ->greeting('DSCSA Exception Notice (PDG-aligned)')
            ->line('**Notification UUID:** '.$context['notification_uuid'])
            ->line('**Receiving entity (buyer):** '.$tenantName)
            ->line('**Buyer / facility GLN:** '.($context['facility_gln'] !== '' ? $context['facility_gln'] : $tenantGln))
            ->line('**Ship-to GLN:** '.($context['ship_to_gln'] !== '' ? $context['ship_to_gln'] : '—'))
            ->line('**Supplier Name:** '.($partner?->name ?: ($context['partner_name'] !== '' ? $context['partner_name'] : '—')))
            ->line('**Supplier contact:** '.($context['partner_email'] !== '' ? $context['partner_email'] : '—')
                .(filled($context['partner_telephone']) ? ' · '.$context['partner_telephone'] : ''))
            ->line('**Issue type:** '.($context['issue_type_name'] !== '' ? $context['issue_type_name'] : $case->title)
                .(filled($context['issue_type_code']) ? ' ('.$context['issue_type_code'].')' : ''))
            ->line('**Issue:** '.$case->title)
            ->line($case->description ?? 'A DSCSA exception requires your correction.')
            ->line('**Severity:** '.($case->severity?->label() ?? '—'))
            ->line('**Buyer resolution request:** '.$context['resolution_request'])
            ->line('Case reference: '.$context['case_reference']);

        if (filled($context['hda_class'])) {
            $mail->line('**HDA class:** '.$context['hda_class']);
        }

        if (filled($context['po_number'])) {
            $mail->line('**PO:** '.$context['po_number']);
        }

        if (filled($context['asn_number'])) {
            $mail->line('**ASN:** '.$context['asn_number']);
        }

        if (filled($context['sscc'])) {
            $mail->line('**SSCC:** '.$context['sscc']);
        }

        if (filled($context['gtin'])) {
            $mail->line('**GTIN:** '.$context['gtin']);
        }

        if (filled($context['serial'])) {
            $mail->line('**Serial:** '.$context['serial']);
        }

        if (filled($context['lot'])) {
            $mail->line('**Lot:** '.$context['lot']);
        }

        if (filled($context['expiry'])) {
            $mail->line('**Expiry:** '.$context['expiry']);
        }

        $mail->line('DSCSA requirement affected: '.$context['dscsa_section']);
        $mail->line('Reply by email is not processed automatically — use the supplier exception portal to view status and upload corrections.');

        if ($this->ccEmails !== []) {
            $mail->cc($this->ccEmails);
        }

        try {
            $json = app(PdgExceptionNotificationPayload::class)->jsonForCase($case);
            $mail->attachData(
                $json,
                'pdg-exception-'.$context['case_reference'].'.json',
                ['mime' => 'application/json'],
            );
        } catch (Throwable) {
            // Mail still sends without attachment if payload build fails.
        }

        return $mail
            ->action('Open supplier exception portal', $this->portalUrl)
            ->line('Or paste this link: '.$this->portalUrl)
            ->salutation('TracePharma DSCSA Exceptions');
    }
}
