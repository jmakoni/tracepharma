<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MapFdaRegistryToCatalogCommand extends Command
{
    protected $signature = 'tracepharma:map-fda-registry-to-catalog';

    protected $description = 'Retired: FDA registry no longer maps into catalog';

    public function handle(): int
    {
        $this->warn('Retired. FDA is the system of record; catalog tables are left in place and are not updated.');

        return self::SUCCESS;
    }
}
