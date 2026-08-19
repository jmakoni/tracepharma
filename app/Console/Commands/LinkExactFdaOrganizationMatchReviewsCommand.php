<?php

namespace App\Console\Commands;

use App\Actions\Fda\LinkExactFdaOrganizationMatchReviews;
use Illuminate\Console\Command;

class LinkExactFdaOrganizationMatchReviewsCommand extends Command
{
    protected $signature = 'fda:link-exact-match-reviews
        {--dry-run : Report auto-link candidates without resolving}
        {--source= : Limit to a single import source}';

    protected $description = 'Auto-link pending FDA org match reviews that now exact/high-fuzzy match their proposed org';

    public function handle(LinkExactFdaOrganizationMatchReviews $link): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('source');
        $source = is_string($source) && $source !== '' ? $source : null;

        $result = $link->handle($source, $dryRun);

        $this->info(($dryRun ? '[dry run] ' : '')
            ."Scanned {$result['scanned']}, linked {$result['linked']}, "
            ."skipped {$result['skipped']}, failed {$result['failed']}.");

        foreach ($result['samples'] as $sample) {
            $this->line('  '.$sample);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
