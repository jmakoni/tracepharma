<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Support\Integrations\OutboundConnectionDefaultSync;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOutboundConnection extends EditRecord
{
    use TransformsConnectionCredentials;

    protected static string $resource = OutboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillDedicatedCredentialFields($data, $this->record->credentials ?? []);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existingSettings = $this->record->settings ?? [];
        $incomingSettings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = array_merge($existingSettings, $incomingSettings);

        return $this->transformOutboundCredentialPairs($data, $this->record->credentials ?? []);
    }

    protected function afterSave(): void
    {
        OutboundConnectionDefaultSync::ensureSingleDefault($this->record->fresh());
    }
}
