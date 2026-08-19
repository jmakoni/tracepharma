<?php

namespace App\Actions\MasterData;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deactivate organization facilities leaked by tests into tenant databases.
 */
final class DemoteLeakedTestOrganizationSites
{
    /**
     * @return array{demoted: int, defaults_cleared: int}
     */
    public function handle(): array
    {
        if (! Schema::hasTable('sites')) {
            return [
                'demoted' => 0,
                'defaults_cleared' => 0,
            ];
        }

        $siteIds = $this->leakedSiteQuery()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($siteIds === []) {
            return [
                'demoted' => 0,
                'defaults_cleared' => 0,
            ];
        }

        $payload = [
            'is_organization_facility' => false,
            'is_active' => false,
        ];

        $demoted = DB::table('sites')->whereIn('id', $siteIds)->update($payload);

        $released = app(ReleaseSitesFromOrganization::class)->handle($siteIds);

        return [
            'demoted' => $demoted,
            'defaults_cleared' => $released['defaults_cleared'],
        ];
    }

    /**
     * @return Builder<Site>
     */
    private function leakedSiteQuery(): Builder
    {
        return Site::query()
            ->where('is_organization_facility', true)
            ->where(function (Builder $query): void {
                $query->where('code', 'like', 'TEST-%')
                    ->orWhere('name', 'like', 'Site A %')
                    ->orWhere('name', 'like', 'Site B %')
                    ->orWhere('name', 'like', 'Commission Site %')
                    ->orWhere('name', 'Scan-first Receive Test Site')
                    ->orWhere('name', 'like', 'Transfer From %')
                    ->orWhere('name', 'like', 'Transfer To %')
                    ->orWhere('name', 'like', 'ScanFirst %')
                    ->orWhere('name', 'like', 'Owned Receive %')
                    ->orWhere('name', 'like', 'Owned Dest %')
                    ->orWhere('name', 'like', 'Org Settings %');
            })
            ->where('name', 'not like', 'Demo %')
            ->where(function (Builder $query): void {
                $query->whereNull('code')
                    ->orWhereNotIn('code', ['MAIN', 'ORG-HQ']);
            });
    }
}
