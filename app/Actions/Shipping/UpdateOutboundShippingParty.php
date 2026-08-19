<?php

namespace App\Actions\Shipping;

use App\Models\OutboundConnection;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\User;
use App\Rules\ValidGln;
use App\Services\Epcis\OutboundConnectionResolver;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\Sgln;
use App\Support\MasterData\AtpReadinessGate;
use App\Support\Shipping\AtpGateBypass;
use DomainException;
use Illuminate\Support\Arr;

/**
 * Update customer / ship-to / outbound connection on a ship order session.
 */
final class UpdateOutboundShippingParty
{
    public function __construct(
        private readonly OutboundConnectionResolver $connectionResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(OutboundShippingSession $session, array $data): OutboundShippingSession
    {
        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        if (! $session->canScan()) {
            throw new DomainException('Cannot update party on a closed ship order.');
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        $partnerId = Arr::has($data, 'trading_partner_id')
            ? ($data['trading_partner_id'] !== null ? (int) $data['trading_partner_id'] : null)
            : $session->trading_partner_id;

        $partner = null;

        if ($partnerId !== null) {
            $partner = TradingPartner::query()
                ->whereKey($partnerId)
                ->where('is_active', true)
                ->first();

            if ($partner === null) {
                throw new DomainException('Selected trading partner was not found or is inactive.');
            }
        }

        $shipToSiteId = Arr::has($data, 'ship_to_site_id')
            ? ($data['ship_to_site_id'] !== null ? (int) $data['ship_to_site_id'] : null)
            : ($session->ship_to_site_id !== null ? (int) $session->ship_to_site_id : null);

        $shipToSite = $shipToSiteId !== null
            ? $this->assertShipToSiteBelongsToPartner($shipToSiteId, $partnerId, $partner)
            : null;

        $shipToGln = Arr::has($data, 'ship_to_gln')
            ? $this->normalizeShipToGln($data['ship_to_gln'])
            : $session->ship_to_gln;

        $this->assertShipToGlnMatchesSite($shipToGln, $shipToSite);

        if ($shipToGln !== null && $shipToSite === null) {
            $shipToSite = $this->resolveShipToSiteFromGln($shipToGln, $partnerId, $partner);
            $shipToSiteId = $shipToSite !== null ? (int) $shipToSite->getKey() : null;
        }

        $explicitConnection = Arr::has($data, 'outbound_connection_id');

        $connectionId = $explicitConnection
            ? ($data['outbound_connection_id'] !== null ? (int) $data['outbound_connection_id'] : null)
            : $session->outbound_connection_id;

        if ($connectionId !== null) {
            $connection = OutboundConnection::query()
                ->where('is_active', true)
                ->find($connectionId);

            if ($connection === null) {
                throw new DomainException('Selected outbound connection was not found or is inactive.');
            }

            if (! OutboundConnectionResolver::connectionMatchesPartner($connection, $partnerId)) {
                if ($explicitConnection) {
                    if ($connection->trading_partner_id !== null && $partnerId === null) {
                        throw new DomainException('Select the customer before choosing a partner-scoped outbound connection.');
                    }

                    throw new DomainException('Selected outbound connection is not scoped to this customer.');
                }

                $connectionId = null;
            }
        }

        if ($connectionId === null && $partnerId !== null) {
            $defaultConnection = $this->connectionResolver->resolve($partnerId);
            $connectionId = $defaultConnection?->getKey();
        }

        $session->forceFill([
            'trading_partner_id' => $partnerId,
            'ship_to_site_id' => $shipToSiteId,
            'ship_to_gln' => $shipToGln,
            'outbound_connection_id' => $connectionId,
        ])->save();

        return $session->refresh();
    }

    /**
     * A ship-to site addresses one customer. Pairing it with a different partner would
     * author a shipment whose owning party and location name two unrelated companies.
     */
    private function assertShipToSiteBelongsToPartner(int $shipToSiteId, ?int $partnerId, ?TradingPartner $partner): Site
    {
        $shipToSite = Site::query()
            ->with('tradingPartner:id,name')
            ->whereKey($shipToSiteId)
            ->where('is_active', true)
            ->first();

        if ($shipToSite === null) {
            throw new DomainException('Selected ship-to site was not found or is inactive.');
        }

        if ($shipToSite->trading_partner_id === null) {
            throw new DomainException(
                'Ship-to site "'.$shipToSite->name.'" is not a customer site — pick a site that belongs to the customer.',
            );
        }

        if ($partnerId === null) {
            throw new DomainException('Select the customer that owns the ship-to site.');
        }

        if ((int) $shipToSite->trading_partner_id !== $partnerId) {
            throw new DomainException(sprintf(
                'Ship-to site "%s" belongs to %s, not %s.',
                $shipToSite->name,
                $shipToSite->tradingPartner?->name ?? 'another customer',
                $partner?->name ?? 'the selected customer',
            ));
        }

        return $shipToSite;
    }

    /**
     * A ship-to GLN reaches the authored EPCIS destinationList verbatim, so it has to be
     * a real GS1 GLN rather than 13 digits that happen to fit.
     */
    private function normalizeShipToGln(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = ValidGln::normalize($value);

        if ($normalized === null) {
            throw new DomainException('Ship-to GLN must be a valid 13-digit GS1 GLN (check digit failed).');
        }

        return $normalized;
    }

    /**
     * A destination GLN with no site behind it leaves the ATP gate nothing to judge and
     * would author a shipment to an address we hold no licence evidence for, so the GLN is
     * bound to one of the customer's facilities here. Blocking is suppressed while the
     * outbound ATP gate is lifted — the same kill-switch covers its prerequisite.
     */
    private function resolveShipToSiteFromGln(string $shipToGln, ?int $partnerId, ?TradingPartner $partner): ?Site
    {
        $enforced = ! AtpGateBypass::isBypassed();

        if ($partnerId === null) {
            if ($enforced) {
                throw new DomainException('Select the customer that owns ship-to GLN '.$shipToGln.'.');
            }

            return null;
        }

        $resolved = AtpReadinessGate::siteForGln($partnerId, $shipToGln);

        if ($resolved === null && $enforced) {
            throw new DomainException(sprintf(
                'Ship-to GLN %s does not match any active site on record for %s. Add the destination site (with its ATP license) before shipping to it.',
                $shipToGln,
                $partner?->name ?? 'the selected customer',
            ));
        }

        return $resolved;
    }

    private function assertShipToGlnMatchesSite(?string $shipToGln, ?Site $shipToSite): void
    {
        if ($shipToGln === null || $shipToSite === null) {
            return;
        }

        $siteGln = Sgln::normalizeGln($shipToSite->gln);

        if ($siteGln !== null && $siteGln !== $shipToGln) {
            throw new DomainException(sprintf(
                'Ship-to GLN %s does not match ship-to site "%s" (%s).',
                $shipToGln,
                $shipToSite->name,
                $siteGln,
            ));
        }
    }

}
