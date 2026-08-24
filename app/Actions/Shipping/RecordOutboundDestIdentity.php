<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Rules\ValidGln;
use App\Support\Gs1\SglnRules;
use DomainException;
use Illuminate\Support\Arr;

/**
 * Collect dest GLN/SGLN onto a customer site for a ship order. Never writes
 * partner-level identity and never invents an SGLN from 13 digits.
 */
final class RecordOutboundDestIdentity
{
    public function __construct(
        private readonly UpdateOutboundShippingParty $updateOutboundShippingParty,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(OutboundShippingSession $session, array $data): OutboundShippingSession
    {
        $partnerId = Arr::has($data, 'trading_partner_id')
            ? ($data['trading_partner_id'] !== null ? (int) $data['trading_partner_id'] : null)
            : ($session->trading_partner_id !== null ? (int) $session->trading_partner_id : null);

        if ($partnerId === null) {
            throw new DomainException('Select a customer before recording a destination.');
        }

        $partner = TradingPartner::query()
            ->whereKey($partnerId)
            ->where('is_active', true)
            ->first();

        if ($partner === null) {
            throw new DomainException('Selected trading partner was not found or is inactive.');
        }

        $destGln = $this->normalizeOptionalGln($data['dest_gln'] ?? null);
        $destSgln = is_string($data['dest_sgln'] ?? null) ? trim((string) $data['dest_sgln']) : '';
        $destSgln = $destSgln !== '' ? $destSgln : null;

        if ($destGln !== null || $destSgln !== null) {
            if ($destGln === null || $destSgln === null) {
                throw new DomainException('Paste both the customer GLN and the SGLN that encodes it.');
            }

            $mismatch = SglnRules::check($destSgln, $destGln);
            if ($mismatch !== null) {
                throw new DomainException($mismatch);
            }
        }

        $requestedSiteId = Arr::has($data, 'ship_to_site_id')
            ? ($data['ship_to_site_id'] !== null ? (int) $data['ship_to_site_id'] : null)
            : ($session->ship_to_site_id !== null ? (int) $session->ship_to_site_id : null);

        $site = $this->resolveDestSite($partner, $requestedSiteId);

        if ($destGln !== null && $destSgln !== null) {
            $site->forceFill([
                'gln' => $destGln,
                'sgln' => $destSgln,
            ])->save();
            $site = $site->fresh() ?? $site;
        }

        return $this->updateOutboundShippingParty->handle($session, [
            'trading_partner_id' => $partnerId,
            'ship_to_site_id' => (int) $site->getKey(),
            'ship_to_gln' => filled($site->gln) ? (string) $site->gln : null,
        ]);
    }

    private function resolveDestSite(TradingPartner $partner, ?int $requestedSiteId): Site
    {
        $sites = Site::query()
            ->where('trading_partner_id', (int) $partner->getKey())
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($requestedSiteId !== null) {
            $selected = $sites->first(
                static fn (Site $site): bool => (int) $site->getKey() === $requestedSiteId,
            );

            if ($selected === null) {
                throw new DomainException('Selected ship-to site was not found or is inactive.');
            }

            return $selected;
        }

        if ($sites->count() > 1) {
            throw new DomainException('Pick the destination site before pasting a GLN or SGLN.');
        }

        if ($sites->count() === 1) {
            return $sites->first();
        }

        return Site::query()->create([
            'trading_partner_id' => (int) $partner->getKey(),
            'name' => (string) $partner->name,
            'is_active' => true,
            'is_organization_facility' => false,
        ]);
    }

    private function normalizeOptionalGln(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = ValidGln::normalize($value);

        if ($normalized === null) {
            throw new DomainException('Destination GLN must be a valid 13-digit GS1 GLN (check digit failed).');
        }

        return $normalized;
    }
}
