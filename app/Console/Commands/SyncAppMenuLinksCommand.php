<?php

namespace App\Console\Commands;

use App\Actions\Menus\SyncAppMenuLinks;
use Illuminate\Console\Command;

class SyncAppMenuLinksCommand extends Command
{
    protected $signature = 'menus:sync-app-links';

    protected $description = 'Sync Filament App panel pages/resources into app_menu_links for the menu manager';

    public function handle(SyncAppMenuLinks $sync): int
    {
        $count = $sync->handle();
        $this->info("Synced {$count} App menu link(s).");

        return self::SUCCESS;
    }
}
