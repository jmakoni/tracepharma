<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Support\Auth\SiteAccess;
use App\Support\TenantSettings;

/**
 * Resolve ship-from / ship-to site ids from an EPCIS upload before persistence.
 *
 * Mirrors {@see \App\Actions\Epcis\EnrichEpcisDocumentShippingFields} party + SBDH
 * fallbacks so API SiteAccess gates align with post-parse enrichment.
 */
final class ResolveEpcisUploadShippingSites
{
    public function __construct(
        private readonly EpcisXmlReader $reader,
    ) {}

    /**
     * @return array{ship_from_site_id: ?int, ship_to_site_id: ?int}
     */
    public function handle(string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return [
                'ship_from_site_id' => null,
                'ship_to_site_id' => null,
            ];
        }

        try {
            $parsed = $this->reader->parse($absolutePath);
        } catch (\Throwable) {
            return [
                'ship_from_site_id' => null,
                'ship_to_site_id' => null,
            ];
        }

        $partyGlns = $this->extractPartyGlns($parsed['events'] ?? []);
        $senderGln = $this->normalizeGln($parsed['sender_gln'] ?? null);
        $receiverGln = $this->normalizeGln($parsed['receiver_gln'] ?? null);

        $shipFromGln = $partyGlns['source_location_gln']
            ?? $partyGlns['source_owning_party_gln']
            ?? $senderGln;
        $shipToGln = $partyGlns['destination_location_gln']
            ?? $partyGlns['destination_owning_party_gln']
            ?? $receiverGln;

        $matchInboundShipToSite = TenantSettings::forTenant(tenant())->matchInboundShipToSite();

        return [
            'ship_from_site_id' => $this->resolveOrganizationSiteId($shipFromGln),
            'ship_to_site_id' => $matchInboundShipToSite
                ? $this->resolveOrganizationSiteId($shipToGln)
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{
     *     source_owning_party_gln: ?string,
     *     source_location_gln: ?string,
     *     destination_owning_party_gln: ?string,
     *     destination_location_gln: ?string,
     * }
     */
    private function extractPartyGlns(array $events): array
    {
        $sourceLocation = null;
        $sourceOwning = null;
        $destLocation = null;
        $destOwning = null;

        $shippingEvent = $this->findBestEvent($events);

        if ($shippingEvent === null) {
            return [
                'source_owning_party_gln' => null,
                'source_location_gln' => null,
                'destination_owning_party_gln' => null,
                'destination_location_gln' => null,
            ];
        }

        foreach ($shippingEvent['parties'] ?? [] as $party) {
            if (! is_array($party)) {
                continue;
            }

            $gln = $this->normalizeGln($party['gln'] ?? null);
            if ($gln === null) {
                continue;
            }

            $type = strtolower((string) ($party['source_dest_type'] ?? ''));
            $role = (string) ($party['party_role'] ?? '');

            if ($role === 'source') {
                if ($type === 'location') {
                    $sourceLocation ??= $gln;
                } elseif ($type === 'owning_party') {
                    $sourceOwning ??= $gln;
                }
            }

            if ($role === 'destination') {
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
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private function findBestEvent(array $events): ?array
    {
        foreach ($events as $event) {
            if (! is_array($event) || ($event['event_type'] ?? '') !== 'ObjectEvent') {
                continue;
            }

            $bizStep = strtolower((string) ($event['biz_step'] ?? ''));

            if ($bizStep !== '' && str_contains($bizStep, 'shipping')) {
                return $event;
            }
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if (($event['biz_transactions'] ?? []) !== []) {
                return $event;
            }

            foreach ($event['parties'] ?? [] as $party) {
                if (! is_array($party)) {
                    continue;
                }

                if (in_array($party['party_role'] ?? '', ['source', 'destination'], true)) {
                    return $event;
                }
            }
        }

        return null;
    }

    private function resolveOrganizationSiteId(?string $gln): ?int
    {
        if ($gln === null) {
            return null;
        }

        return SiteAccess::organizationSiteIdForGln($gln);
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
