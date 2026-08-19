<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PromoteFdaWdd3plToCatalogSitesCommand extends Command
{
    protected $signature = 'tracepharma:promote-fda-wdd-3pl-to-sites
        {--dry-run : Retired no-op}
        {--force : Retired no-op}';

    protected $description = 'Retired: WDD/3PL staging is no longer promoted into catalog sites';

    public function handle(): int
    {
        $this->warn('Retired. FDA WDD facilities and licenses are the system of record; catalog sites are not created.');

        return self::SUCCESS;
    }
}
