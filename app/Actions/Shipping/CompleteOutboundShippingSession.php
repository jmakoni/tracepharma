<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\TradingPartner;
use App\Models\User;
use App\Notifications\CustomerPortalShipNotification;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Outbound\CustomerPortalService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Complete a ship order: validate refs, mark completed, author shipping EPCIS, schedule transmit.
 */
final class CompleteOutboundShippingSession
{
    public function __construct(
        private readonly ValidateOutboundShippingSend $validateOutboundShippingSend,
        private readonly GenerateShippingEpcisEvents $generateShippingEpcisEvents,
        private readonly EpcCustodyGate $custodyGate,
        private readonly CustomerPortalService $customerPortalService,
    ) {}

    public function handle(OutboundShippingSession $session, ?int $actorId = null): OutboundShippingSession
    {
        if (! TenantFeatures::forTenant(tenant())->canAuthorOutboundShipments()) {
            throw new DomainException('Outbound shipping is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $blockers = $this->validateOutboundShippingSend->handle($session);
        if ($blockers !== []) {
            throw new DomainException(implode(' ', $blockers));
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        $completion = DB::transaction(function () use ($session): array {
            $session = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status === 'completed') {
                if ($session->shipping_events_generated_at !== null) {
                    // Already fully sent — idempotent success, nothing to retry.
                    return ['session' => $session, 'prior_status' => null];
                }

                // Completed but EPCIS authoring never finished (e.g. the prior request
                // died mid-authoring). Re-validate and retry authoring; on failure the
                // catch below must revert to the status the order was in before this
                // completion, or the order is stuck: not active (no scan/cancel) and
                // not sent (no document).
                $blockers = $this->validateOutboundShippingSend->handle($session);
                if ($blockers !== []) {
                    throw new DomainException(implode(' ', $blockers));
                }

                $this->assertConfirmedLinesStillShippable($session);

                $priorStatus = (int) $session->confirmed_count > 0 ? 'in_progress' : 'open';

                return ['session' => $session, 'prior_status' => $priorStatus];
            }

            if (! in_array($session->status, ['open', 'in_progress'], true)) {
                throw new DomainException("Cannot send ship order with status [{$session->status}].");
            }

            // Under the session lock, and reading the lines the lock froze: a scan
            // confirmed against the pre-lock line set would otherwise be sent without
            // ever having been rechecked. Re-run send validation here so refs, ATP, and
            // open-parent hierarchy cannot change between the UI check and completion.
            $blockers = $this->validateOutboundShippingSend->handle($session);
            if ($blockers !== []) {
                throw new DomainException(implode(' ', $blockers));
            }

            $this->assertConfirmedLinesStillShippable($session);

            $priorStatus = (string) $session->status;

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            return ['session' => $session->refresh(), 'prior_status' => $priorStatus];
        });

        /** @var OutboundShippingSession $session */
        $session = $completion['session'];

        if ($session->status === 'completed' && $session->shipping_events_generated_at === null) {
            try {
                $this->generateShippingEpcisEvents->handle($session, $actorId);
            } catch (Throwable $e) {
                // Authoring the EPCIS *is* the shipment as far as trading partners and
                // regulators are concerned, so a failure here cannot be reported as a send.
                // Hand the order back to the operator in its pre-send state where possible.
                if ($completion['prior_status'] !== null) {
                    $this->revertIncompleteCompletion($session, $completion['prior_status']);
                }

                $session = $session->fresh() ?? $session;
                if ($session->shipping_events_generated_at !== null) {
                    throw $e instanceof DomainException
                        ? $e
                        : new DomainException($e->getMessage(), 0, $e);
                }

                throw new DomainException(
                    'Shipment not sent — shipping EPCIS could not be authored: '.$e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        $session = $session->refresh();
        $this->issueCustomerPortalLink($session);

        return $session->refresh();
    }

    /**
     * Portal pickup is access, not identity. A link failure must not undo a send
     * that already authored transaction information.
     */
    private function issueCustomerPortalLink(OutboundShippingSession $session): void
    {
        $partner = $session->tradingPartner
            ?? ($session->trading_partner_id !== null
                ? TradingPartner::query()->find($session->trading_partner_id)
                : null);

        if (! $partner instanceof TradingPartner || ! $partner->is_active) {
            return;
        }

        try {
            $this->customerPortalService->ensureCustomerPortalLink($partner);
            $this->notifyPartnerPortalPickup($session, $partner);
        } catch (Throwable $e) {
            Log::warning('Customer portal link was not issued after outbound send.', [
                'outbound_shipping_session_id' => $session->getKey(),
                'trading_partner_id' => $partner->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Optional email-on-ship: partner contact gets a signed portal URL. Never fails the send.
     */
    private function notifyPartnerPortalPickup(OutboundShippingSession $session, TradingPartner $partner): void
    {
        if (! TenantSettings::forTenant(tenant())->emailPortalOnShipEnabled()) {
            return;
        }

        $email = filled($partner->email) ? (string) $partner->email : null;

        if ($email === null) {
            return;
        }

        try {
            $portalUrl = $this->customerPortalService->signedCustomerPortalUrl($partner);

            (new AnonymousNotifiable)
                ->route('mail', $email)
                ->notify(new CustomerPortalShipNotification(
                    partnerName: (string) $partner->name,
                    portalUrl: $portalUrl,
                    asnNumber: filled($session->asn_number) ? (string) $session->asn_number : null,
                    customerPo: filled($session->customer_po) ? (string) $session->customer_po : null,
                    tenantId: tenant()?->getKey() !== null ? (string) tenant()->getKey() : null,
                    tenantName: tenant()?->name,
                ));
        } catch (Throwable $e) {
            Log::warning('Customer portal ship email was not sent.', [
                'outbound_shipping_session_id' => $session->getKey(),
                'trading_partner_id' => $partner->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Scans are gated one at a time, but a quarantine hold, a destroy event or a
     * revoked correction scope can land between the last scan and the send. Recheck
     * the whole confirmed set so no ineligible unit reaches the authored EPCIS.
     *
     * Called inside the completion transaction with the session row locked, so the
     * confirmed lines read here are the set that will ship:
     * {@see ConfirmOutboundShippingScan} takes the same lock before inserting a
     * line, and cannot add one between this check and the status change.
     *
     * @throws DomainException when any confirmed unit can no longer ship
     */
    private function assertConfirmedLinesStillShippable(OutboundShippingSession $session): void
    {
        $epcIds = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->orderBy('id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($epcIds === []) {
            throw new DomainException('Cannot send ship order with no confirmed scan lines.');
        }

        try {
            if (! $session->is_corrective) {
                $this->custodyGate->assertOperableFor($epcIds, 'sending this shipment');

                return;
            }

            // Ids, not models: the gate resolves them itself and fails closed on one it
            // cannot load, where dropping it here would wave the line through unchecked.
            $this->custodyGate->assertCorrectiveShipAllowed(
                $epcIds,
                $session->corrects_epcis_document_id !== null
                    ? (int) $session->corrects_epcis_document_id
                    : null,
                $session->site_id !== null ? (int) $session->site_id : null,
            );
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Roll back a completed status when shipping EPCIS was not authored.
     * Locked so a concurrent author that just stamped shipping_events_generated_at
     * cannot be wiped by this revert.
     */
    private function revertIncompleteCompletion(OutboundShippingSession $session, string $priorStatus): void
    {
        DB::transaction(function () use ($session, $priorStatus): void {
            $locked = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->shipping_events_generated_at !== null) {
                return;
            }

            if ($locked->status !== 'completed') {
                return;
            }

            $locked->forceFill([
                'status' => $priorStatus,
                'completed_at' => null,
            ])->save();
        });
    }
}
