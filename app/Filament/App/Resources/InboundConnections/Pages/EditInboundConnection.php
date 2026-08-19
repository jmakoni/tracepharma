<?php

namespace App\Filament\App\Resources\InboundConnections\Pages;

use App\Filament\App\Concerns\SyncsEpcisHubRouting;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Support\InboundConnectionPartnerRoutingSync;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInboundConnection extends EditRecord
{
    use SyncsEpcisHubRouting;
    use TransformsConnectionCredentials;

    protected static string $resource = InboundConnectionResource::class;

    /** @var list<array<string, mixed>> */
    protected array $partnerRoutingMappings = [];

    protected bool $registerHubRouting = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = $this->fillDedicatedCredentialFields($data, $this->record->credentials ?? []);
        $data['partner_routing_mappings'] = InboundConnectionPartnerRoutingSync::toFormRows($this->record);
        $data['register_hub_routing'] = $this->record->isHubRegistered();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->partnerRoutingMappings = $data['partner_routing_mappings'] ?? [];
        unset($data['partner_routing_mappings']);

        $this->registerHubRouting = (bool) ($data['register_hub_routing'] ?? false);
        unset($data['register_hub_routing']);

        $existingSettings = $this->record->settings ?? [];
        $incomingSettings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = array_merge($existingSettings, $incomingSettings);

        return $this->transformInboundCredentialPairs($data, $this->record->credentials ?? []);
    }

    protected function afterSave(): void
    {
        InboundConnectionPartnerRoutingSync::syncFromForm(
            $this->record,
            $this->partnerRoutingMappings,
            $this->record->multiPartnerRoutingEnabled(),
        );

        $this->syncHubRouting($this->record, $this->registerHubRouting);
        $this->registerHubRouting = false;
    }
}
