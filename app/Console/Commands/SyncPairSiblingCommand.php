<?php

namespace App\Console\Commands;

use App\Support\PairSiblingCentral;
use Illuminate\Console\Command;

class SyncPairSiblingCommand extends Command
{
    protected $signature = 'tracepharma:sync-pair-sibling';

    protected $description = 'Copy away-environment tenant hosts into the sibling central database';

    public function handle(PairSiblingCentral $sibling): int
    {
        if (! $sibling->enabled()) {
            $this->warn('PAIR_SIBLING_DB_DATABASE is not set or matches this app database.');

            return self::SUCCESS;
        }

        $synced = $sibling->syncAwayTenants();
        $this->info('Replicated '.count($synced).' away-environment tenant(s) to '.config('tracepharma.pair_sibling_database'));

        return self::SUCCESS;
    }
}
