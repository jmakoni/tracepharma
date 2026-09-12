<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Undo a single confirmed ship scan.
 *
 * Allowed while the order is still scannable, stuck completed without authored
 * shipping EPCIS (needsShippingEpcis), or when shipping EPCIS was authored but
 * transmission is still Retry-eligible (failed / skipped) so operators can drop
 * a pallet and retransmit a rebuilt TI.
 */
final class UnconfirmOutboundShippingScanLine
{
    public function handle(OutboundShippingScanLine $line, ?int $actorId = null): OutboundShippingSession
    {
        if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
            throw new DomainException('Outbound shipping is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $session = $line->session ?? OutboundShippingSession::query()->find($line->outbound_shipping_session_id);
        if ($session instanceof OutboundShippingSession) {
            $user = auth()->user();
            if ($user instanceof User && $session->site_id !== null) {
                SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
            }
        }

        $lineId = (int) $line->getKey();

        $result = DB::transaction(function () use ($line): OutboundShippingSession {
            $line = OutboundShippingScanLine::query()
                ->whereKey($line->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $session = OutboundShippingSession::query()
                ->whereKey($line->outbound_shipping_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            $authored = $session->shipping_events_generated_at !== null || $session->epcis_document_id !== null;
            if ($authored && ! $this->allowsPostAuthorRemoval($session)) {
                throw new DomainException(
                    'Cannot remove scan: shipping EPCIS was already generated and transmission is not failed/skipped. '
                    .'Use Retry Transmit after a failed send, or remove the pallet only while transmit is Retry-eligible.',
                );
            }

            if (! $authored && ! $session->canScan() && ! $session->needsShippingEpcis()) {
                throw new DomainException("Cannot remove scan: ship order status [{$session->status}] is not editable.");
            }

            if ($line->status !== 'confirmed') {
                throw new DomainException('Cannot remove scan: only confirmed lines can be removed.');
            }

            $line->delete();

            $remainingConfirmed = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->count();

            $updates = [
                'confirmed_count' => max(0, (int) $session->confirmed_count - 1),
            ];

            if (! $authored) {
                if ($session->needsShippingEpcis()) {
                    $updates['status'] = $remainingConfirmed > 0 ? 'in_progress' : 'open';
                    $updates['completed_at'] = null;
                } elseif ($session->isActive()) {
                    $updates['status'] = $remainingConfirmed > 0 ? 'in_progress' : 'open';
                }
            }

            $session->forceFill($updates)->save();

            return $session->refresh();
        });

        Log::info('outbound_shipping.session.scan_line_unconfirmed', [
            'outbound_shipping_session_id' => $result->getKey(),
            'outbound_shipping_scan_line_id' => $lineId,
            'actor_id' => $actorId,
        ]);

        return $result;
    }

    private function allowsPostAuthorRemoval(OutboundShippingSession $session): bool
    {
        $document = $session->epcisDocument;
        if ($document === null && $session->epcis_document_id !== null) {
            $session->load('epcisDocument');
            $document = $session->epcisDocument;
        }

        if ($document === null) {
            return false;
        }

        return in_array((string) $document->transmission_status, ['failed', 'skipped'], true);
    }
}
