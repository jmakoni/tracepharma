<?php

namespace App\Filament\App\Resources\TransferringSessions\Pages;

class MobileListTransferReceiveSessions extends MobileListTransferringSessions
{
    public function listMode(): string
    {
        return 'receive';
    }
}
