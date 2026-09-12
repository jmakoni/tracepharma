<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Pages;

use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\Resources\Pages\CreateRecord;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateOutboundShippingSession extends CreateRecord
{
    protected static string $resource = OutboundShippingSessionResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Opening a ship-order workstation is not a compliance mutation; gate Send shipment instead.
     */
    protected function shouldGateCreateWithRegulatoryCompliance(): bool
    {
        return false;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ship order opened';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(OpenOutboundShippingSession::class)->handle(
                siteId: isset($data['site_id']) ? (int) $data['site_id'] : null,
                openedBy: auth()->id(),
                isDropShipment: (bool) ($data['is_drop_shipment'] ?? false),
                principalId: isset($data['principal_id']) ? (int) $data['principal_id'] : null,
            );
        } catch (InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages([
                'site_id' => $e->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('view', $this->getRedirectUrlParameters());
    }
}
