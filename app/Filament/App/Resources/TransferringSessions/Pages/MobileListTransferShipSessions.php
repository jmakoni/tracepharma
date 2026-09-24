<?php

namespace App\Filament\App\Resources\TransferringSessions\Pages;

class MobileListTransferShipSessions extends MobileListTransferringSessions
{
    public function listMode(): string
    {
        return 'ship';
    }
}
