<?php

namespace App\Filament\App\Resources\Principals\Pages;

use App\Filament\App\Resources\Principals\PrincipalResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrincipal extends EditRecord
{
    protected static string $resource = PrincipalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
