<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Pages;

use App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBuyingGroupMembers extends ListRecords
{
    protected static string $resource = BuyingGroupMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
