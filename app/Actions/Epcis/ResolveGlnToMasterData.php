<?php

namespace App\Actions\Epcis;

use App\Models\LocationDevice;
use App\Models\ReadPoint;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Fda\DeaRegistration;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sgln;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolve a location token (GLN, SGLN-derived GLN, or DEA registration) to tenant master-data parties/locations without auto-creating rows.
 */
final class ResolveGlnToMasterData
{
    /**
     * @return array{
     *     gln: string,
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int,
     *     trading_partner: ?TradingPartner,
     *     site: ?Site,
     *     location_device: ?LocationDevice,
     *     read_point: ?ReadPoint
     * }
     */
    public function handle(string $token): array
    {
        $empty = $this->emptyResult('');

        $glnNormalized = Sgln::normalizeGln($token);
        if ($glnNormalized !== null) {
            $result = $this->resolveGlnLadder($glnNormalized);
            if ($this->hasMasterDataMatch($result)) {
                return $result;
            }

            if (! $this->hasValidGlnCheckDigit($glnNormalized)) {
                $deaNormalized = DeaRegistration::normalize($token) ?? $glnNormalized;
                $deaResult = $this->resolveByDeaRegistration($deaNormalized);
                if ($deaResult !== null) {
                    return $deaResult;
                }
            }

            return $result;
        }

        $deaParsed = DeaRegistration::parseFromLocationToken($token);
        if ($deaParsed !== null) {
            $deaResult = $this->resolveByDeaRegistration($deaParsed);
            if ($deaResult !== null) {
                return $deaResult;
            }

            return $this->emptyResult('');
        }

        $digits = preg_replace('/\D+/', '', $token) ?? '';

        return $this->emptyResult(strlen($digits) === 13 ? $digits : '');
    }

    /**
     * @return array{
     *     gln: string,
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int,
     *     trading_partner: ?TradingPartner,
     *     site: ?Site,
     *     location_device: ?LocationDevice,
     *     read_point: ?ReadPoint
     * }
     */
    private function resolveGlnLadder(string $normalized): array
    {
        $empty = $this->emptyResult($normalized);

        $partner = TradingPartner::query()->where('gln', $normalized)->first();
        if ($partner !== null) {
            return array_merge($empty, [
                'trading_partner_id' => (int) $partner->getKey(),
                'trading_partner' => $partner,
            ]);
        }

        $site = Site::query()->where('gln', $normalized)->first();
        if ($site !== null) {
            return array_merge($empty, [
                'trading_partner_id' => $site->trading_partner_id !== null ? (int) $site->trading_partner_id : null,
                'site_id' => (int) $site->getKey(),
                'trading_partner' => $site->tradingPartner,
                'site' => $site,
            ]);
        }

        $device = LocationDevice::query()->with('site.tradingPartner')->where('gln', $normalized)->first();
        if ($device !== null) {
            $deviceSite = $device->site;

            return array_merge($empty, [
                'trading_partner_id' => $deviceSite?->trading_partner_id !== null
                    ? (int) $deviceSite->trading_partner_id
                    : null,
                'site_id' => $device->site_id !== null ? (int) $device->site_id : null,
                'location_device_id' => (int) $device->getKey(),
                'trading_partner' => $deviceSite?->tradingPartner,
                'site' => $deviceSite,
                'location_device' => $device,
            ]);
        }

        $readPoint = $this->findReadPointForGln($normalized);

        if ($readPoint !== null) {
            $readPointSite = $readPoint->site;

            return array_merge($empty, [
                'trading_partner_id' => $readPointSite?->trading_partner_id !== null
                    ? (int) $readPointSite->trading_partner_id
                    : null,
                'site_id' => $readPoint->site_id !== null ? (int) $readPoint->site_id : null,
                'read_point_id' => (int) $readPoint->getKey(),
                'trading_partner' => $readPointSite?->tradingPartner,
                'site' => $readPointSite,
                'read_point' => $readPoint,
            ]);
        }

        return $empty;
    }

    /**
     * @return array{
     *     gln: string,
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int,
     *     trading_partner: ?TradingPartner,
     *     site: ?Site,
     *     location_device: ?LocationDevice,
     *     read_point: ?ReadPoint
     * }|null
     */
    private function resolveByDeaRegistration(string $normalizedDea): ?array
    {
        $site = Site::query()
            ->with('tradingPartner')
            ->whereRaw('UPPER(REPLACE(REPLACE(dea_number, " ", ""), "-", "")) = ?', [$normalizedDea])
            ->first();

        if ($site !== null) {
            $gln = (string) ($site->gln ?? '');

            return array_merge($this->emptyResult($gln), [
                'trading_partner_id' => $site->trading_partner_id !== null ? (int) $site->trading_partner_id : null,
                'site_id' => (int) $site->getKey(),
                'trading_partner' => $site->tradingPartner,
                'site' => $site,
            ]);
        }

        $partner = TradingPartner::query()
            ->whereRaw('UPPER(REPLACE(REPLACE(dea_number, " ", ""), "-", "")) = ?', [$normalizedDea])
            ->first();

        if ($partner === null) {
            return null;
        }

        $gln = (string) ($partner->gln ?? '');

        return array_merge($this->emptyResult($gln), [
            'trading_partner_id' => (int) $partner->getKey(),
            'trading_partner' => $partner,
        ]);
    }

    /**
     * @return array{
     *     gln: string,
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int,
     *     trading_partner: ?TradingPartner,
     *     site: ?Site,
     *     location_device: ?LocationDevice,
     *     read_point: ?ReadPoint
     * }
     */
    private function emptyResult(string $gln): array
    {
        return [
            'gln' => $gln,
            'trading_partner_id' => null,
            'site_id' => null,
            'location_device_id' => null,
            'read_point_id' => null,
            'trading_partner' => null,
            'site' => null,
            'location_device' => null,
            'read_point' => null,
        ];
    }

    /**
     * @param  array{
     *     gln: string,
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int,
     *     trading_partner: ?TradingPartner,
     *     site: ?Site,
     *     location_device: ?LocationDevice,
     *     read_point: ?ReadPoint
     * }  $result
     */
    private function hasMasterDataMatch(array $result): bool
    {
        return $result['trading_partner_id'] !== null
            || $result['site_id'] !== null
            || $result['location_device_id'] !== null
            || $result['read_point_id'] !== null;
    }

    private function hasValidGlnCheckDigit(string $gln13): bool
    {
        return Gtin::checkDigit(substr($gln13, 0, 12)) === substr($gln13, 12, 1);
    }

    /**
     * The read point whose SGLN encodes this GLN.
     *
     * `read_points` carries no GLN of its own — the SGLN is where its identity lives, and
     * the GLN it encodes is only readable once the company-prefix split is known. Every
     * legal split of this GLN is a prefix of the URN a read point would hold, so the
     * candidates are matched in SQL and then confirmed by parsing.
     */
    private function findReadPointForGln(string $gln): ?ReadPoint
    {
        $candidates = [];

        foreach (range(6, 11) as $companyPrefixLength) {
            $prefix = Sgln::toUrn($gln, $companyPrefixLength, '');

            if ($prefix !== null) {
                $candidates[] = $prefix;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return ReadPoint::query()
            ->with('site.tradingPartner')
            ->where(function (Builder $query) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    $query->orWhere('sgln', 'like', $candidate.'%');
                }
            })
            ->orderBy('id')
            ->get()
            ->first(function (ReadPoint $readPoint) use ($gln): bool {
                $parsed = Sgln::fromUrn((string) $readPoint->sgln);

                return $parsed !== null && $parsed['gln'] === $gln;
            });
    }
}
