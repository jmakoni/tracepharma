<?php

namespace App\Actions\MasterData;

use App\Filament\App\Support\FdaPicker;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Fda\FdaPrefill;
use App\Support\Fda\FdaTenantLink;
use App\Support\MasterData\PartnerSiteCreate;
use App\Filament\Notifications\Notification;
use Throwable;

/**
 * After HQ create, mirror an explicit FDA plant/warehouse pick as a second partner site.
 */
final class CreatePickedFdaSiteForTradingPartner
{
    public function handle(TradingPartner $partner, ?string $pick): ?Site
    {
        $parsed = FdaPicker::parseTradingPartnerPick($pick);

        if ($parsed === null || $parsed['type'] === 'org') {
            return null;
        }

        $hq = Site::query()
            ->where('trading_partner_id', $partner->id)
            ->where('is_headquarters', true)
            ->first();

        return match ($parsed['type']) {
            'est' => $this->createFromEstablishment($partner, $hq, $parsed['id']),
            'wdd' => $this->createFromWddFacility($partner, $hq, $parsed['id']),
        };
    }

    private function createFromEstablishment(TradingPartner $partner, ?Site $hq, int $establishmentId): ?Site
    {
        $establishment = FdaEstablishment::query()->find($establishmentId);

        if ($establishment === null) {
            $this->warn('That FDA establishment no longer exists. Add the plant from the partner sites tab.');

            return null;
        }

        if ($this->belongsToAnotherOrganization($partner, $establishment->fda_organization_id)) {
            $this->warn('That establishment belongs to a different organization.');

            return null;
        }

        $existing = $this->existingPartnerSite($partner, 'fda_establishment_id', $establishment->id);

        if ($existing !== null && ! $this->isHqStandingInForDifferentPlace($existing, $partner, $establishment->gln)) {
            return $existing;
        }

        if ($existing !== null) {
            $this->unstampHq($existing);
        }

        if (FdaPicker::pickedLocationSharesHqGln($partner->gln, $establishment->gln)) {
            if ($hq !== null) {
                FdaTenantLink::stampSiteFromEstablishment($hq, $establishment);
            }

            return $hq;
        }

        $problem = PartnerSiteCreate::identityProblems($establishment->gln, $establishment->code);

        if ($problem !== null) {
            $this->warn($problem);

            return null;
        }

        return $this->createSite($partner, FdaPrefill::establishmentAttributes($establishment), 'Plant');
    }

    private function createFromWddFacility(TradingPartner $partner, ?Site $hq, int $facilityId): ?Site
    {
        $facility = FdaWddFacility::query()->find($facilityId);

        if ($facility === null) {
            $this->warn('That WDD facility no longer exists. Add the warehouse from the partner sites tab.');

            return null;
        }

        if ($this->belongsToAnotherOrganization($partner, $facility->fda_organization_id)) {
            $this->warn('That WDD facility belongs to a different organization.');

            return null;
        }

        $existing = $this->existingPartnerSite($partner, 'fda_wdd_facility_id', $facility->id);

        if ($existing !== null && ! $this->isHqStandingInForDifferentPlace($existing, $partner, $facility->gln)) {
            return $existing;
        }

        if ($existing !== null) {
            $this->unstampHq($existing);
        }

        if (FdaPicker::pickedLocationSharesHqGln($partner->gln, $facility->gln)) {
            if ($hq !== null) {
                FdaTenantLink::stampSiteFromWddFacility($hq, $facility);
                try {
                    app(CopyFdaWddLicensesToTenantSite::class)->handle($hq);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->warn('Headquarters was linked to the warehouse, but its licenses could not be copied.');
                }
            }

            return $hq;
        }

        $problem = PartnerSiteCreate::identityProblems($facility->gln, $facility->code);

        if ($problem !== null) {
            $this->warn($problem);

            return null;
        }

        $site = $this->createSite($partner, FdaPrefill::wddFacilityAttributes($facility), 'Warehouse');

        if ($site !== null) {
            try {
                app(CopyFdaWddLicensesToTenantSite::class)->handle($site);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn('The warehouse site was created, but its licenses could not be copied. Add them from the partner sites tab.');
            }
        }

        return $site;
    }

    /**
     * @param  array<string, mixed>  $prefill
     */
    private function createSite(TradingPartner $partner, array $prefill, string $nameFallback): ?Site
    {
        try {
            return Site::query()->create($this->siteAttributes($partner, $prefill, $nameFallback));
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('The picked FDA site could not be created. Add it from the partner sites tab.');

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $prefill
     * @return array<string, mixed>
     */
    private function siteAttributes(TradingPartner $partner, array $prefill, string $nameFallback): array
    {
        $prefill['trading_partner_id'] = $partner->getKey();
        $prefill['is_headquarters'] = false;
        $prefill['is_active'] = true;
        $prefill['name'] = filled($prefill['name'] ?? null) ? $prefill['name'] : $nameFallback;
        $prefill['country_code'] = filled($prefill['country_code'] ?? null) ? $prefill['country_code'] : 'US';

        return Site::syncOrganizationFacilityFlag($prefill);
    }

    private function unstampHq(Site $hq): void
    {
        $hq->forceFill([
            'fda_establishment_id' => null,
            'fda_wdd_facility_id' => null,
        ])->save();
    }

    private function existingPartnerSite(TradingPartner $partner, string $stampColumn, int $stampId): ?Site
    {
        return Site::query()
            ->where('trading_partner_id', $partner->id)
            ->where($stampColumn, $stampId)
            ->first();
    }

    private function isHqStandingInForDifferentPlace(Site $existing, TradingPartner $partner, ?string $locationGln): bool
    {
        return (bool) $existing->is_headquarters
            && ! FdaPicker::pickedLocationSharesHqGln($partner->gln, $locationGln);
    }

    private function belongsToAnotherOrganization(TradingPartner $partner, mixed $organizationId): bool
    {
        $partnerOrganizationId = FdaTenantLink::organizationId($partner);

        return $partnerOrganizationId !== null
            && $organizationId !== null
            && (int) $organizationId !== $partnerOrganizationId;
    }

    private function warn(string $body): void
    {
        Notification::make()
            ->title('Picked FDA site not created')
            ->body($body)
            ->warning()
            ->persistent()
            ->send();
    }
}
