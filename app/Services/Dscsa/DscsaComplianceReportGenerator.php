<?php

namespace App\Services\Dscsa;

use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\ComplianceReport\ComplianceReportData;
use App\Services\Dscsa\ComplianceReport\ComplianceReportDataBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;

final class DscsaComplianceReportGenerator
{
    public function __construct(
        private readonly ComplianceReportDataBuilder $builder,
    ) {}

    public function buildData(EpcisDocument $document, ?User $actor = null): ComplianceReportData
    {
        return $this->builder->build($document, $actor);
    }

    /**
     * @return array{binary: string, filename: string, data: ComplianceReportData}
     */
    public function generate(EpcisDocument $document, ?User $actor = null): array
    {
        // Dompdf holds the full HTML frame tree in memory; large serial lists OOM at low
        // FPM limits (Filament then shows "Error while loading page").
        $previousMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '5G');

        try {
            $report = $this->builder->build($document, $actor);
            $html = view('dscsa.compliance-report.document', ['report' => $report])->render();

            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();

            return [
                'binary' => $dompdf->output(),
                'filename' => $this->filename($report),
                'data' => $report,
            ];
        } finally {
            if (is_string($previousMemoryLimit) && $previousMemoryLimit !== '') {
                ini_set('memory_limit', $previousMemoryLimit);
            }
        }
    }

    public function filename(ComplianceReportData $report): string
    {
        $ref = preg_replace('/[^A-Za-z0-9_-]+/', '_', $report->referenceNumber) ?? 'Report';
        $ref = trim($ref, '_') ?: 'Report';

        $lotPart = '';
        if (count($report->lots) === 1) {
            $lot = preg_replace('/[^A-Za-z0-9_-]+/', '_', $report->lots[0]) ?? '';
            $lot = trim($lot, '_');
            if ($lot !== '' && $lot !== '-') {
                $lotPart = '_'.$lot;
            }
        }

        return 'DSCSA_Compliance_Report_'.$ref.$lotPart.'_'.now()->format('Ymd').'.pdf';
    }
}
