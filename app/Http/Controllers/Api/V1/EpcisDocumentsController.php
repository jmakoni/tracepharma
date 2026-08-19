<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListEpcisDocumentsRequest;
use App\Models\Epcis\EpcisDocument;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use Illuminate\Http\JsonResponse;

final class EpcisDocumentsController extends Controller
{
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

        // Site-scoped like Filament Inbound EPCIS (EpcisDocumentResource::getEloquentQuery).
        $user = $request->user();
        if ($user !== null && ! $user->can(Permissions::SitesAccessAll)) {
            $query->whereIn('ship_to_site_id', SiteAccess::userSiteIds($user));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(static fn (EpcisDocument $document): array => [
                'uuid' => $document->document_uuid,
                'status' => $document->status,
                'original_filename' => $document->original_filename,
                'received_at' => $document->received_at?->toIso8601String(),
                'received_via' => $document->received_via?->value,
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
