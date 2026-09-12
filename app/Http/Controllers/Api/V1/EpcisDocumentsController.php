<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListEpcisDocumentsRequest;
use App\Http\Requests\Api\V1\ShowEpcisDocumentRequest;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Epcis\Outbound\CanonicalEventsToJsonLd20;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Epcis\EpcisApiSiteAccess;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EpcisDocumentsController extends Controller
{
    public function __construct(
        private readonly EpcisApiSiteAccess $siteAccess,
        private readonly CanonicalEventsToJsonLd20 $jsonLdProjector,
    ) {}

    public function index(ListEpcisDocumentsRequest $request): JsonResponse
    {
        if (! TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()) {
            abort(403, 'Inbound integrations are not enabled for this tenant profile.');
        }

        TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        $query = EpcisDocument::query()
            ->inboundCatalog()
            ->orderByDesc('received_at');

        if (filled($request->input('schema_version'))) {
            $query->where('schema_version', (string) $request->input('schema_version'));
        }

        if (filled($request->input('format'))) {
            $query->where('format', (string) $request->input('format'));
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $user->can(Permissions::SitesAccessAll)) {
            $query->whereIn('ship_to_site_id', SiteAccess::userSiteIds($user));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (EpcisDocument $document): array => $this->metadataPayload($document))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(ShowEpcisDocumentRequest $request, EpcisDocument $document): JsonResponse
    {
        $this->assertDocumentReadable($request, $document);

        return response()->json([
            'data' => $this->metadataPayload($document),
        ]);
    }

    public function epcis20(ShowEpcisDocumentRequest $request, EpcisDocument $document): JsonResponse
    {
        $this->assertDocumentReadable($request, $document);

        try {
            $json = $this->jsonLdProjector->projectDocument($document);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            abort(500, 'Unable to encode EPCIS 2.0 JSON-LD projection.');
        }

        return response()->json($decoded, 200, [
            'Content-Type' => 'application/ld+json; charset=UTF-8',
        ]);
    }

    private function assertDocumentReadable(Request $request, EpcisDocument $document): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()) {
            abort(403, 'Inbound integrations are not enabled for this tenant profile.');
        }

        TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $this->siteAccess->callerCanAccessDocument($user, $document, (string) $document->direction)) {
            abort(403, 'You do not have access to this EPCIS document.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataPayload(EpcisDocument $document): array
    {
        return [
            'id' => $document->getKey(),
            'uuid' => $document->document_uuid,
            'status' => $document->status,
            'direction' => $document->direction,
            'schema_version' => $document->schema_version,
            'format' => $document->format ?? EpcisSchemaVersion::FORMAT_XML,
            'original_filename' => $document->original_filename,
            'received_at' => $document->received_at?->toIso8601String(),
            'received_via' => $document->received_via?->value,
            'creation_date' => $document->creation_date?->toIso8601String(),
            'event_count' => (int) $document->event_count,
            'epc_count' => (int) $document->epc_count,
            'ship_from_site_id' => $document->ship_from_site_id,
            'ship_to_site_id' => $document->ship_to_site_id,
            'trading_partner_id' => $document->trading_partner_id,
        ];
    }
}
