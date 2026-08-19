<?php

namespace App\Console\Commands;

use App\Actions\OpenFda\ImportOpenFdaDrugsFdaPackages;
use App\Jobs\ImportFdaDatasetJob;
use App\Models\Fda\FdaImportRun;
use App\Support\OpenFda\OpenFdaDrugsFdaDataset;
use Illuminate\Console\Command;

class ImportOpenFdaDrugsFdaCommand extends Command
{
    protected $signature = 'tracepharma:import-openfda-drugsfda
        {--path= : Local json or zip path}
        {--fresh-download}';

    protected $description = 'Import Drugs@FDA openfda.package_ndc into fda_product_packaging';

    public function handle(OpenFdaDrugsFdaDataset $dataset): int
    {
        if (ImportFdaDatasetJob::isExecuting(ImportFdaDatasetJob::OPENFDA_DRUGSFDA_COMMAND)) {
            return $this->runImport($dataset);
        }

        $lock = ImportFdaDatasetJob::tryAcquireExecutionLock(ImportFdaDatasetJob::OPENFDA_DRUGSFDA_COMMAND);

        if ($lock === null) {
            $this->error('Another openFDA Drugs@FDA import is already running or queued.');

            return self::FAILURE;
        }

        try {
            return $this->runImport($dataset);
        } finally {
            ImportFdaDatasetJob::releaseExecutionLock(ImportFdaDatasetJob::OPENFDA_DRUGSFDA_COMMAND, $lock);
        }
    }

    private function runImport(OpenFdaDrugsFdaDataset $dataset): int
    {
        $path = $this->option('path');
        $freshDownload = (bool) $this->option('fresh-download');

        $this->info('Resolving openFDA Drugs@FDA dataset...');
        $jsonPath = $dataset->resolveJsonPath($path !== null ? (string) $path : null, $freshDownload);
        $this->info("Using dataset: {$jsonPath}");

        $this->info('Loading dataset into memory...');
        $results = $dataset->loadResults($jsonPath);
        $this->info('Loaded '.count($results).' results.');

        $started = microtime(true);
        $run = FdaImportRun::query()->create([
            'source' => 'openfda_drugsfda',
            'source_path' => $jsonPath,
            'sha256' => is_file($jsonPath) ? hash_file('sha256', $jsonPath) ?: null : null,
            'started_at' => now(),
        ]);

        $bar = $this->output->createProgressBar(count($results));
        $bar->start();

        $counts = app(ImportOpenFdaDrugsFdaPackages::class)->handle(
            $results,
            onProgress: static function () use ($bar): void {
                $bar->advance();
            }
        );

        $bar->finish();
        $this->newLine();

        $this->line('Packaging — upserted: '.$counts['packaging_upserted']
            .', skipped_empty: '.$counts['packaging_skipped_empty']
            .', skipped_no_fda_product: '.$counts['skipped_no_fda_product']
            .', products_matched: '.$counts['products_matched']);
        $this->line('Errors: '.$counts['errors']);

        $run->forceFill([
            'rows_read' => count($results),
            'rows_inserted' => $counts['packaging_upserted'],
            'rows_updated' => $counts['products_matched'],
            'rows_skipped' => $counts['packaging_skipped_empty'] + $counts['skipped_no_fda_product'],
            'completed_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ])->save();

        $this->info('Done.');

        return self::SUCCESS;
    }
}
