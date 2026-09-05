<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Epcis\DispatchEpcisSubscriptions;
use App\Http\Controllers\Controller;
use App\Models\Epcis\EpcisSubscription;
use App\Models\User;
use App\Services\Epcis\Query\SimpleEventQuery;
use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GS1-shaped subscription control subset (Phase 4): subscribe / list / unsubscribe.
 *
 * Honesty: `schedule` and `params` are accepted and stored for partner compatibility,
 * but delivery is event-triggered (inbound validated / outbound sent) via
 * {@see DispatchEpcisSubscriptions} — not a timed GS1 Query Control
 * poller. `EQ_bizStep` in params is applied as a bizStep filter at create time.
 */
final class EpcisGs1SubscriptionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->assertIntegrations()) {
            return $denied;
        }

        Gate::authorize('viewAny', EpcisSubscription::class);

        $rows = EpcisSubscription::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (EpcisSubscription $sub): array => $this->publicPayload($sub));

        return response()->json(['subscriptions' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->assertIntegrations()) {
            return $denied;
        }

        Gate::authorize('create', EpcisSubscription::class);

        $data = $request->validate([
            'destination' => ['required', 'string', 'max:2048'],
            'queryName' => ['nullable', 'string', 'max:64'],
            'schedule' => ['nullable', 'string', 'max:128'],
            'name' => ['nullable', 'string', 'max:255'],
            'directions' => ['nullable', 'in:inbound,outbound,both'],
            'params' => ['nullable', 'array'],
        ]);

        try {
            EpcisSubscriptionUrl::assertSafeTargetUrl($data['destination']);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'type' => 'SubscribeNotPermittedException',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $params = is_array($data['params'] ?? null) ? $data['params'] : [];
        try {
            app(SimpleEventQuery::class)->assertAllowedParams($params);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'type' => 'QueryParameterException',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $bizSteps = [];
        if (isset($params['EQ_bizStep']) && is_string($params['EQ_bizStep'])) {
            $bizSteps[] = $params['EQ_bizStep'];
        }

        /** @var User|null $user */
        $user = $request->user();

        $subscription = EpcisSubscription::query()->create([
            'name' => $data['name'] ?? ('GS1 subscription '.now()->format('Y-m-d H:i')),
            'subscription_uuid' => (string) Str::uuid(),
            'target_url' => $data['destination'],
            'secret' => Str::random(48),
            'is_active' => true,
            'directions' => $data['directions'] ?? EpcisSubscription::DIRECTION_BOTH,
            'biz_step_filter' => $bizSteps === [] ? null : $bizSteps,
            'format' => EpcisSubscription::FORMAT_JSONLD_20,
            'query_name' => $data['queryName'] ?? 'SimpleEventQuery',
            'schedule' => $data['schedule'] ?? null,
            'query_params' => $params === [] ? null : $params,
            'created_by' => $user?->getKey(),
        ]);

        return response()->json([
            'type' => 'SubscribeSuccess',
            'subscriptionID' => $subscription->subscription_uuid,
            'secret' => $subscription->secret,
            'queryName' => $subscription->query_name,
            'destination' => $subscription->target_url,
            'schedule' => $subscription->schedule,
            'deliveryMode' => 'document_event',
            'note' => 'schedule and query params are stored for compatibility; callbacks fire when matching documents are validated (inbound) or sent (outbound), not on a cron schedule.',
        ], 201);
    }

    public function destroy(Request $request, string $subscriptionID): JsonResponse
    {
        if ($denied = $this->assertIntegrations()) {
            return $denied;
        }

        $subscription = EpcisSubscription::query()
            ->where('subscription_uuid', $subscriptionID)
            ->first();

        if ($subscription === null) {
            return response()->json([
                'type' => 'NoSuchSubscriptionException',
                'message' => 'Subscription not found.',
            ], 404);
        }

        Gate::authorize('delete', $subscription);

        $subscription->delete();

        return response()->json([
            'type' => 'UnsubscribeSuccess',
            'subscriptionID' => $subscriptionID,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPayload(EpcisSubscription $subscription): array
    {
        return [
            'subscriptionID' => $subscription->subscription_uuid,
            'name' => $subscription->name,
            'destination' => $subscription->target_url,
            'queryName' => $subscription->query_name,
            'schedule' => $subscription->schedule,
            'params' => $subscription->query_params,
            'active' => $subscription->is_active,
            'directions' => $subscription->directions,
            'deliveryMode' => 'document_event',
        ];
    }

    private function assertIntegrations(): ?JsonResponse
    {
        $features = TenantFeatures::forTenant(tenant());
        if (! $features->supportsInboundIntegrations() && ! $features->supportsOutboundIntegrations()) {
            return response()->json([
                'type' => 'SecurityException',
                'message' => 'Integrations are not enabled for this tenant profile.',
            ], 403);
        }

        try {
            TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);
        } catch (HttpException $e) {
            return response()->json([
                'type' => 'SecurityException',
                'message' => $e->getMessage(),
            ], 403);
        }

        return null;
    }
}
