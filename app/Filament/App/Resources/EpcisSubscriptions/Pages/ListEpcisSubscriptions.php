<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions\Pages;

use App\Filament\App\Resources\EpcisSubscriptions\EpcisSubscriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListEpcisSubscriptions extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = EpcisSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
