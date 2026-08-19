<?php

namespace App\Actions\Shipping;

use App\Enums\SiteAtpReadinessStatus;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Support\Gs1\Sgln;
use App\Support\MasterData\AtpDisclosure;
use App\Support\MasterData\AtpReadinessGate;
use App\Support\MasterData\SiteAtpReadiness;
use App\Support\MasterData\TenantReceivingState;
use App\Support\Shipping\AtpGateBypass;
use App\Support\Shipping\DetectOpenParentHierarchyOnShip;
use Illuminate\Support\Collection;

/**
 * Return human-readable blockers before sending a ship order.
 *
 * @return list<string>
 */
final class ValidateOutboundShippingSend
{
    public function __construct(
        private readonly DetectOpenParentHierarchyOnShip $openParentHierarchyOnShip,
    ) {}

    public function handle(OutboundShippingSession $session): array
    {
        $session->loadMissing(['tradingPartner', 'shipToSite', 'site']);
        $blockers = [];

        if ($session->trading_partner_id === null) {
            $blockers[] = 'Select a customer (trading partner).';
        }

        if ($session->trading_partner_id !== null) {
            $destination = $this->destinationCandidates($session);

            if ($destination['unresolved_gln'] === null && $destination['sites']->isEmpty()) {
                $blockers[] = sprintf(
                    'Customer "%s" has no destination sites on record — add a ship-to site before sending.',
                    $session->tradingPartner?->name ?? 'selected customer',
                );
            }
        }

        if (! $this->hasShipTo($session)) {
            $blockers[] = 'Provide a ship-to GLN or partner site.';
        }

        if (blank($session->asn_number)) {
            $blockers[] = 'ASN number is required.';
        }

        if (blank($session->customer_po) && blank($session->invoice_number)) {
            $blockers[] = 'Customer PO or invoice number is required.';
        }

        if (! $session->dscsa_affirm) {
            $blockers[] = 'TI/TS affirmation is required.';
        }

        if (! $this->hasConfirmedLines($session)) {
            $blockers[] = 'Confirm at least one unit before sending.';
        }

        if (($openParentBlocker = $this->openParentHierarchyOnShip->blockerMessage($session)) !== null) {
            $blockers[] = $openParentBlocker;
        }

        if (($atpBlocker = $this->atpBlocker($session)) !== null) {
            $blockers[] = $atpBlocker;
        }

        return $blockers;
    }

    private function hasConfirmedLines(OutboundShippingSession $session): bool
    {
        return OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->exists();
    }

    private function hasShipTo(OutboundShippingSession $session): bool
    {
        if (filled($session->ship_to_gln) && Sgln::normalizeGln((string) $session->ship_to_gln) !== null) {
            return true;
        }

        if ($session->ship_to_site_id !== null && filled($session->shipToSite?->gln)) {
            return true;
        }

        if (filled($session->tradingPartner?->gln)) {
            return true;
        }

        return false;
    }

    /**
     * Inbound only soft-warns on partner ATP, but a shipment we author is a transfer of
     * ownership to that party: a license for the tenant receiving state that is expired,
     * missing, or carries no expiration date stops the send instead of trailing it as an
     * exception.
     *
     * Silent only when there is nothing to judge at all — no customer, and no site on
     * record for the one that is selected.
     *
     * The ingest soft warning judges a party by the same rule, through
     * {@see AtpReadinessGate::blocksParty()}.
     */
    private function atpBlocker(OutboundShippingSession $session): ?string
    {
        if (AtpGateBypass::isBypassed()) {
            return null;
        }

        $tenantState = TenantReceivingState::resolve();

        // Without a receiving state every partner reads as NeedsReceivingState, which is
        // not evidence of a license — say so rather than waving the shipment through.
        if ($tenantState === null) {
            return 'Set the organization receiving state in Organization settings before sending — partner ATP licenses cannot be evaluated without it.';
        }

        $destination = $this->destinationCandidates($session);

        if ($destination['unresolved_gln'] !== null) {
            return sprintf(
                'Ship-to GLN %s does not match any active site on record for %s, so its ATP license for %s cannot be checked. Add the destination site before sending.',
                $destination['unresolved_gln'],
                $session->tradingPartner?->name ?? 'the selected customer',
                $tenantState,
            );
        }

        $sites = $destination['sites'];

        if ($sites->isEmpty()) {
            return null;
        }

        $unready = [];

        foreach ($sites as $site) {
            $status = SiteAtpReadiness::summarize($site)['status'];

            // One licensed destination is enough: the shipment can lawfully land there.
            if (! AtpReadinessGate::blocks($status)) {
                return null;
            }

            $unready[] = $status;
        }

        return $this->atpBlockerMessage($session, $sites, $unready, $tenantState);
    }

