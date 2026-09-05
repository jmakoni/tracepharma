<?php

namespace App\Http\Controllers;

use App\Models\Epcis\EpcisDocument;
use App\Models\TradingPartner;
use App\Services\Outbound\CustomerPortalService;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerPortalController extends Controller
{
    public function index(Request $request, string $customerPortalUuid, CustomerPortalService $portal): Response
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(['doc_direction', 'from', 'to', 'po']), 403);

        $partner = $this->resolvePartner($customerPortalUuid);
        $direction = $this->normalizeDirection($request->query('doc_direction'));
        $from = $this->parseDate($request->query('from'), endOfDay: false);
        $to = $this->parseDate($request->query('to'), endOfDay: true);
        $po = filled($request->query('po')) ? (string) $request->query('po') : null;

        $documents = $portal->portalDocumentsQuery($partner, $direction, $from, $to, $po)
            ->limit(200)
            ->get([
                'id',
                'document_uuid',
                'original_filename',
                'creation_date',
                'created_at',
                'event_count',
                'epc_count',
                'payload_path',
                'direction',
                'customer_po',
                'asn_number',
            ]);

        $downloads = $documents->mapWithKeys(
            fn (EpcisDocument $document): array => [
                (int) $document->getKey() => $portal->signedDownloadUrl($partner, $document),
            ],
        );

        return response()
            ->view('customer-portal.index', [
                'partner' => $partner,
                'documents' => $documents,
                'downloads' => $downloads,
                'retentionYears' => max(1, (int) config('tracepharma.epcis.retention_years', 6)),
                'filters' => [
                    'direction' => $direction,
                    'from' => $from?->toDateString(),
                    'to' => $to?->toDateString(),
                    'po' => $po,
                ],
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function download(
        Request $request,
        string $customerPortalUuid,
        int $document,
        CustomerPortalService $portal,
    ): StreamedResponse {
        abort_unless($request->hasValidSignatureWhileIgnoring(['doc_direction', 'from', 'to', 'po']), 403);

        $partner = $this->resolvePartner($customerPortalUuid);
        $row = $portal->portalDocumentsQuery($partner)->whereKey($document)->first();

        abort_if($row === null, 404, 'Document not found for this customer portal.');
        abort_unless(EpcisDocumentXmlDownload::available($row), 404, 'EPCIS file is not available.');

        $download = EpcisDocumentXmlDownload::response($row);
        $download->headers->set('Cache-Control', 'no-store, private');

        return $download;
    }

    private function resolvePartner(string $customerPortalUuid): TradingPartner
    {
        $partner = TradingPartner::query()
            ->where('customer_portal_uuid', $customerPortalUuid)
            ->first(['id', 'name', 'customer_portal_uuid', 'is_active']);

        abort_if($partner === null || ! $partner->is_active, 403, 'This customer portal link is no longer active.');

        return $partner;
    }

    private function normalizeDirection(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, ['inbound', 'outbound'], true) ? $value : null;
    }

    private function parseDate(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! is_string($value) || ! filled($value)) {
            return null;
        }

        try {
            $date = Carbon::parse($value);

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
