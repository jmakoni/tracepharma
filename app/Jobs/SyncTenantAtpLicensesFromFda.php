<?php

namespace App\Jobs;

use App\Actions\MasterData\CopyFdaWddLicensesToTenantSite;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTenantAtpLicensesFromFda implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'fda-atp-'.(string) $this->tenant->getKey();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public static function dispatchForAllTenants(): void
    {
        Tenant::query()->where('status', 'active')->cursor()->each(function (Tenant $tenant): void {
            try {
                static::dispatch($tenant);
            } catch (Throwable $exception) {
                Log::error('Failed to dispatch tenant ATP FDA sync', [
                    'tenant_id' => $tenant->getKey(),
                    'exception' => $exception,
                ]);
            }
        });
    }

    /**
     * @return array{sites: int, licenses: int, pruned: int, stamped: int, unmatched: int}
     */
    public function handle(CopyFdaWddLicensesToTenantSite $copier): array
    {
        return $this->tenant->run(fn (): array => $this->sync($copier));
    }

    /**
     * @return array{sites: int, licenses: int, pruned: int, stamped: int, unmatched: int}
     */
    public function sync(CopyFdaWddLicensesToTenantSite $copier): array
    {
        $sitesSynced = 0;
        $licensesCopied = 0;
        $licensesPruned = 0;
        $licensesStamped = 0;
        $licensesUnmatched = 0;

        $this->eligibleSites()->each(function (Site $site) use (
            $copier,
            &$sitesSynced,
            &$licensesCopied,
            &$licensesPruned,
            &$licensesStamped,
            &$licensesUnmatched,
        ): void {
            $counts = $copier->sync($site);
            $licensesCopied += $counts['copied'];
            $licensesPruned += $counts['pruned'];
            $licensesStamped += $counts['stamped'];
            $licensesUnmatched += $counts['unmatched'];
            $sitesSynced++;
        });

        return [
            'sites' => $sitesSynced,
            'licenses' => $licensesCopied,
            'pruned' => $licensesPruned,
            'stamped' => $licensesStamped,
            'unmatched' => $licensesUnmatched,
        ];
    }

    /**
     * @return Collection<int, Site>
     */
    public function eligibleSites(): Collection
    {
        return Site::query()
            ->with('tradingPartner')
            ->whereNotNull('trading_partner_id')
            ->where('is_organization_facility', false)
            ->where(function (Builder $query): void {
                $query->whereNotNull('fda_wdd_facility_id')
                    ->orWhereHas(
                        'atpLicenses',
                        fn (Builder $licenseQuery): Builder => $licenseQuery->whereNotNull('fda_wdd_license_id'),
                    );
            })
            ->orderBy('id')
            ->get();
    }
}
