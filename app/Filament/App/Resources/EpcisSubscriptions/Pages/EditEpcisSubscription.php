<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions\Pages;

use App\Filament\App\Resources\EpcisSubscriptions\EpcisSubscriptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpcisSubscription extends EditRecord
{
    protected static string $resource = EpcisSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
