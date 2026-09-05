<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

/**
 * Builds a PDG Exception Notification Guideline–shaped payload for supplier email.
 * Notify-only: no reply parser / partner apply-fix.
 */
final class PdgExceptionNotificationPayload
{
    public function __construct(
        private readonly ExceptionEmailContextBuilder $contextBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCase(\App\Models\Exceptions\ExceptionCase $case): array
    {
        $case->loadMissing(['type', 'tradingPartner', 'document', 'epcs.ilmd']);
        $context = $this->contextBuilder->build($case);
        $partner = $case->tradingPartner;
        $document = $case->document;
        $tenantName = (string) (tenant('name') ?? config('app.name'));
        $tenantGln = (string) (tenant('gln') ?? '');

        $sgtins = $case->epcs
            ->filter(fn ($epc): bool => ($epc->epc_type ?? '') === 'sgtin')
            ->take(25)
            ->map(fn ($epc): array => array_filter([
                'gtin' => $epc->gtin14,
                'serial' => $epc->serial_number,
                'lot' => $epc->ilmd?->lot_number,
                'expiry' => $epc->ilmd?->expiry_date?->toDateString(),
            ], static fn ($value) => filled($value)))
            ->values()
            ->all();

        return [
            'guideline' => 'PDG Exception Notification Guideline v1 (notify only)',
            'notification_uuid' => $case->share_uuid ?: $context['case_reference'],
            'case_reference' => $context['case_reference'],
            'issue' => [
                'title' => $case->title,
                'description' => $case->description,
                'type_code' => $case->type?->code,
                'type_name' => $case->type?->name,
                'hda_class' => $case->type?->hda_class,
                'severity' => $case->severity?->value,
                'dscsa_section' => $context['dscsa_section'],
            ],
            'buyer' => [
                'name' => $tenantName,
                'gln' => $tenantGln,
                'ship_to_gln' => (string) ($document?->ship_to_gln ?? $tenantGln),
            ],
            'seller' => [
                'name' => (string) ($partner?->name ?? $context['partner_name']),
                'email' => (string) ($partner?->email ?? ''),
                'telephone' => (string) ($partner?->telephone ?? ''),
                'gln' => (string) ($partner?->gln ?? ''),
            ],
            'shipment' => array_filter([
                'po_number' => $context['po_number'] !== '' ? $context['po_number'] : null,
                'asn_number' => $context['asn_number'] !== '' ? $context['asn_number'] : null,
                'sscc' => $context['sscc'] !== '' ? $context['sscc'] : null,
                'sender_gln' => filled($document?->sender_gln) ? (string) $document->sender_gln : null,
                'receiver_gln' => filled($document?->receiver_gln) ? (string) $document->receiver_gln : null,
                'ship_from_gln' => filled($document?->ship_from_gln) ? (string) $document->ship_from_gln : null,
                'file_label' => $context['file_label'] !== '' ? $context['file_label'] : null,
            ], static fn ($value) => $value !== null),
            'products' => $sgtins,
            'buyer_resolution_request' => ['Send Corrected Data'],
            'receiver_actions' => $context['receiver_actions'],
            'compliance_hold' => $context['compliance_hold'],
        ];
    }

    public function jsonForCase(\App\Models\Exceptions\ExceptionCase $case): string
    {
        return json_encode(
            $this->forCase($case),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
