<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource;
use App\Filament\Admin\Support\FreezeManualFdaCreateFields;
use App\Filament\Admin\Support\SyncFdaFacilityAddressFingerprint;
use App\Filament\Resources\Pages\CreateRecord;

class CreateFdaEstablishment extends CreateRecord
{
    protected static string $resource = FdaEstablishmentResource::class;

    public function mount(): void
    {
        parent::mount();

        $organizationId = request()->query('fda_organization_id');
        if (! filled($organizationId)) {
            return;
        }

        $this->form->fill(array_merge(
            is_array($this->form->getRawState()) ? $this->form->getRawState() : [],
            [
                'fda_organization_id' => (int) $organizationId,
                'is_active' => true,
            ],
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SyncFdaFacilityAddressFingerprint::apply($data);
    }

    protected function afterCreate(): void
    {
        FreezeManualFdaCreateFields::afterCreate($this->record);
    }
}
