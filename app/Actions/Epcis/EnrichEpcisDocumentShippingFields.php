<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EventBizTransaction;
use App\Models\Epcis\EventParty;
use App\Support\Epcis\EpcisXmlReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalize shipping PO/ASN and ship-from/to GLNs onto an EPCIS document from persisted events.
 */
final class EnrichEpcisDocumentShippingFields
{
    public function __construct(
        private readonly ResolveGlnToMasterData $resolveGln,
        private readonly EnsureCatalogPartiesFromEpcisLocations $ensureCatalogParties,
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $locations
     */
    public function handle(EpcisDocument $document, ?array $locations = null): EpcisDocument
    {
        if (! Schema::hasColumn('epcis_documents', 'customer_po')) {
            return $document;
        }

        $eventsQuery = Schema::hasColumn('epcis_events', 'ingest_generation')
            ? $document->activeEvents()
            : $document->events();

        $events = $eventsQuery
            ->with(['bizTransactions', 'parties'])
            ->orderBy('id')
            ->get();

        $shippingEvent = $this->findBestEvent($events);

        $customerPo = null;
        $asnNumber = null;

        $bizTransactions = $this->bizTransactionsForDocument($shippingEvent, $events);
        foreach ($bizTransactions as $bt) {
            $typeUri = strtolower((string) ($bt->type_uri ?? ''));
            $segment = $this->urnLastSegment((string) ($bt->value ?? ''));
            if ($segment === null) {
                continue;
            }

            if ($customerPo === null && (str_ends_with($typeUri, ':po') || str_contains($typeUri, ':btt:po'))) {
                $customerPo = $segment;
            }

            if ($asnNumber === null && str_ends_with($typeUri, ':desadv')) {
                $asnNumber = $segment;
            }
        }

        $partyGlns = [
            'source_owning_party_gln' => null,
            'source_location_gln' => null,
            'destination_owning_party_gln' => null,
            'destination_location_gln' => null,
        ];

        if ($shippingEvent !== null) {
            $partyGlns = $this->extractPartyGlns($shippingEvent->parties);
        }

        $shipFromGln = $partyGlns['source_location_gln']
            ?? $partyGlns['source_owning_party_gln']
            ?? $this->normalizeGln($document->sender_gln);
        $shipToGln = $partyGlns['destination_location_gln']
            ?? $partyGlns['destination_owning_party_gln']
            ?? $this->normalizeGln($document->receiver_gln);
        $destOwningPartyGln = $partyGlns['destination_owning_party_gln']
            ?? $this->normalizeGln($document->receiver_gln);
        $sourceOwningPartyGln = $partyGlns['source_owning_party_gln']
            ?? $this->normalizeGln($document->sender_gln);

        $locations = $this->resolveLocations($document, $locations);
        $locationByGln = $this->indexLocationsByGln($locations);

        $this->ensureCatalogParties->handle($locations, [
            'source_owning_party_gln' => $sourceOwningPartyGln,
            'source_location_gln' => $partyGlns['source_location_gln'] ?? $shipFromGln,
            'destination_owning_party_gln' => $destOwningPartyGln,
            'destination_location_gln' => $partyGlns['destination_location_gln'] ?? $shipToGln,
        ], $this->productManufacturerRoleContext($document));

        $shipFromName = $this->nameForGln($locationByGln, $sourceOwningPartyGln);
        $shipFromSiteName = $this->nameForGln($locationByGln, $shipFromGln);
        $shipToName = $this->nameForGln($locationByGln, $destOwningPartyGln);
        $shipToSiteName = $this->nameForGln($locationByGln, $shipToGln);

        $shipFromSiteId = null;
        if ($shipFromGln !== null) {
            $resolvedFrom = $this->resolveGln->handle($shipFromGln);
            $shipFromSiteId = $resolvedFrom['site_id'];
        }

        $shipToSiteId = null;
        if ($shipToGln !== null) {
            $resolvedTo = $this->resolveGln->handle($shipToGln);
            $shipToSiteId = $resolvedTo['site_id'];
        }

        $shipToPartnerId = null;
        if ($destOwningPartyGln !== null) {
            $shipToPartnerId = $this->resolveGln->handle($destOwningPartyGln)['trading_partner_id'];
        }

        // Seller stays SBDH sender / existing trading_partner_id — never overwrite from ship-to.
        $tradingPartnerId = $document->trading_partner_id;
        if ($tradingPartnerId === null && filled($document->sender_gln)) {
            $tradingPartnerId = $this->resolveGln->handle((string) $document->sender_gln)['trading_partner_id'];
        }

        $attributes = [
            'customer_po' => $customerPo,
            'asn_number' => $asnNumber,
            'ship_from_gln' => $shipFromGln,
            'ship_to_gln' => $shipToGln,
            // Keep authored/persisted site when XML enrichment cannot resolve a GLN.
            'ship_from_site_id' => $shipFromSiteId ?? $document->ship_from_site_id,
            'ship_to_site_id' => $shipToSiteId ?? $document->ship_to_site_id,
            'ship_to_partner_id' => $shipToPartnerId,
            'trading_partner_id' => $tradingPartnerId,
        ];

        if (Schema::hasColumn('epcis_documents', 'ship_from_name')) {
            $attributes['ship_from_name'] = $shipFromName;
            $attributes['ship_from_site_name'] = $shipFromSiteName;
            $attributes['ship_to_name'] = $shipToName;
            $attributes['ship_to_site_name'] = $shipToSiteName;
        }

        $document->forceFill($attributes)->save();

        return $document->refresh();
    }

    /**
     * @param  list<array<string, mixed>>|null  $locations
     * @return list<array<string, mixed>>
     */
    private function resolveLocations(EpcisDocument $document, ?array $locations): array
    {
        if ($locations !== null) {
            return $locations;
        }

        if (blank($document->payload_path)) {
            return [];
        }

        try {
            $absolute = $document->materializePayloadPath();
        } catch (\Throwable) {
            return [];
        }

        $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath($absolute) ?: $absolute;
        $cleanupTemp = str_starts_with(
            $resolved,
            rtrim($tempDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
        );

        try {
            if (! is_readable($absolute)) {
                return [];
            }

            $parsed = app(EpcisXmlReader::class)->parse($absolute);

            return $parsed['locations'] ?? [];
        } finally {
            if ($cleanupTemp && is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @return array<string, array<string, mixed>>
     */
    private function indexLocationsByGln(array $locations): array
    {
        $map = [];

        foreach ($locations as $location) {
            $gln = $this->normalizeGln($location['gln'] ?? null);
            if ($gln === null) {
                continue;
            }
            $map[$gln] = $location;
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $locationByGln
     */
    private function nameForGln(array $locationByGln, ?string $gln): ?string
    {
        if ($gln === null) {
            return null;
        }

        $name = $locationByGln[$gln]['name'] ?? null;

        return filled($name) ? (string) $name : null;
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     */
    private function findBestEvent(Collection $events): ?EpcisEvent
    {
        $shipping = $events->first(function (EpcisEvent $event): bool {
            if ($event->event_type !== 'ObjectEvent') {
                return false;
            }

            $bizStep = strtolower((string) ($event->biz_step ?? ''));

            return $bizStep !== '' && str_contains($bizStep, 'shipping');
        });

        if ($shipping !== null) {
            return $shipping;
        }

        return $events->first(function (EpcisEvent $event): bool {
            if ($event->bizTransactions->isNotEmpty()) {
                return true;
            }

            return $event->parties->contains(
                fn (EventParty $party): bool => in_array($party->party_role, ['source', 'destination'], true)
            );
        });
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return Collection<int, EventBizTransaction>
     */
    private function bizTransactionsForDocument(?EpcisEvent $preferred, Collection $events): Collection
    {
        if ($preferred !== null && $preferred->bizTransactions->isNotEmpty()) {
            return $preferred->bizTransactions;
        }

        return $events->flatMap(fn (EpcisEvent $event) => $event->bizTransactions);
    }

    /**
     * @param  Collection<int, EventParty>  $parties
     * @return array{
     *     source_owning_party_gln: ?string,
     *     source_location_gln: ?string,
     *     destination_owning_party_gln: ?string,
     *     destination_location_gln: ?string,
     * }
     */
    private function extractPartyGlns(Collection $parties): array
    {
        $sourceLocation = null;
        $sourceOwning = null;
        $destLocation = null;
        $destOwning = null;

        foreach ($parties as $party) {
            $gln = $this->normalizeGln($party->gln);
            if ($gln === null) {
                continue;
            }

            $extra = $party->extra_json;
            if (! is_array($extra)) {
                $extra = [];
            }
            $type = strtolower((string) ($extra['source_dest_type'] ?? ''));

            if ($party->party_role === 'source') {
                if ($type === 'location') {
                    $sourceLocation ??= $gln;
                } elseif ($type === 'owning_party') {
                    $sourceOwning ??= $gln;
                }
            }

            if ($party->party_role === 'destination') {
                if ($type === 'location') {
                    $destLocation ??= $gln;
                } elseif ($type === 'owning_party') {
                    $destOwning ??= $gln;
                }
            }
        }

        return [
            'source_owning_party_gln' => $sourceOwning,
            'source_location_gln' => $sourceLocation,
            'destination_owning_party_gln' => $destOwning,
            'destination_location_gln' => $destLocation,
        ];
    }

    /**
     * @return array{
     *     product_manufacturer_name?: ?string,
     *     product_manufacturer_gln?: ?string,
     * }
     */
    private function productManufacturerRoleContext(EpcisDocument $document): array
    {
        $manufacturerName = $document->fileProductClassesByGtin()
            ->pluck('manufacturer')
            ->filter(fn (mixed $name): bool => filled($name))
            ->map(fn (mixed $name): string => trim((string) $name))
            ->unique()
            ->first();

        if ($manufacturerName === null) {
            return [];
        }

        return [
            'product_manufacturer_name' => $manufacturerName,
        ];
    }

    private function urnLastSegment(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, ':')) {
            $parts = explode(':', $value);
            $segment = (string) end($parts);

            return $segment !== '' ? $segment : null;
        }

        return $value;
    }

    private function normalizeGln(mixed $gln): ?string
    {
        if ($gln === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $gln) ?? '';

        return strlen($normalized) === 13 ? $normalized : (strlen($normalized) > 0 ? $normalized : null);
    }
}
