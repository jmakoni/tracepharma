<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Pages;

use App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateBuyingGroupMember extends CreateRecord
{
    protected static string $resource = BuyingGroupMemberResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['member_tenant_id']);

        return $data;
    }
}
