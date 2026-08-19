<?php

namespace App\Console\Commands;

use App\Actions\Fda\DedupeFdaOrganizationMatchReviews;
use Illuminate\Console\Command;

class DedupeFdaOrganizationMatchReviewsCommand extends Command
{
    protected $signature = 'fda:dedupe-match-reviews
        {--dry-run : Report what would change without writing}
        {--source= : Limit to a single import source (e.g. wdd, decrs)}';

    protected $description = 'Delete duplicate pending FDA organization match reviews, keeping the lowest id per key';

    public function handle(DedupeFdaOrganizationMatchReviews $dedupe): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('source');
        $source = is_string($source) && $source !== '' ? $source : null;

        $result = $dedupe->handle($source, $dryRun);

        $this->info(($dryRun ? '[dry run] ' : '')
            ."Duplicate groups {$result['groups']}, "
            ."deleted {$result['deleted']}, "
            ."kept {$result['kept']}, "
            ."remaining pending {$result['remaining_pending']}.");

        return self::SUCCESS;
    }
}
