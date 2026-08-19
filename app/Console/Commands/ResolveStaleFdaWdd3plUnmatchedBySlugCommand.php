<?php

namespace App\Console\Commands;

use App\Actions\Fda\ResolveStaleFdaWdd3plUnmatchedBySlug;
use Illuminate\Console\Command;

class ResolveStaleFdaWdd3plUnmatchedBySlugCommand extends Command
{
    protected $signature = 'fda:resolve-stale-wdd-3pl-unmatched
        {--dry-run : Report slug matches without linking}
        {--path= : Optional path to wdd_3pl_facilities_report.txt for Type majority}';

    protected $description = 'Link open WDD/3PL unmatched facilities whose slug now matches an FDA organization';

    public function handle(ResolveStaleFdaWdd3plUnmatchedBySlug $resolve): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;

        $result = $resolve->handle($path, $dryRun);

        $this->info(($dryRun ? '[dry run] ' : '')
            ."Scanned {$result['scanned']}, linked {$result['linked']}, "
            ."partner_type filled {$result['partner_type_filled']}, skipped {$result['skipped']}.");

        foreach ($result['samples'] as $sample) {
            $this->line('  '.$sample);
        }

        return self::SUCCESS;
    }
}
