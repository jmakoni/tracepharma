<?php

namespace App\Console\Commands;

use App\Actions\Fda\ResolveStaleFdaOrganizationMatchReviews;
use Illuminate\Console\Command;

class ResolveStaleFdaOrganizationMatchReviewsCommand extends Command
{
    protected $signature = 'fda:resolve-stale-match-reviews
        {--dry-run : Report stale proposals without creating organizations}
        {--source= : Limit to a single import source (e.g. wdd, decrs)}
        {--limit= : Max pending reviews to scan}';

    protected $description = 'Create separate FDA organizations for pending match reviews whose proposed org is stale under the current matcher';

    public function handle(ResolveStaleFdaOrganizationMatchReviews $resolve): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('source');
        $source = is_string($source) && $source !== '' ? $source : null;
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;

        $result = $resolve->handle($source, $dryRun, $limit);

        $this->info(($dryRun ? '[dry run] ' : '')
            ."Scanned {$result['scanned']}, stale {$result['stale']}, "
            ."resolved {$result['resolved']}, kept {$result['kept']}, failed {$result['failed']}.");

        foreach ($result['samples'] as $sample) {
            $this->line('  '.$sample);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
