<?php

namespace App\Console\Commands;

use App\Actions\OpenFda\ImportOpenFdaNdcPartners;
use App\Actions\OpenFda\ImportOpenFdaNdcProducts;
use App\Jobs\ImportFdaDatasetJob;
use App\Models\Fda\FdaImportRun;
use App\Support\OpenFda\OpenFdaNdcDataset;
use Illuminate\Console\Command;

class ImportOpenFdaNdcCommand extends Command
{
    protected $signature = 'tracepharma:import-openfda-ndc
        {--stage=all : all|partners|products}
        {--path= : Local json or zip path}
        {--fresh-download}';

    protected $description = 'Import the openFDA NDC directory into fda_organizations, fda_products, and packaging';

    public function handle(OpenFdaNdcDataset $dataset): int
    {
        if (ImportFdaDatasetJob::isExecuting(ImportFdaDatasetJob::OPENFDA_NDC_COMMAND)) {
            return $this->runImport($dataset);
        }

        $lock = ImportFdaDatasetJob::tryAcquireExecutionLock(ImportFdaDatasetJob::OPENFDA_NDC_COMMAND);

        if ($lock === null) {
            $this->error('Another openFDA NDC import is already running or queued.');

            return self::FAILURE;
        }

        try {
            return $this->runImport($dataset);
        } finally {
            ImportFdaDatasetJob::releaseExecutionLock(ImportFdaDatasetJob::OPENFDA_NDC_COMMAND, $lock);
        }
    }

    private function runImport(OpenFdaNdcDataset $dataset): int
    {
        $stage = (string) $this->option('stage');

        if (! in_array($stage, ['all', 'partners', 'products'], true)) {
            $this->error("Invalid --stage option: {$stage}. Expected one of: all, partners, products.");

            return self::FAILURE;
        }

        $path = $this->option('path');
        $freshDownload = (bool) $this->option('fresh-download');

        $this->info('Resolving openFDA NDC dataset...');
        $jsonPath = $dataset->resolveJsonPath($path !== null ? (string) $path : null, $freshDownload);
        $this->info("Using dataset: {$jsonPath}");

        $this->info('Loading dataset into memory...');
        $results = $dataset->loadResults($jsonPath);
        $this->info('Loaded '.count($results).' results.');

        $started = microtime(true);
        $run = FdaImportRun::query()->create([
            'source' => 'openfda_ndc',
            'source_path' => $jsonPath,
            'sha256' => is_file($jsonPath) ? hash_file('sha256', $jsonPath) ?: null : null,
            'started_at' => now(),
        ]);

        $partnerCounts = [
            'skipped_empty' => 0,
            'orgs_created' => 0,
            'orgs_reviewed' => 0,
            'orgs_linked' => 0,
        ];
        $productCounts = [
            'fda_upserted' => 0,
            'packaging_upserted' => 0,
            'org_linked' => 0,
            'missing_org' => 0,
            'errors' => 0,
        ];

        if ($stage === 'all' || $stage === 'partners') {
            $this->info('Resolving FDA organizations...');
            $partnerCounts = app(ImportOpenFdaNdcPartners::class)->handle($results);
            $this->line('Organizations — created: '.$partnerCounts['orgs_created']
                .', reviewed: '.$partnerCounts['orgs_reviewed']
                .', linked: '.$partnerCounts['orgs_linked']
                .', skipped_empty: '.$partnerCounts['skipped_empty']);
        }

        if ($stage === 'all' || $stage === 'products') {
            $this->info('Importing products...');
            $bar = $this->output->createProgressBar(count($results));
            $bar->start();

            $productCounts = app(ImportOpenFdaNdcProducts::class)->handle(
                $results,
                onProgress: static function () use ($bar): void {
                    $bar->advance();
                }
            );

            $bar->finish();
            $this->newLine();
            $this->line('Products — fda_upserted: '.$productCounts['fda_upserted']
                .', packaging_upserted: '.$productCounts['packaging_upserted']
                .', org_linked: '.$productCounts['org_linked']
                .', missing_org: '.$productCounts['missing_org']
                .', errors: '.($productCounts['errors'] ?? 0));
        }

        $run->forceFill([
            'rows_read' => count($results),
            'rows_inserted' => ($partnerCounts['orgs_created'] ?? 0) + ($productCounts['fda_upserted'] ?? 0),
            'rows_updated' => $partnerCounts['orgs_linked'] ?? 0,
            'rows_sent_to_review' => $partnerCounts['orgs_reviewed'] ?? 0,
            'rows_skipped' => ($partnerCounts['skipped_empty'] ?? 0) + ($productCounts['missing_org'] ?? 0),
            'completed_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ])->save();

        $this->info('Done.');

        return self::SUCCESS;
    }
}
