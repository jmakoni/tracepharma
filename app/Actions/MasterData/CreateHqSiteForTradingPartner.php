<?php

namespace App\Actions\MasterData;

use App\Enums\PartnerType;
use App\Filament\App\Support\FdaPicker;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaTenantLink;

/**
 * Create a headquarters site for a tenant trading partner when none exists.
 */
final class CreateHqSiteForTradingPartner
{
    /**
     * Null when the partner GLN already belongs to another site — sites.gln is
     * UNIQUE, so creating would fail. Typical cause: the GLN is one of the
     * organization's own facilities.
     */
    public function handle(TradingPartner $partner, ?string $pick = null): ?Site
    {
        $glnOnlyStamp = $this->shouldStampByGlnOnly($partner, $pick);

        $existing = Site::query()
            ->where('trading_partner_id', $partner->id)
            ->where('is_headquarters', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $glnOwner = filled($partner->gln)
            ? Site::query()->where('gln', $partner->gln)->first()
            : null;

        if ($glnOwner !== null) {
            if ((int) $glnOwner->trading_partner_id !== (int) $partner->id) {
                return null;
            }

            $glnOwner->forceFill(['is_headquarters' => true])->save();
            $this->copyAtpLicenses($partner, $glnOwner, $glnOnlyStamp);

            return $glnOwner;
        }

        $site = tap(
            Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => (DisplayName::clean($partner->name) ?? $partner->name).' - HQ Site',
                'is_headquarters' => true,
                'description' => $partner->description,
                'street_address' => $partner->street_address,
                'street_address_2' => $partner->street_address_2,
                'city' => $partner->city,
                'state' => $partner->state,
                'zipcode' => $partner->zipcode,
                'country_code' => $partner->country_code ?? 'US',
                'timezone' => $partner->timezone,
                'gln' => $partner->gln,
                'altitude' => $partner->altitude,
                'latitude' => $partner->latitude,
                'longitude' => $partner->longitude,
                'logo' => $partner->logo,
                'is_active' => true,
                'is_organization_facility' => false,
            ]),
            fn (Site $created) => $created->refresh(),
        );

        $this->copyAtpLicenses($partner, $site, $glnOnlyStamp);

        return $site;
    }

    private function shouldStampByGlnOnly(TradingPartner $partner, ?string $pick): bool
    {
        $parsed = FdaPicker::parseTradingPartnerPick($pick);

        if ($parsed === null || $parsed['type'] === 'org') {
            return false;
        }

        $locationGln = match ($parsed['type']) {
            'est' => FdaEstablishment::query()->whereKey($parsed['id'])->value('gln'),
            'wdd' => FdaWddFacility::query()->whereKey($parsed['id'])->value('gln'),
        };

        return ! FdaPicker::pickedLocationSharesHqGln($partner->gln, is_string($locationGln) ? $locationGln : null);
    }

    private function copyAtpLicenses(TradingPartner $partner, Site $site, bool $glnOnlyStamp = false): void
    {
        $this->stampHqIdentity($partner, $site, $glnOnlyStamp);
        $site->refresh();

        app(CopyFdaWddLicensesToTenantSite::class)->handle($site);
    }

    private function stampHqIdentity(TradingPartner $partner, Site $site, bool $glnOnlyStamp = false): void
    {
        $organizationId = FdaTenantLink::organizationId($partner);

        if ($organizationId === null) {
            return;
        }

        if ($partner->partner_type !== PartnerType::Manufacturer) {
            $facility = $this->resolveWddFacility($organizationId, $site, $glnOnlyStamp);

            if ($facility !== null) {
                FdaTenantLink::stampSiteFromWddFacility($site, $facility);

                return;
            }
        }

        $establishment = $this->resolveEstablishment($organizationId, $site, $glnOnlyStamp);

        if ($establishment !== null) {
            FdaTenantLink::stampSiteFromEstablishment($site, $establishment);
        }
    }

    private function resolveWddFacility(int $organizationId, Site $site, bool $glnOnlyStamp = false): ?FdaWddFacility
    {
        if (filled($site->gln)) {
            $byGln = FdaWddFacility::query()
                ->where('fda_organization_id', $organizationId)
                ->where('gln', $site->gln)
                ->first();

            if ($byGln !== null) {
                return $byGln;
            }
        }

        if ($glnOnlyStamp) {
            return null;
        }

        return FdaWddFacility::query()
            ->where('fda_organization_id', $organizationId)
            ->where('is_headquarters', true)
            ->orderBy('id')
            ->first();
    }

    private function resolveEstablishment(int $organizationId, Site $site, bool $glnOnlyStamp = false): ?FdaEstablishment
    {
        if (filled($site->gln)) {
            $byGln = FdaEstablishment::query()
                ->where('fda_organization_id', $organizationId)
                ->where('gln', $site->gln)
                ->first();

            if ($byGln !== null) {
                return $byGln;
            }
        }

        if ($glnOnlyStamp) {
            return null;
        }

        return FdaEstablishment::query()
            ->where('fda_organization_id', $organizationId)
            ->where('is_headquarters', true)
            ->orderBy('id')
            ->first();
    }
}
