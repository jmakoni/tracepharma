<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Enums\OutboundConformanceState;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Support\Integrations\OutboundConnectionDefaultSync;
use Filament\Resources\Pages\CreateRecord;

class CreateOutboundConnection extends CreateRecord
{
    use TransformsConnectionCredentials;

    protected static string $resource = OutboundConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->transformOutboundCredentialPairs($data);
        $data['conformance_state'] = OutboundConformanceState::Test->value;

        return $data;
    }

    protected function afterCreate(): void
    {
        OutboundConnectionDefaultSync::ensureSingleDefault($this->record->fresh());
    }
}
