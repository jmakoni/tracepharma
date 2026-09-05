<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Shipping\ProcessWmsShipConfirm;
use App\Exceptions\WmsIdempotencyConflictException;
use App\Models\Tenant;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WmsShipConfirmWebhookController
{
    public function __construct(
        private readonly ProcessWmsShipConfirm $process,
    ) {}

    public function handle(Request $request, string $tenantId): JsonResponse
    {
        AssertWebhookTenantMatchesHost::assert($tenantId);

        $tenant = Tenant::query()->findOrFail($tenantId);

        $this->authorizeBridge($request, $tenant);

        TenantAccess::assertActive($tenant);

        TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::WMS_WEBHOOKS);

        return TenantRunner::run($tenant, function () use ($request): JsonResponse {
            if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
                return response()->json(['message' => 'Outbound shipping is not available for this tenant profile.'], 403);
            }

            $data = $request->validate([
                'site_id' => ['nullable', 'integer'],
                'scans' => ['required', 'array', 'min:1'],
                'scans.*' => ['required', 'string', 'max:512'],
                'complete' => ['nullable', 'boolean'],
                'trading_partner_id' => ['nullable', 'integer'],
                'customer_id' => ['nullable', 'integer'],
                'ship_to_site_id' => ['nullable', 'integer'],
                'ship_to_gln' => ['nullable', 'string', 'max:13'],
                'outbound_connection_id' => ['nullable', 'integer'],
                'asn_number' => ['nullable', 'string', 'max:255'],
                'asn' => ['nullable', 'string', 'max:255'],
                'customer_po' => ['nullable', 'string', 'max:255'],
                'po' => ['nullable', 'string', 'max:255'],
                'invoice_number' => ['nullable', 'string', 'max:255'],
                'shipment_reference' => ['nullable', 'string', 'max:255'],
                'dscsa_affirm' => ['nullable', 'boolean'],
            ]);

            $idempotencyKey = $request->header('Idempotency-Key');

            if (app()->isProduction() && ! is_string($idempotencyKey)) {
                return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
            }

            try {
                $result = $this->process->handle($data, is_string($idempotencyKey) ? $idempotencyKey : null);
            } catch (WmsIdempotencyConflictException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            } catch (DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $body = [
                'status' => $result['status'],
                'session_id' => $result['session_id'],
                'confirmed_count' => $result['confirmed_count'],
                'message' => $result['message'],
            ];

            if (isset($result['blockers'])) {
                $body['blockers'] = $result['blockers'];
            }

            if (isset($result['scan_errors'])) {
                $body['scan_errors'] = $result['scan_errors'];
            }

            if (($result['idempotent_replay'] ?? false) === true) {
                $body['idempotent_replay'] = true;
            }

            return response()->json($body, $result['http_status']);
        });
    }

    private function authorizeBridge(Request $request, Tenant $tenant): void
    {
        $configured = $this->resolveBridgeApiKey($tenant);

        if ($configured === '') {
            abort(503, 'WMS ship-confirm bridge is not configured for this tenant.');
        }

        $provided = (string) ($request->header('X-Wms-Api-Key')
            ?? $request->bearerToken()
            ?? '');

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            abort(401, 'Invalid WMS bridge credentials.');
        }
    }

    private function resolveBridgeApiKey(Tenant $tenant): string
    {
        return TenantSettings::forTenant($tenant)->wmsBridgeApiKey() ?? '';
    }
}