    /**
     * The facilities the ATP gate judges, and the destination GLN that named none of them.
     *
     * A named ship-to site, or the site a destination GLN resolves to, is the only
     * candidate: a license held by another address of the same customer does not authorize
     * a delivery to this one. Only when the order names no destination at all does every
     * address the customer has on record stand in.
     *
     * ship_to_gln is checked before shipToSite, mirroring
     * {@see GenerateShippingEpcisEvents::resolveShipToParty()}: it is
     * the destination that gets authored onto the shipping event, and it can name a
     * different address than the saved ship-to site (e.g. a specific dock/sub-location for
     * the same partner). Judging the site instead would pass ATP against a location the
     * shipment is not actually addressed to.
     *
     * @return array{sites: Collection<int, Site>, unresolved_gln: ?string}
     */
    private function destinationCandidates(OutboundShippingSession $session): array
    {
        $partnerId = $session->trading_partner_id !== null ? (int) $session->trading_partner_id : null;

        if (filled($session->ship_to_gln)) {
            $shipToGln = Sgln::normalizeGln($session->ship_to_gln);

            if ($shipToGln !== null) {
                $resolved = $partnerId !== null
                    ? AtpReadinessGate::siteForGln($partnerId, $shipToGln)
                    : null;

                return $resolved instanceof Site
                    ? ['sites' => collect([$resolved]), 'unresolved_gln' => null]
                    : ['sites' => collect(), 'unresolved_gln' => $shipToGln];
            }
        }

        if ($session->shipToSite instanceof Site) {
            return ['sites' => collect([$session->shipToSite]), 'unresolved_gln' => null];
        }

        if ($partnerId === null) {
            return ['sites' => collect(), 'unresolved_gln' => null];
        }

        return [
            'sites' => Site::query()
                ->where('trading_partner_id', $partnerId)
                ->where('is_active', true)
                ->get(),
            'unresolved_gln' => null,
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @param  list<SiteAtpReadinessStatus>  $unready
     */
    private function atpBlockerMessage(
        OutboundShippingSession $session,
        Collection $sites,
        array $unready,
        string $tenantState,
    ): string {
        if ($sites->count() === 1) {
            return sprintf(
                '%s Sending is blocked until a valid license is on record. %s',
                $this->singleSiteReason($sites->first(), $unready[0], $tenantState),
                AtpDisclosure::SHORT,
            );
        }

        return sprintf(
            'Customer "%s" has no site with a valid ATP license on record for %s — checked %d site(s). Sending is blocked until one is. %s',
            $session->tradingPartner?->name ?? 'selected customer',
            $tenantState,
            $sites->count(),
            AtpDisclosure::SHORT,
        );
    }

    private function singleSiteReason(Site $site, SiteAtpReadinessStatus $status, string $tenantState): string
    {
        return match ($status) {
            SiteAtpReadinessStatus::Expired => sprintf(
                'Ship-to site "%s" has an expired ATP license for %s on record.',
                $site->name,
                $tenantState,
            ),
            SiteAtpReadinessStatus::UnknownExpiry => sprintf(
                'Ship-to site "%s" has an ATP license for %s with no expiration date on file, so it cannot be shown to be in force.',
                $site->name,
                $tenantState,
            ),
            default => sprintf(
                'Ship-to site "%s" has no ATP license for %s on record.',
                $site->name,
                $tenantState,
            ),
        };
    }
}
