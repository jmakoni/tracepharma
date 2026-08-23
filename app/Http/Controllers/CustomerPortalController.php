<?php

namespace App\Http\Controllers;

use App\Models\Epcis\EpcisDocument;
use App\Models\TradingPartner;
use App\Services\Outbound\CustomerPortalService;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerPortalController extends Controller
{
    public function index(Request $request, string $customerPortalUuid, CustomerPortalService $portal): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $partner = $this->resolvePartner($customerPortalUuid);
        $documents = $portal->documentsQuery($partner)
            ->limit(200)
            ->get(['id', 'document_uuid', 'original_filename', 'creation_date', 'created_at', 'event_count', 'epc_count', 'payload_path']);

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
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function download(
        Request $request,
        string $customerPortalUuid,
        int $document,
        CustomerPortalService $portal,
    ): StreamedResponse {
        abort_unless($request->hasValidSignature(), 403);

        $partner = $this->resolvePartner($customerPortalUuid);
        $row = $portal->documentsQuery($partner)->whereKey($document)->first();

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
}
