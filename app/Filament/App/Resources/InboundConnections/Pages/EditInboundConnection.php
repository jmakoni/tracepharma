<?php

namespace App\Filament\App\Resources\InboundConnections\Pages;

use App\Actions\Integrations\RegisterConnectionApprovalRequest;
use App\Enums\ConnectionApprovalStatus;
use App\Filament\App\Concerns\SyncsEpcisHubRouting;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Models\InboundConnection;
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

    private ?string $securityFingerprintBeforeSave = null;

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

        /** @var InboundConnection $record */
        $record = $this->record;
        // Form getState() may already have written scalar attributes onto $record;
        // fingerprint the persisted row so Approved re-pend compares pre-edit values.
        $this->securityFingerprintBeforeSave = app(RegisterConnectionApprovalRequest::class)
            ->securityFingerprint($record->fresh() ?? $record);

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

        $this->syncApprovalRequest($this->record->fresh());
    }

    private function syncApprovalRequest(InboundConnection $record): void
    {
        $registrar = app(RegisterConnectionApprovalRequest::class);
        $fingerprintChanged = $this->securityFingerprintBeforeSave !== null
            && $this->securityFingerprintBeforeSave !== $registrar->securityFingerprint($record);

        // Rejected/suspended always resubmit; Approved only when security-sensitive fields change.
        if ($record->isRejected() || $record->isSuspended() || ($record->isApproved() && $fingerprintChanged)) {
            $record->approval_status = ConnectionApprovalStatus::Pending;
            $record->approval_note = null;
            $record->save();
            $record->refresh();
        }

        if ($record->isPendingApproval()) {
            $registrar->register($record);
        }
    }
}
