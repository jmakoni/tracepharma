<?php

namespace App\Filament\App\Resources\ReceivingSessions\Pages;

use App\Filament\App\Resources\ReceivingSessions\Concerns\InteractsWithReceivingSessionHud;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewReceivingSession extends ViewRecord
{
    use InteractsWithReceivingSessionHud;

    protected static string $resource = ReceivingSessionResource::class;

    protected string $view = 'filament.app.resources.receiving-sessions.pages.view-receiving-session';

    public function content(Schema $schema): Schema
    {
        return $this->receivingSessionDesktopContent($schema);
    }
}
