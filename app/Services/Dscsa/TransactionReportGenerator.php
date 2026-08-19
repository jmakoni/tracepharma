<?php

namespace App\Services\Dscsa;

use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\TransactionReport\TransactionReportData;
use App\Services\Dscsa\TransactionReport\TransactionReportDataBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;

final class TransactionReportGenerator
{
    public function __construct(
        private readonly TransactionReportDataBuilder $builder,
    ) {}

    /**
     * Build report data (for tests / preview) without rendering PDF.
     */
    public function buildData(EpcisDocument $document, ?User $actor = null): TransactionReportData
    {
        return $this->builder->build($document, $actor);
    }

    /**
     * @return array{binary: string, filename: string, data: TransactionReportData}
     */
    public function generate(EpcisDocument $document, ?User $actor = null): array
    {
        $report = $this->builder->build($document, $actor);
        $html = view('dscsa.transaction-report.document', ['report' => $report])->render();

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
            'filename' => $this->filenameForReference($report->referenceNumber),
            'data' => $report,
        ];
    }

    public function filenameForReference(string $referenceNumber): string
    {
        $ref = preg_replace('/[^A-Za-z0-9_-]+/', '_', $referenceNumber) ?? 'Report';
        $ref = trim($ref, '_') ?: 'Report';

        return 'Transaction_Report_'.$ref.'_'.now()->format('Ymd').'.pdf';
    }
}
