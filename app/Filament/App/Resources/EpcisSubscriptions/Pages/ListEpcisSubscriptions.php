<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions\Pages;

use App\Filament\App\Resources\EpcisSubscriptions\EpcisSubscriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEpcisSubscriptions extends ListRecords
{
    protected static string $resource = EpcisSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
