<?php

namespace App\Console\Commands;

use App\Actions\Places\BackfillCatalogPartnerPlaces;
use App\Jobs\BackfillCatalogPartnerPlacesJob;
use App\Models\Fda\FdaOrganization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class BackfillPartnerPlacesCommand extends Command
{
    protected $signature = 'tracepharma:backfill-partner-places
        {--partner= : FDA organization id}
        {--limit= : Max organizations to process}
        {--only-missing=true : Only fill organizations/fields that are currently empty}
        {--dry-run : Compute the backfill without writing to the database}
        {--sync : Run inline instead of dispatching a queue job}';

    protected $description = 'Backfill FDA organization HQ address/geo from a Places search (existing sites only)';

    public function handle(BackfillCatalogPartnerPlaces $action): int
    {
        $onlyMissing = filter_var($this->option('only-missing'), FILTER_VALIDATE_BOOL);
        $dryRun = (bool) $this->option('dry-run');
        $sync = (bool) $this->option('sync');

        $organizations = $this->resolveOrganizations();

        if ($organizations->isEmpty()) {
            $this->info('No matching FDA organizations found.');

            return self::SUCCESS;
        }

        $totals = [
            'skipped_has_address' => 0,
            'no_results' => 0,
            'hq_filled' => 0,
            'sites_upserted' => 0,
            'rejected' => 0,
        ];
        $dispatched = 0;

        foreach ($organizations as $organization) {
            if ($sync || $dryRun) {
                $summary = $action->handle($organization, $onlyMissing, $dryRun);

                foreach ($totals as $key => $value) {
                    $totals[$key] += $summary[$key];
                }

                $this->line("[{$organization->id}] {$organization->name}: ".$this->formatSummary($summary));

                continue;
            }

            BackfillCatalogPartnerPlacesJob::dispatch($organization->id, $onlyMissing);
            $dispatched++;
            $this->line("[{$organization->id}] {$organization->name}: dispatched");
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} job(s).");
        } else {
            $this->info(
                "Done. skipped_has_address={$totals['skipped_has_address']}, "
                ."no_results={$totals['no_results']}, hq_filled={$totals['hq_filled']}, "
                ."sites_upserted={$totals['sites_upserted']}, rejected={$totals['rejected']}"
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, FdaOrganization>
     */
    private function resolveOrganizations(): Collection
    {
        $partnerOption = $this->option('partner');

        if (filled($partnerOption)) {
            return FdaOrganization::query()
                ->where('id', (int) $partnerOption)
                ->get();
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = FdaOrganization::query()
            ->where(function ($q) {
                $q->whereNull('street_address')->orWhere('street_address', '');
            })
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function formatSummary(array $summary): string
    {
        return collect($summary)
            ->map(fn ($value, $key) => "{$key}=".(is_bool($value) ? ($value ? 'true' : 'false') : $value))
            ->implode(', ');
    }
}
