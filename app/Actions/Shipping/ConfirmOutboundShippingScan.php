<?php

namespace App\Actions\Shipping;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\AssertOutermostSsccHasChildren;
use App\Support\Shipping\DetectOpenParentHierarchyOnShip;
use App\Support\Shipping\EpcOnOpenShippingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Confirm an SSCC/SGTIN scan onto an open outbound shipping session.
 */
final class ConfirmOutboundShippingScan
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ReceivingGate $receivingGate,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly EpcCustodyGate $custodyGate,
        private readonly DetectOpenParentHierarchyOnShip $openParentHierarchyOnShip,
        private readonly EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
        private readonly AssertOutermostSsccHasChildren $assertOutermostSsccHasChildren,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     line: ?OutboundShippingScanLine,
     *     epc: ?Epc,
     *     effect: 'confirmed'|'already_confirmed'|'not_found'|'quarantined'|'not_shippable'|'not_in_custody'|'not_correctable'|'double_ship'|'session_closed'|'open_parent_hierarchy'|'on_open_receive'
     * }
     */
    public function handle(
        OutboundShippingSession $session,
        string $scan,
        ?int $userId = null,
    ): array {
        if (! TenantFeatures::forTenant(tenant())->canAuthorOutboundShipments()) {
            throw new DomainException('Outbound shipping is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        $scan = ElementString::normalize($scan);
        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];

        if ($epc === null) {
            return [
                'ok' => false,
                'message' => 'Barcode not recognized. Check the label and try again.',
                'line' => null,
                'epc' => null,
                'effect' => 'not_found',
            ];
        }

        return DB::transaction(function () use ($session, $scan, $userId, $epc): array {
            $session = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, ['open', 'in_progress'], true)) {
                return [
                    'ok' => false,
                    'message' => 'This ship order is no longer open.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'session_closed',
                ];
            }

            $existing = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'ok' => true,
                    'message' => 'Already confirmed.',
                    'line' => $existing,
                    'epc' => $epc,
                    'effect' => 'already_confirmed',
                ];
            }

            // Inside the transaction: a hold opened after the barcode resolved must still
            // block the line, and the session row is already locked against a racing scan.
            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                $caseId = $hold->exception_id;
                $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                return [
                    'ok' => false,
                    'message' => 'Under quarantine'.$suffix.'. Clear or release quarantine before shipping.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'quarantined',
                ];
            }

            if ($this->epcOnAnotherOpenReceivingSession->existsOnAnyExclusiveSession($epc)) {
                return [
                    'ok' => false,
                    'message' => 'Already confirmed on an open receive session.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'on_open_receive',
                ];
            }

            if ($session->is_corrective) {
                // The stock is already gone, so on-hand inventory at the ship-from site is
                // the wrong question — evidence from the shipment being corrected is what
                // authorizes the amendment.
                try {
                    $this->custodyGate->assertCorrectiveShipAllowed(
                        $epc,
                        $session->corrects_epcis_document_id !== null
                            ? (int) $session->corrects_epcis_document_id
                            : null,
                        $session->site_id !== null ? (int) $session->site_id : null,
                    );
                } catch (InvalidArgumentException $e) {
                    return [
                        'ok' => false,
                        'message' => $e->getMessage(),
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'not_correctable',
                    ];
                }
            } else {
                if (! $this->shippableEpcsAtSite->contains((int) $session->site_id, (int) $epc->getKey())) {
                    return [
                        'ok' => false,
                        'message' => 'This unit is not shippable inventory at the ship-from site.',
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'not_shippable',
                    ];
                }

                // Quarantine was checked above in this transaction; custody alone here avoids
                // a duplicate hold message.
                try {
                    $this->custodyGate->assertInCustody($epc, 'shipping');
                } catch (InvalidArgumentException $e) {
                    return [
                        'ok' => false,
                        'message' => $e->getMessage(),
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'not_in_custody',
                    ];
                }
            }

            if ($this->onAnotherOpenShipSession($session, $epc)) {
                return [
                    'ok' => false,
                    'message' => 'Already on another open ship order.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'double_ship',
                ];
            }

            if ($this->onOpenTransferSession($epc)) {
                return [
                    'ok' => false,
                    'message' => 'Already confirmed on an open or in-transit transfer.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'double_ship',
                ];
            }

            if (! $session->is_corrective) {
                try {
                    $this->assertOutermostSsccHasChildren->handle($epc);
                } catch (InvalidArgumentException $e) {
                    return [
                        'ok' => false,
                        'message' => $e->getMessage(),
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'not_shippable',
                    ];
                }

                if ($this->openParentHierarchyOnShip->unexpectedParentForEpc($session, $epc) !== null) {
                    return [
                        'ok' => false,
                        'message' => 'This unit is packed under a container that is not on this ship order — scan the outermost SSCC instead.',
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'open_parent_hierarchy',
                    ];
                }
            }

            $line = OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                // Every directly scanned unit is an outermost item on this shipment, SSCC or
                // bare SGTIN alike; 'child' is reserved for hierarchy expansion.
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => $scan,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
            ]);

            $session->forceFill([
                'status' => 'in_progress',
                'confirmed_count' => (int) $session->confirmed_count + 1,
            ])->save();

            return [
                'ok' => true,
                'message' => 'Confirmed for shipment.',
                'line' => $line,
                'epc' => $epc,
                'effect' => 'confirmed',
            ];
        });
    }

    private function onAnotherOpenShipSession(OutboundShippingSession $session, Epc $epc): bool
    {
        return app(EpcOnOpenShippingSession::class)->exists($epc, $session);
    }

    private function onOpenTransferSession(Epc $epc): bool
    {
        return TransferringScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereIn('status', ['confirmed', 'received'])
            ->whereHas('session', fn ($query) => $query->whereIn('status', ['open', 'in_transit']))
            ->exists();
    }
}
