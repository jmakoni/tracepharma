<?php

namespace App\Console\Commands;

use App\Actions\Fda\ImportFdaDecrs;
use App\Jobs\ImportFdaDatasetJob;
use App\Support\Fda\FdaDecrsDataset;
use Illuminate\Console\Command;

class ImportFdaDecrsCommand extends Command
{
    protected $signature = 'tracepharma:import-fda-decrs
        {--path= : Local zip or drls_reg.txt path}
        {--fresh-download}';

    protected $description = 'Import FDA DECRS (drls_reg.zip) into fda_organizations, fda_establishments, and operations';

    public function handle(FdaDecrsDataset $dataset, ImportFdaDecrs $importer): int
    {
        if (ImportFdaDatasetJob::isExecuting(ImportFdaDatasetJob::DECRS_COMMAND)) {
            return $this->runImport($dataset, $importer);
        }

        $lock = ImportFdaDatasetJob::tryAcquireExecutionLock(ImportFdaDatasetJob::DECRS_COMMAND);

        if ($lock === null) {
            $this->error('Another DECRS import is already running or queued.');

            return self::FAILURE;
        }

        try {
            return $this->runImport($dataset, $importer);
        } finally {
            ImportFdaDatasetJob::releaseExecutionLock(ImportFdaDatasetJob::DECRS_COMMAND, $lock);
        }
    }

    private function runImport(FdaDecrsDataset $dataset, ImportFdaDecrs $importer): int
    {
        $path = $this->option('path');
        $freshDownload = (bool) $this->option('fresh-download');

        $this->info('Resolving FDA DECRS dataset...');
        $resolvedPath = $dataset->resolvePath($path !== null ? (string) $path : null, $freshDownload);
        $this->info("Using dataset: {$resolvedPath}");

        $sourcePath = is_string($path) && $path !== '' ? $path : $resolvedPath;

        $this->info('Importing DECRS rows...');
        $counts = $importer->handle($resolvedPath, $sourcePath);

        $this->line('Read: '.$counts['read']
            .', inserted: '.$counts['inserted']
            .', updated: '.$counts['updated']
            .', skipped: '.$counts['skipped']
            .', sent_to_review: '.$counts['sent_to_review']);

        $this->info('Done.');

        return self::SUCCESS;
    }
}
