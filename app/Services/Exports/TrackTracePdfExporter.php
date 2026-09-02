<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Models\DataExport;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\DscsaComplianceReportGenerator;
use App\Support\Portal\PortalShipmentDisplay;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Async Serialized Track & Trace — same DSCSA Compliance Report PDF as Filament.
 */
final class TrackTracePdfExporter
{
    public function __construct(
        private readonly DscsaComplianceReportGenerator $generator,
        private readonly TrackTraceExportQuery $exportQuery,
    ) {}

    /**
     * @return array{row_count: int, disk: string, path: string, filename: string}
     */
    public function exportToStorage(DataExport $export, ?User $actor, string $disk, string $path): array
    {
        $documentId = $this->exportQuery->resolveDocumentId($export, $actor);

        $document = EpcisDocument::query()->find($documentId);

        if ($document === null) {
            throw new DomainException('Document not found.');
        }

        $portalExport = $this->exportQuery->isPortalExport($export);

        if ($portalExport) {
            if (! PortalShipmentDisplay::reportsAvailable($document)) {
                throw new DomainException(
                    'This shipment is not ready for a Serialized Track & Trace report yet.',
                );
            }
        } elseif (! in_array($document->status, ['parsed', 'validated'], true)) {
            throw new DomainException(
                'Document must be parsed or validated before generating a Serialized Track & Trace report.',
            );
        }

        $result = $this->generator->generate($document, $actor);

        $isS3 = config("filesystems.disks.{$disk}.driver") === 's3';
        $options = array_filter([
            'ContentType' => $isS3 ? 'application/pdf' : null,
        ]);

        Storage::disk($disk)->put($path, $result['binary'], $options);

        return [
            'row_count' => $result['data']->serialCount,
            'disk' => $disk,
            'path' => $path,
            'filename' => $result['filename'],
        ];
    }
}
