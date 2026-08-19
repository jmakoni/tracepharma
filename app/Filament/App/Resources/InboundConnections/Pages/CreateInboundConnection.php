<?php

namespace App\Filament\App\Resources\InboundConnections\Pages;

use App\Filament\App\Concerns\SyncsEpcisHubRouting;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Support\InboundConnectionPartnerRoutingSync;
use Filament\Resources\Pages\CreateRecord;

class CreateInboundConnection extends CreateRecord
{
    use SyncsEpcisHubRouting;
    use TransformsConnectionCredentials;

    protected static string $resource = InboundConnectionResource::class;

    /** @var list<array<string, mixed>> */
    protected array $partnerRoutingMappings = [];

    protected bool $registerHubRouting = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->partnerRoutingMappings = $data['partner_routing_mappings'] ?? [];
        unset($data['partner_routing_mappings']);

        $this->registerHubRouting = (bool) ($data['register_hub_routing'] ?? false);
        unset($data['register_hub_routing']);

        return $this->transformInboundCredentialPairs($data);
    }

    protected function afterCreate(): void
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
