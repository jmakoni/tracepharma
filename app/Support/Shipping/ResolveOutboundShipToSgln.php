<?php

namespace App\Support\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dest GLN / SGLN for an outbound ship order — the same candidates the author uses.
 *
 * Never invents a company-prefix split. A recorded site/partner URN or an inbound
 * published URN that encodes this GLN is accepted; otherwise resolve() is null.
 */
final class ResolveOutboundShipToSgln
{
    /**
     * @return array{gln: ?string, site_id: ?int}
     */
    public function destParty(OutboundShippingSession $session): array
    {
        $session->loadMissing(['shipToSite', 'tradingPartner']);

        if (filled($session->ship_to_gln)) {
            $gln = Sgln::normalizeGln((string) $session->ship_to_gln);

            if ($gln !== null) {
                return [
                    'gln' => $gln,
                    'site_id' => $session->ship_to_site_id !== null ? (int) $session->ship_to_site_id : null,
                ];
            }
        }

        if ($session->ship_to_site_id !== null && filled($session->shipToSite?->gln)) {
            return [
                'gln' => Sgln::normalizeGln((string) $session->shipToSite->gln),
                'site_id' => (int) $session->ship_to_site_id,
            ];
        }

        if (filled($session->tradingPartner?->gln)) {
            return [
                'gln' => Sgln::normalizeGln((string) $session->tradingPartner->gln),
                'site_id' => null,
            ];
        }

        return ['gln' => null, 'site_id' => null];
    }

    /**
     * @return list<string>
     */
    public function candidates(OutboundShippingSession $session): array
    {
        $session->loadMissing(['shipToSite', 'tradingPartner']);

        $fromMaster = [];
        foreach ([$session->shipToSite?->getAttribute('sgln'), $session->tradingPartner?->getAttribute('sgln')] as $value) {
            if (is_string($value) && $value !== '') {
                $fromMaster[] = $value;
            }
        }

        $party = $this->destParty($session);
        if ($party['gln'] === null) {
            return $fromMaster;
        }

        return array_values(array_unique([
            ...$fromMaster,
            ...$this->publishedSglnCandidatesForGln($party['gln']),
        ]));
    }

    public function resolve(OutboundShippingSession $session): ?string
    {
        $party = $this->destParty($session);
        if ($party['gln'] === null) {
            return null;
        }

        return SglnResolution::resolve(
            $party['gln'],
            $this->candidates($session),
            TenantSettings::forTenant(tenant())->companyPrefixForPartnerEncoding(),
        );
    }

    /**
     * @return list<string>
     */
    private function publishedSglnCandidatesForGln(string $gln): array
    {
        $fromLocations = [];
        $fromParties = [];

        if (Schema::hasTable('event_locations')) {
            $fromLocations = DB::table('event_locations')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_locations.event_id')
                ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
                ->where('epcis_documents.direction', 'inbound')
                ->where('event_locations.gln', $gln)
                ->whereNotNull('event_locations.gln_uri')
                ->where('event_locations.gln_uri', '!=', '')
                ->pluck('event_locations.gln_uri')
                ->all();
        }

        if (Schema::hasTable('event_parties')) {
            $fromParties = DB::table('event_parties')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_parties.event_id')
                ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
                ->where('epcis_documents.direction', 'inbound')
                ->where('event_parties.gln', $gln)
                ->whereNotNull('event_parties.gln_uri')
                ->where('event_parties.gln_uri', '!=', '')
                ->pluck('event_parties.gln_uri')
                ->all();
        }

        return array_values(array_filter(
            [...$fromLocations, ...$fromParties],
            static fn (mixed $uri): bool => is_string($uri) && $uri !== '',
        ));
    }
}
