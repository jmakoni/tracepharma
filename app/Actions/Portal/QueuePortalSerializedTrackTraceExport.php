<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Enums\DataExportType;
use App\Jobs\Exports\ProcessTrackTraceExportJob;
use App\Models\DataExport;
use App\Models\Epcis\EpcisDocument;
use App\Models\PortalUser;
use App\Support\Portal\PortalShipmentDisplay;
use DomainException;

/**
 * Queue a Serialized Track & Trace (DSCSA Compliance Report) PDF for a portal buyer.
 * Visibility must already be asserted by the caller.
 */
final class QueuePortalSerializedTrackTraceExport
{
    public function handle(EpcisDocument $document, PortalUser $portalUser): DataExport
    {
        if (! PortalShipmentDisplay::reportsAvailable($document)) {
            throw new DomainException(
                'This shipment is not ready for a Serialized Track & Trace report yet.',
            );
        }

        $email = strtolower(trim((string) $portalUser->email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('A valid email is required to receive the export download link.');
        }

        $tenantId = tenant('id');
        if (! is_string($tenantId) || $tenantId === '') {
            throw new DomainException('Tenant context is required to queue the export.');
        }

        $export = DataExport::query()->create([
            'type' => DataExportType::TrackAndTrace,
            'requested_by_user_id' => null,
            'notify_email' => $email,
            'filters' => [
                'document_id' => (int) $document->getKey(),
                'portal' => true,
            ],
        ]);

        ProcessTrackTraceExportJob::dispatch(
            $tenantId,
            (string) $export->getKey(),
        )->onQueue((string) config('tracepharma.exports.queue', 'default'));

        return $export;
    }
}
