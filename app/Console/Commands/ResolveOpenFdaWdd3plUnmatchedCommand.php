<?php

namespace App\Console\Commands;

use App\Actions\Fda\ResolveOpenFdaWdd3plUnmatched;
use Illuminate\Console\Command;

class ResolveOpenFdaWdd3plUnmatchedCommand extends Command
{
    protected $signature = 'fda:resolve-open-wdd-3pl-unmatched
        {--dry-run : Report parent groups without creating or linking}
        {--path= : Optional path to wdd_3pl_facilities_report.txt for Type majority}';

    protected $description = 'Create or link FDA organizations for open WDD/3PL unmatched rows (DC names roll up to parent)';

    public function handle(ResolveOpenFdaWdd3plUnmatched $resolve): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : null;

        $result = $resolve->handle($path, $dryRun);

        $this->info(($dryRun ? '[dry run] ' : '')
            ."Scanned {$result['scanned']} rows across {$result['parents']} parents; "
            ."linked {$result['linked']}, created {$result['created']}, "
            ."rows resolved {$result['rows_resolved']}.");

        foreach ($result['samples'] as $sample) {
            $this->line('  '.$sample);
        }

        return self::SUCCESS;
    }
}
