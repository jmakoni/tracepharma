<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Support\Integrations\OutboundConnectionDefaultSync;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOutboundConnection extends CreateRecord
{
    use TransformsConnectionCredentials;

    protected static string $resource = OutboundConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->transformOutboundCredentialPairs($data);
    }

    protected function afterCreate(): void
    {
        OutboundConnectionDefaultSync::ensureSingleDefault($this->record->fresh());
    }
}
