<?php

namespace App\Console\Commands;

use App\Actions\Catalog\EnsureMajorWholesalerFdaOrganizations;
use Illuminate\Console\Command;

class EnsureMajorWholesalerCatalogPartnersCommand extends Command
{
    protected $signature = 'catalog:ensure-major-wholesalers';

    protected $description = 'Ensure Top 6 major wholesaler FDA organizations exist (by GLN)';

    public function handle(EnsureMajorWholesalerFdaOrganizations $action): int
    {
        $count = $action->handle();

        $this->info('Ensured '.$count.' major wholesaler FDA organizations.');

        return self::SUCCESS;
    }
}
