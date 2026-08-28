<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Enums\ExceptionSeverity;
use App\Models\Exceptions\ExceptionCase;

final class ExceptionEmailContextBuilder
{
    /**
     * @return array{
     *     case_reference: string,
     *     notification_uuid: string,
     *     reason_label: string,
     *     issue_type_code: string,
     *     issue_type_name: string,
     *     hda_class: string,
     *     po_number: string,
     *     asn_number: string,
     *     sscc: string,
     *     ship_to_gln: string,
     *     partner_name: string,
     *     partner_email: string,
     *     partner_telephone: string,
     *     facility_gln: string,
     *     file_label: string,
     *     gtin: string,
     *     serial: string,
     *     lot: string,
     *     expiry: string,
     *     resolution_request: string,
     *     dscsa_section: string,
     *     receiver_actions: list<string>,
     *     compliance_hold: bool,
     *     subject_prefix: string
     * }
     */
    public function build(ExceptionCase $exception): array
    {
        $exception->loadMissing(['type', 'tradingPartner', 'document']);

        $caseReference = $exception->caseReference();
        $reasonLabel = $exception->type?->name ?? $exception->title;
        $typeCode = $exception->type?->code;
        $complianceHold = $exception->severity === ExceptionSeverity::Critical
            || ($exception->type?->blocksReceiving() ?? false);

        $document = $exception->document;
        $product = $this->primarySgtinFields($exception);
        $tenantGln = (string) (tenant('gln') ?? '');

        return [
            'case_reference' => $caseReference,
            'notification_uuid' => (string) ($exception->share_uuid ?: $caseReference),
            'reason_label' => $reasonLabel,
            'issue_type_code' => (string) ($typeCode ?? ''),
            'issue_type_name' => (string) ($exception->type?->name ?? ''),
            'hda_class' => (string) ($exception->type?->hda_class ?? ''),
            'po_number' => (string) ($document?->customer_po ?? ''),
            'asn_number' => (string) ($document?->asn_number ?? ''),
            'sscc' => $this->ssccFromCase($exception),
            'ship_to_gln' => (string) ($document?->ship_to_gln ?: $tenantGln),
            'partner_name' => (string) ($exception->tradingPartner?->name ?? ''),
            'partner_email' => (string) ($exception->tradingPartner?->email ?? ''),
            'partner_telephone' => (string) ($exception->tradingPartner?->telephone ?? ''),
            'facility_gln' => $tenantGln,
            'file_label' => $document?->original_filename
                ?? $document?->document_uuid
                ?? '',
            'gtin' => $product['gtin'],
            'serial' => $product['serial'],
            'lot' => $product['lot'],
            'expiry' => $product['expiry'],
            'resolution_request' => 'Send Corrected Data',
            'dscsa_section' => DscsaSectionReference::label($typeCode),
            'receiver_actions' => DscsaSectionReference::receiverActions($typeCode, $complianceHold),
            'compliance_hold' => $complianceHold,
            'subject_prefix' => $complianceHold ? '[Compliance Hold]' : '[Compliance Exception]',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function subject(array $context): string
    {
        $parts = array_filter([
            $context['subject_prefix'] ?? '[Compliance Exception]',
            $context['reason_label'] ?? null,
            filled($context['po_number'] ?? null) ? 'PO '.$context['po_number'] : null,
            filled($context['asn_number'] ?? null) ? 'ASN '.$context['asn_number'] : null,
            filled($context['sscc'] ?? null) ? 'SSCC '.$context['sscc'] : null,
            $context['case_reference'] ?? null,
        ]);

        return implode(' | ', $parts);
    }

    private function ssccFromCase(ExceptionCase $exception): string
    {
        $fromCase = $exception->epcs()
            ->where('epcs.epc_type', 'sscc')
            ->whereNotNull('epcs.sscc18')
            ->where('epcs.sscc18', '!=', '')
            ->value('epcs.sscc18');

        if (filled($fromCase)) {
            return (string) $fromCase;
        }

        $document = $exception->document;
        if ($document === null) {
            return '';
        }

        return (string) ($document->epcsQuery()
            ->where('epcs.epc_type', 'sscc')
            ->whereNotNull('epcs.sscc18')
            ->where('epcs.sscc18', '!=', '')
            ->value('epcs.sscc18') ?? '');
    }

    /**
     * @return array{gtin: string, serial: string, lot: string, expiry: string}
     */
    private function primarySgtinFields(ExceptionCase $exception): array
    {
        if (! $exception->exists) {
            return ['gtin' => '', 'serial' => '', 'lot' => '', 'expiry' => ''];
        }

        // Query via pivot without loading the epcs relation on the case (list-mail N+1 guard).
        $epc = \App\Models\Epcis\Epc::query()
            ->where('epcs.epc_type', 'sgtin')
            ->whereIn('epcs.id', function ($query) use ($exception): void {
                $query->select('epc_id')
                    ->from('exception_epcs')
                    ->where('exception_id', $exception->getKey());
            })
            ->with('ilmd')
            ->orderBy('epcs.id')
            ->first();

        if ($epc === null) {
            return ['gtin' => '', 'serial' => '', 'lot' => '', 'expiry' => ''];
        }

        return [
            'gtin' => (string) ($epc->gtin14 ?? ''),
            'serial' => (string) ($epc->serial_number ?? ''),
            'lot' => (string) ($epc->ilmd?->lot_number ?? ''),
            'expiry' => (string) ($epc->ilmd?->expiry_date?->toDateString() ?? ''),
        ];
    }
}
