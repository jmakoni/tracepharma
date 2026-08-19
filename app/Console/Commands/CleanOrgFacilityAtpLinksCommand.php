<?php

namespace App\Console\Commands;

use App\Models\AtpLicense;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repair organization facilities that were stamped with a partner's FDA site
 * by an earlier ingest. Their partner-copied ATP licenses are deactivated so
 * a partner's licenses can never make our own dock look ready.
 */
class CleanOrgFacilityAtpLinksCommand extends Command
{
    protected $signature = 'tracepharma:clean-org-facility-atp
        {--tenants=* : Tenant id(s) to clean; default all}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Deactivate partner-copied ATP licenses on organization facilities and unlink partner FDA sites';

    public function handle(): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $totals = ['licenses' => 0, 'sites' => 0];

        foreach ($tenants as $tenant) {
            /** @var array{licenses: int, sites: int} $counts */
            $counts = $tenant->run(fn (): array => $this->clean($dryRun));

            $totals['licenses'] += $counts['licenses'];
            $totals['sites'] += $counts['sites'];

            $this->line(
                "[{$tenant->id}] {$tenant->name}: licenses_deactivated={$counts['licenses']}"
                .", sites_unlinked={$counts['sites']}",
            );
        }

        $this->info(
            ($dryRun ? 'Dry run. ' : 'Done. ')
            ."licenses_deactivated={$totals['licenses']}, sites_unlinked={$totals['sites']}",
        );

        return self::SUCCESS;
    }

    /**
     * @return array{licenses: int, sites: int}
     */
    private function clean(bool $dryRun): array
    {
        $orgSiteIds = Site::query()
            ->where(function (Builder $query): void {
                $query->whereNull('trading_partner_id')
                    ->orWhere('is_organization_facility', true);
            })
            ->pluck('id')
            ->all();

        if ($orgSiteIds === []) {
            return ['licenses' => 0, 'sites' => 0];
        }

        $partnerOrgIds = TradingPartner::query()
            ->whereNotNull('fda_organization_id')
            ->pluck('fda_organization_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        if ($partnerOrgIds === []) {
            return ['licenses' => 0, 'sites' => 0];
        }

        $partnerFacilityIds = FdaWddFacility::query()
            ->whereIn('fda_organization_id', $partnerOrgIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $partnerEstablishmentIds = FdaEstablishment::query()
            ->whereIn('fda_organization_id', $partnerOrgIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $partnerLicenseIds = $partnerFacilityIds === []
            ? []
            : FdaWddLicense::query()
                ->whereIn('fda_wdd_facility_id', $partnerFacilityIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

        $leakedLicenses = AtpLicense::query()
            ->whereIn('site_id', $orgSiteIds)
            ->where('is_active', true)
            ->when(
                $partnerLicenseIds === [],
                fn (Builder $query) => $query->whereRaw('0 = 1'),
                fn (Builder $query) => $query->whereIn('fda_wdd_license_id', $partnerLicenseIds),
            );

        $licenses = $dryRun
            ? (int) $leakedLicenses->count()
            : (int) $leakedLicenses->update(['is_active' => false]);

        return [
            'licenses' => $licenses,
            'sites' => $this->unlinkPartnerFdaSites(
                $orgSiteIds,
                $partnerFacilityIds,
                $partnerEstablishmentIds,
                $dryRun,
            ),
        ];
    }

    /**
     * @param  list<int>  $orgSiteIds
     * @param  list<int>  $partnerFacilityIds
     * @param  list<int>  $partnerEstablishmentIds
     */
    private function unlinkPartnerFdaSites(
        array $orgSiteIds,
        array $partnerFacilityIds,
        array $partnerEstablishmentIds,
        bool $dryRun,
    ): int {
        if ($partnerFacilityIds === [] && $partnerEstablishmentIds === []) {
            return 0;
        }

        $sites = Site::query()
            ->whereIn('id', $orgSiteIds)
            ->where(function (Builder $query) use ($partnerFacilityIds, $partnerEstablishmentIds): void {
                if ($partnerFacilityIds !== []) {
                    $query->whereIn('fda_wdd_facility_id', $partnerFacilityIds);
                }

                if ($partnerEstablishmentIds !== []) {
                    $query->orWhereIn('fda_establishment_id', $partnerEstablishmentIds);
                }
            });

        if ($dryRun) {
            return (int) $sites->count();
        }

        return (int) $sites->update([
            'fda_wdd_facility_id' => null,
            'fda_establishment_id' => null,
        ]);
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function resolveTenants(): Collection
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenants');

        if ($tenantIds !== []) {
            return Tenant::query()->whereIn('id', $tenantIds)->orderBy('id')->get();
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
