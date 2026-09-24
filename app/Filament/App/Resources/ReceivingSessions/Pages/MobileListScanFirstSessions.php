<?php

namespace App\Filament\App\Resources\ReceivingSessions\Pages;

class MobileListScanFirstSessions extends MobileListReceivingSessions
{
    public function listMode(): string
    {
        return 'scan-first';
    }
}
