<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportMckessonSoldShipTo;
use Illuminate\Console\Command;

class ImportMckessonSoldShipToCommand extends Command
{
    protected $signature = 'tracepharma:import-mckesson-sold-ship-to
        {--path= : Path to the McKesson Sold-To / Ship-To TSV}
        {--dry-run : Parse and summarize without writing}';

    protected $description = 'Blank-fill matching FDA organizations and sites from a McKesson Sold-To / Ship-To TSV';

    public function handle(ImportMckessonSoldShipTo $importer): int
    {
        $path = $this->option('path');
        $path = filled($path)
            ? (string) $path
            : storage_path('app/catalog/mckesson-sold-ship-to.tsv');

        if (! is_readable($path)) {
            $fallback = base_path('tests/fixtures/catalog/mckesson-sold-ship-to.tsv');
            if (is_readable($fallback)) {
                $path = $fallback;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? 'Dry-run importing' : 'Importing')." McKesson feed from {$path}");

        $summary = $importer->handle($path, $dryRun);

        $this->line(collect($summary)
            ->except(['near_duplicates', 'partner_id'])
            ->map(fn ($value, $key) => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : $value))
            ->implode(', '));

        if ($summary['partner_id'] !== null) {
            $this->line('partner_id='.$summary['partner_id']);
        }

        $duplicates = $summary['near_duplicates'];
        $this->newLine();
        $this->info('Near-duplicate McKesson FDA organizations (not merged): '.count($duplicates));

        if ($duplicates !== []) {
            $this->table(
                ['id', 'name', 'slug', 'gln', 'city'],
                array_map(static fn (array $row): array => [
                    $row['id'],
                    $row['name'],
                    $row['slug'],
                    $row['gln'] ?? '',
                    $row['city'] ?? '',
                ], $duplicates)
            );
        }

        return self::SUCCESS;
    }
}
