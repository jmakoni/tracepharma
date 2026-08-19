<?php

namespace App\Actions\MasterData;

use App\Models\LocationDevice;
use App\Models\Site;
use App\Support\Gs1\OrganizationSglnPrefixes;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Re-author our own locations' SGLNs after the organization GS1 Company Prefix changes.
 *
 * The prefix is where our GLNs split, so it is also the only thing that can say which
 * SGLN identifies one of our docks. Change it and every SGLN derived from the old one
 * names a location that is no longer on our records — while still round-tripping to
 * the same 13 digits, which is why nothing downstream notices. So the stored value
 * cannot be left to win over the new prefix the way a partner's stated SGLN does.
 *
 * Only organization facilities and the location devices on them are touched. A
 * partner's SGLN is the partner's to state, and our prefix says nothing about it.
 *
 * @see SglnResolution for the rule this restores rows to
 */
final class RederiveOrganizationSglns
{
    /**
     * @return array{sites: int, location_devices: int}
     */
    public function handle(?string $companyPrefix, bool $dryRun = false): array
    {
        $prefix = TenantSettings::normalizeCompanyPrefix($companyPrefix);

        return [
            'sites' => $this->rederiveSites($prefix, $dryRun),
            'location_devices' => $this->rederiveLocationDevices($prefix, $dryRun),
        ];
    }

    private function rederiveSites(?string $prefix, bool $dryRun): int
    {
        $changed = 0;

        Site::query()
            ->ownedByOrganization()
            ->chunkById(500, function (Collection $sites) use ($prefix, $dryRun, &$changed): void {
                foreach ($sites as $site) {
                    $changed += $this->rederive($site, $prefix, $dryRun) ? 1 : 0;
                }
            });

        return $changed;
    }

    private function rederiveLocationDevices(?string $prefix, bool $dryRun): int
    {
        $changed = 0;

        LocationDevice::query()
            ->whereIn('site_id', Site::query()->ownedByOrganization()->select('sites.id'))
            ->chunkById(500, function (Collection $devices) use ($prefix, $dryRun, &$changed): void {
                foreach ($devices as $device) {
                    $changed += $this->rederive($device, $prefix, $dryRun) ? 1 : 0;
                }
            });

        return $changed;
    }

    private function rederive(Model $location, ?string $prefix, bool $dryRun): bool
    {
        $current = $location->getAttribute('sgln');
        $current = is_string($current) && $current !== '' ? $current : null;
        $gln = $location->getAttribute('gln');
        $next = $this->sglnFor(is_string($gln) ? $gln : null, $current, $prefix, $location);

        if ($next === $current) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $location->forceFill(['sgln' => $next])->save();

        // A tenant database still carrying the legacy generated column drops the write,
        // so report what the row now holds rather than what we asked it to hold.
        return $location->wasChanged('sgln');
    }

    private function sglnFor(?string $gln, ?string $current, ?string $prefix, Model $location): ?string
    {
        $extension = SglnResolution::extensionOf($current, $gln);
        $fromPrefix = SglnResolution::fromCompanyPrefix($gln, $prefix, $extension);

        if ($fromPrefix !== null) {
            return $fromPrefix;
        }

        if ($location instanceof Site) {
            return SglnResolution::resolve(
                $gln,
                $current !== null ? [$current] : [],
                $prefix,
                OrganizationSglnPrefixes::forSite($location),
            ) ?? SglnResolution::fromPrefixLength($gln, $prefix, $extension);
        }

        return SglnResolution::resolve($gln, $current !== null ? [$current] : [], $prefix);
    }
}
