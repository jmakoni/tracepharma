<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Shipping\ProcessWmsShipConfirm;
use App\Exceptions\WmsIdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sanctum Connector wrap of ProcessWmsShipConfirm.
 * Webhook POST /api/webhooks/wms/{tenantId} is unchanged.
 */
final class WmsShipConfirmController extends Controller
{
    public function __construct(
        private readonly ProcessWmsShipConfirm $process,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
            abort(403, 'Outbound shipping is not available for this tenant profile.');
        }

        TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::WMS_WEBHOOKS);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! JobRoleAccess::allows(Permissions::NavShip, $user)) {
            abort(403, 'Shipping is not authorized for your job role.');
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
    }
}
