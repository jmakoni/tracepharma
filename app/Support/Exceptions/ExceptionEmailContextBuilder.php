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
     *     reason_label: string,
     *     po_number: string,
     *     asn_number: string,
     *     sscc: string,
     *     partner_name: string,
     *     facility_gln: string,
     *     file_label: string,
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

        return [
            'case_reference' => $caseReference,
            'reason_label' => $reasonLabel,
            'po_number' => (string) ($document?->customer_po ?? ''),
            'asn_number' => (string) ($document?->asn_number ?? ''),
            'sscc' => $this->ssccFromCase($exception),
            'partner_name' => (string) ($exception->tradingPartner?->name ?? ''),
            'facility_gln' => (string) (tenant('gln') ?? ''),
            'file_label' => $document?->original_filename
                ?? $document?->document_uuid
                ?? '',
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
}
