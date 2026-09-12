<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Pages;

use App\Filament\App\Resources\BuyingGroupMembers\Actions\BuyingGroupMembershipActions;
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
            BuyingGroupMembershipActions::invite(),
            BuyingGroupMembershipActions::revoke(),
            RegulatoryCompliance::apply(
                DeleteAction::make(),
                'buying_group_members_delete',
                requireReason: true,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['member_tenant_id']);

        return $data;
    }
}
