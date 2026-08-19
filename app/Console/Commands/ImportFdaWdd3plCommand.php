<?php

namespace App\Console\Commands;

use App\Actions\Fda\ImportFdaWdd3plStaging;
use App\Actions\Fda\ImportFdaWddToRegistry;
use App\Actions\Fda\PromoteFdaWdd3plToCatalogSites;
use App\Exceptions\FdaStagingCollapsedException;
use App\Exceptions\FdaStagingImportIncompleteException;
use App\Jobs\ImportFdaDatasetJob;
use App\Jobs\SyncTenantAtpLicensesFromFda;
use App\Support\Fda\FdaStagingSnapshotSize;
use App\Support\Fda\FdaWdd3plDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportFdaWdd3plCommand extends Command
{
    protected $signature = 'tracepharma:import-fda-wdd-3pl
        {--path= : Local TSV path}
        {--fresh-download}
        {--report : Write unmatched facilities CSV report even when all rows matched}
        {--promote : After import, promote staging rows to catalog sites and ATP licenses}
        {--force : Promote even when this import loaded far fewer rows than the last one}';

    protected $description = 'Import the FDA WDD/3PL facilities report into fda_wdd_3pl_staging (truncates each run; unmatched partners are listed in storage/app/fda/wdd_unmatched_{date}.csv)';

    public function handle(
        FdaWdd3plDataset $dataset,
        ImportFdaWdd3plStaging $importer,
        ImportFdaWddToRegistry $registryImporter,
        PromoteFdaWdd3plToCatalogSites $promoter,
    ): int {
        if (ImportFdaDatasetJob::isExecuting(ImportFdaDatasetJob::WDD_COMMAND)) {
            return $this->runImport($dataset, $importer, $registryImporter, $promoter);
        }

        $lock = ImportFdaDatasetJob::tryAcquireExecutionLock(ImportFdaDatasetJob::WDD_COMMAND);

        if ($lock === null) {
            $this->error('Another WDD/3PL import is already running or queued.');

            return self::FAILURE;
        }

        try {
            return $this->runImport($dataset, $importer, $registryImporter, $promoter);
        } finally {
            ImportFdaDatasetJob::releaseExecutionLock(ImportFdaDatasetJob::WDD_COMMAND, $lock);
        }
    }

    private function runImport(
        FdaWdd3plDataset $dataset,
        ImportFdaWdd3plStaging $importer,
        ImportFdaWddToRegistry $registryImporter,
        PromoteFdaWdd3plToCatalogSites $promoter,
    ): int {
        $path = $this->option('path');
        $freshDownload = (bool) $this->option('fresh-download');

        $this->info('Resolving FDA WDD/3PL dataset...');
        $resolvedPath = $dataset->resolvePath($path !== null ? (string) $path : null, $freshDownload);
        $this->info("Using dataset: {$resolvedPath}");

        $this->info('Importing WDD/3PL staging rows...');
        $counts = $importer->handle($resolvedPath);

        $this->line('Read: '.$counts['read']
            .', matched: '.$counts['matched']
            .', skipped_unmatched: '.$counts['skipped_unmatched']
            .', inserted: '.$counts['inserted']);

        $this->info('Importing WDD/3PL into Pure FDA registry...');
        $registryCounts = $registryImporter->handle($resolvedPath);
        $this->line('Registry — inserted: '.$registryCounts['inserted']
            .', updated: '.$registryCounts['updated']
            .', sent_to_review: '.$registryCounts['sent_to_review']
            .', licenses_delisted: '.$registryCounts['licenses_delisted']);

        $unmatchedFacilities = $counts['unmatched_facilities'] ?? [];

        if ($counts['skipped_unmatched'] > 0) {
            $uniqueCount = count($unmatchedFacilities);
            $this->warn(
                "Skipped {$counts['skipped_unmatched']} row(s) with no catalog partner match ({$uniqueCount} unique facility name(s))."
            );
        }

        if ((bool) $this->option('report') || $counts['skipped_unmatched'] > 0) {
            $reportPath = $this->writeUnmatchedReport($unmatchedFacilities);
            $this->info("Unmatched facilities report: {$reportPath}");
        }

        if ((bool) $this->option('promote')) {
            $force = (bool) $this->option('force');
            $size = FdaStagingSnapshotSize::measure($counts['inserted'], $counts['import_run_id']);

            if ($size->hasCollapsed() && $force) {
                $this->warn($size->summary().' Promoting anyway because --force was given.');
            }

            $this->info('Promoting staging rows to catalog sites...');

            try {
                $promoteCounts = $promoter->handle(false, $force);
            } catch (FdaStagingImportIncompleteException|FdaStagingCollapsedException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->line(
                'Processed: '.$promoteCounts['processed']
                .', sites_matched: '.$promoteCounts['sites_matched']
                .', sites_created: '.$promoteCounts['sites_created']
                .', licenses_upserted: '.$promoteCounts['licenses_upserted']
                .', licenses_relocated: '.$promoteCounts['licenses_relocated']
                .', licenses_delisted: '.$promoteCounts['licenses_delisted']
                .', skipped: '.$promoteCounts['skipped']
            );

            if (! ImportFdaDatasetJob::isExecuting(ImportFdaDatasetJob::WDD_COMMAND)) {
                SyncTenantAtpLicensesFromFda::dispatchForAllTenants();
                $this->info('Dispatched tenant ATP license sync for all tenants.');
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $unmatchedFacilities
     */
    private function writeUnmatchedReport(array $unmatchedFacilities): string
    {
        $directory = storage_path('app/fda');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/wdd_unmatched_'.now()->format('Y-m-d').'.csv';
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Unable to write unmatched facilities report: {$path}");
        }

        try {
            fputcsv($handle, ['facility_name', 'count']);

            arsort($unmatchedFacilities, SORT_NUMERIC);

            foreach ($unmatchedFacilities as $facilityName => $count) {
                fputcsv($handle, [$facilityName, $count]);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }
}
