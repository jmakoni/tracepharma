<?php

namespace App\Services\Dscsa;

use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\Support\EpcisShipmentReportContext;
use ZipArchive;

/**
 * ZIP audit package: transaction PDF + serialized DSCSA PDF + TI-history JSON.
 */
final class AuditPackageZipGenerator
{
    public function __construct(
        private readonly TransactionReportGenerator $transactionReport,
        private readonly DscsaComplianceReportGenerator $complianceReport,
        private readonly TiHistoryExportGenerator $tiHistory,
        private readonly EpcisShipmentReportContext $context,
    ) {}

    /**
     * @return array{binary: string, filename: string, content_type: string}
     */
    public function generate(EpcisDocument $document, ?User $actor = null): array
    {
        $transaction = $this->transactionReport->generate($document, $actor);
        $compliance = $this->complianceReport->generate($document, $actor);
        $tiHistory = $this->tiHistory->generate($document, $actor);

        $tmp = tempnam(sys_get_temp_dir(), 'tp-audit-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary audit package file.');
        }

        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;
        $binary = null;

        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to open audit package ZIP.');
            }

            $zip->addFromString($transaction['filename'], $transaction['binary']);
            $zip->addFromString($compliance['filename'], $compliance['binary']);
            $zip->addFromString($tiHistory['filename'], $tiHistory['binary']);
            $zip->addFromString(
                'document-summary.json',
                (string) json_encode([
                    'document_id' => $document->getKey(),
                    'document_uuid' => $document->document_uuid,
                    'reference' => $this->context->referenceNumber($document),
                    'asn_number' => $document->asn_number,
                    'customer_po' => $document->customer_po,
                    'sender_gln' => $document->sender_gln,
                    'receiver_gln' => $document->receiver_gln,
                    'ship_to_site_id' => $document->ship_to_site_id,
                    'status' => $document->status,
                    'generated_at' => now()->toIso8601String(),
                    'generated_by' => $actor?->email,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );

            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finalize audit package ZIP.');
            }

            $binary = file_get_contents($zipPath);
            if ($binary === false || $binary === '') {
                throw new \RuntimeException('Unable to read audit package ZIP.');
            }
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }

        $ref = preg_replace('/[^A-Za-z0-9_-]+/', '_', $this->context->referenceNumber($document)) ?: 'DOC';

        return [
            'binary' => $binary,
            'filename' => 'Audit_Package_'.$ref.'_'.now()->format('Ymd').'.zip',
            'content_type' => 'application/zip',
        ];
    }
}
