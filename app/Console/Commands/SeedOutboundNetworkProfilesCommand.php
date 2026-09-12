<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OutboundNetworkProfile;
use Database\Seeders\OutboundNetworkProfileSeeder;
use Illuminate\Console\Command;

class SeedOutboundNetworkProfilesCommand extends Command
{
    protected $signature = 'tracepharma:seed-outbound-network-profiles {--force : Overwrite existing profile values}';

    protected $description = 'Seed Admin outbound network profiles (UniTrace/Systech/SAP ICH/TraceLink reusable values).';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        (new OutboundNetworkProfileSeeder($force))->run();

        $count = OutboundNetworkProfile::query()->count();
        $this->info("Outbound network profiles seeded ({$count} rows).");

        return self::SUCCESS;
    }
}
