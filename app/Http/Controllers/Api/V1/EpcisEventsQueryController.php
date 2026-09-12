<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Epcis\EpcisEvent;
use App\Models\User;
use App\Services\Epcis\Outbound\CanonicalEventsToJsonLd20;
use App\Services\Epcis\Query\SimpleEventQuery;
use App\Support\Epcis\EpcisApiSiteAccess;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * GS1-shaped SimpleEventQuery REST (Phase 1).
 */
final class EpcisEventsQueryController extends Controller
{
    public function __construct(
        private readonly SimpleEventQuery $query,
        private readonly CanonicalEventsToJsonLd20 $projector,
        private readonly EpcisApiSiteAccess $siteAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->assertReadable()) {
            return $denied;
        }

        $params = $request->query();

        try {
            $result = $this->query->execute(is_array($params) ? $params : [], $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'type' => 'QueryParameterException',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $payload = $this->projector->projectQueryDocument($result['events']);
        if ($result['nextPageToken'] !== null) {
            $payload['nextPageToken'] = $result['nextPageToken'];
        }
        $payload['perPage'] = $result['perPage'];

        return response()->json($payload, 200, [
            'Content-Type' => 'application/ld+json; charset=UTF-8',
        ]);
    }

    public function show(Request $request, string $eventID): JsonResponse
    {
        if ($denied = $this->assertReadable()) {
            return $denied;
        }

        $decoded = urldecode($eventID);
        $event = null;

        if (ctype_digit($decoded)) {
            $event = EpcisEvent::query()
                ->with(['eventEpcs.epc', 'locations', 'bizTransactions', 'epcIlmd', 'document'])
                ->find((int) $decoded);
        }

        if ($event === null) {
            $event = EpcisEvent::query()
                ->with(['eventEpcs.epc', 'locations', 'bizTransactions', 'epcIlmd', 'document'])
                ->where('event_id', $decoded)
                ->first();
        }

        if ($event === null || $event->document === null) {
            return response()->json([
                'type' => 'NoSuchResourceException',
                'message' => 'Event not found.',
            ], 404);
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $this->siteAccess->callerCanAccessDocument($user, $event->document, (string) $event->document->direction)) {
            return response()->json([
                'type' => 'SecurityException',
                'message' => 'You do not have access to this event.',
            ], 403);
        }

        // Active generation only
        if ((int) $event->ingest_generation !== (int) ($event->document->ingest_generation ?? 1)) {
            return response()->json([
                'type' => 'NoSuchResourceException',
                'message' => 'Event not found.',
            ], 404);
        }

        if (! in_array($event->document->status, ['parsed', 'validated', 'generated'], true)) {
            return response()->json([
                'type' => 'NoSuchResourceException',
                'message' => 'Event not found.',
            ], 404);
        }

        return response()->json($this->projector->projectEvent($event), 200, [
            'Content-Type' => 'application/ld+json; charset=UTF-8',
        ]);
    }

    private function assertReadable(): ?JsonResponse
    {
        if (! TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()) {
            return response()->json([
                'type' => 'SecurityException',
                'message' => 'Inbound integrations are not enabled for this tenant profile.',
            ], 403);
        }

        try {
            TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'type' => 'SecurityException',
                'message' => $e->getMessage(),
            ], 403);
        }

        return null;
    }
}
