<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Actions\Integrations\RegisterConnectionApprovalRequest;
use App\Enums\ConnectionApprovalStatus;
use App\Enums\OutboundConformanceState;
use App\Enums\SerializationProvider;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Models\OutboundConnection;
use App\Support\Integrations\OutboundConnectionDefaultSync;
use App\Support\Integrations\OutboundSendPreset;
use Filament\Resources\Pages\CreateRecord;

class CreateOutboundConnection extends CreateRecord
{
    use TransformsConnectionCredentials;

    protected static string $resource = OutboundConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->transformOutboundCredentialPairs($data);
        $data['conformance_state'] = OutboundConformanceState::Test->value;
        $data['approval_status'] = ConnectionApprovalStatus::Pending->value;

        // Legacy readers still key off settings.profile_key — backfill from the provider.
        $provider = SerializationProvider::tryFrom((string) ($data['serialization_provider'] ?? ''));
        $profileKey = OutboundSendPreset::profileKeyForProvider($provider);
        if ($profileKey !== null) {
            $data['settings'] = array_merge(
                is_array($data['settings'] ?? null) ? $data['settings'] : [],
                ['profile_key' => $profileKey],
            );
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var OutboundConnection $record */
        $record = $this->record;
        $record->syncTradingPartnerIdFromPartners();
        $record->refresh();
        $record->assertAssignableConfiguration();
        OutboundConnectionDefaultSync::ensureSingleDefault($record->fresh());
        app(RegisterConnectionApprovalRequest::class)->register($record->fresh());
    }
}
