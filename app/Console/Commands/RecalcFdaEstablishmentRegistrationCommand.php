<?php

namespace App\Console\Commands;

use App\Models\Fda\FdaEstablishment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RecalcFdaEstablishmentRegistrationCommand extends Command
{
    protected $signature = 'tracepharma:recalc-fda-establishment-registration';

    protected $description = 'Recalculate fda_establishments.is_currently_registered from expiration_date and exclusion_flag';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $updated = 0;

        FdaEstablishment::query()->orderBy('id')->chunkById(500, function ($rows) use ($today, &$updated): void {
            foreach ($rows as $row) {
                $registered = ! $row->exclusion_flag
                    && ($row->expiration_date === null || $row->expiration_date->toDateString() >= $today);

                if ((bool) $row->is_currently_registered !== $registered) {
                    $row->fillFromFda(['is_currently_registered' => $registered]);
                    $updated++;
                }
            }
        });

        $this->info("Updated {$updated} establishment registration flag(s).");

        return self::SUCCESS;
    }
}
