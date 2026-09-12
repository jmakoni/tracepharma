<?php

namespace App\Support\MasterData;

use App\Enums\PartnerType;
use App\Models\Fda\FdaEstablishment;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Fda\FdaTenantLink;
use App\Support\Places\UsState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Only the manufacturer's own plant address is authorized without a WDD license.
 * Other sites (DCs, 3PLs, extra warehouses) must have a WDD/3PL license for the
 * tenant organization jurisdictions (footprint or preferred receiving-state fallback).
 */
final class ManufacturerDecrsAuthorization
{
    public static function matches(Site $site): bool
    {
        if (filled($site->fda_wdd_facility_id)) {
            return false;
        }

        $partner = $site->relationLoaded('tradingPartner')
            ? $site->tradingPartner
            : $site->tradingPartner()->first();

        if (! $partner instanceof TradingPartner || $partner->partner_type !== PartnerType::Manufacturer) {
            return false;
        }

        $organizationId = FdaTenantLink::organizationId($partner);

        if ($organizationId === null) {
            return false;
        }

        if (! self::siteHasPlace($site)) {
            return false;
        }

        if (self::samePlace(
            $site->street_address,
            $site->city,
            $site->state,
            $site->zipcode,
            $site->country_code,
            $partner->street_address,
            $partner->city,
            $partner->state,
            $partner->zipcode,
            $partner->country_code,
        )) {
            return true;
        }

        $registered = self::registeredEstablishments($organizationId, $partner);

        if ($registered->isEmpty()) {
            return false;
        }

        if (filled($site->fda_establishment_id)
            && $registered->contains(fn (FdaEstablishment $est): bool => (int) $est->id === (int) $site->fda_establishment_id)) {
            return true;
        }

        return $registered->contains(fn (FdaEstablishment $est): bool => self::samePlace(
            $site->street_address,
            $site->city,
            $site->state,
            $site->zipcode,
            $site->country_code,
            $est->street_address,
            $est->city,
            $est->state_province,
            $est->postal_code,
            $est->country_code,
        ));
    }

    /**
     * @param  Builder<Site>  $sites
     * @return list<int>
     */
    public static function siteIds(Builder $sites): array
    {
        $candidates = (clone $sites)
            ->with(['tradingPartner:id,partner_type,fda_organization_id,name,street_address,city,state,zipcode,country_code'])
            ->get();

        return $candidates
            ->filter(fn (Site $site): bool => self::matches($site))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, FdaEstablishment>
     */
    private static function registeredEstablishments(int $organizationId, TradingPartner $partner): Collection
    {
        return FdaEstablishment::query()
            ->where('is_active', true)
            ->where('exclusion_flag', false)
            ->where(function (Builder $query): void {
                $query->whereNull('expiration_date')
                    ->orWhereDate('expiration_date', '>=', now()->toDateString());
            })
            ->where(function (Builder $query) use ($organizationId, $partner): void {
                $query->where('fda_organization_id', $organizationId);

                if (filled($partner->name)) {
                    $query->orWhere('firm_name', $partner->name)
                        ->orWhere('name', $partner->name);
                }
            })
            ->get([
                'id',
                'street_address',
                'city',
                'state_province',
                'postal_code',
                'country_code',
            ]);
    }

    private static function samePlace(
        ?string $leftStreet,
        ?string $leftCity,
        ?string $leftState,
        ?string $leftPostal,
        ?string $leftCountry,
        ?string $rightStreet,
        ?string $rightCity,
        ?string $rightState,
        ?string $rightPostal,
        ?string $rightCountry,
    ): bool {
        $left = self::placeKey($leftStreet, $leftCity, $leftState, $leftPostal, $leftCountry);
        $right = self::placeKey($rightStreet, $rightCity, $rightState, $rightPostal, $rightCountry);

        return $left !== '' && $left === $right;
    }

    private static function placeKey(
        ?string $street,
        ?string $city,
        ?string $state,
        ?string $postal,
        ?string $country,
    ): string {
        $streetNorm = self::expandStreet(self::normalizePart($street));
        $cityNorm = self::expandCity(self::normalizePart($city));
        $stateNorm = UsState::normalize($state) ?? self::normalizePart($state);
        $countryNorm = match (strtoupper(trim((string) $country))) {
            'USA', 'UNITED STATES', 'US', '' => 'US',
            default => strtoupper(trim((string) $country)),
        };
        $postalNorm = preg_replace('/\D/', '', (string) $postal) ?? '';
        $postalNorm = substr($postalNorm, 0, 5);

        if ($streetNorm === '' && $cityNorm === '' && $postalNorm === '') {
            return '';
        }

        return implode('|', [$streetNorm, $cityNorm, $stateNorm, $postalNorm, $countryNorm]);
    }

    private static function normalizePart(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Z0-9\s]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function expandStreet(string $value): string
    {
        return (string) preg_replace(
            [
                '/\bDRIVE\b/',
                '/\bSTREET\b/',
                '/\bAVENUE\b/',
                '/\bBOULEVARD\b/',
                '/\bROAD\b/',
            ],
            ['DR', 'ST', 'AVE', 'BLVD', 'RD'],
            $value,
        );
    }

    private static function expandCity(string $value): string
    {
        return (string) preg_replace(
            [
                '/\bMOUNT\b/',
                '/\bFORT\b/',
                '/\bSAINT\b/',
            ],
            ['MT', 'FT', 'ST'],
            $value,
        );
    }

    private static function siteHasPlace(Site $site): bool
    {
        return filled($site->street_address) || filled($site->city) || filled($site->zipcode);
    }
}
