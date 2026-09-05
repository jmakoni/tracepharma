<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Pages;

use App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\DeleteAction;

class EditBuyingGroupMember extends EditRecord
{
    protected static string $resource = BuyingGroupMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                DeleteAction::make(),
                'buying_group_members_delete',
                requireReason: true,
            ),
        ];
    }
}
