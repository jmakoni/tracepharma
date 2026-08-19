<?php

namespace App\Filament\App\Resources\ReceivingSessions\Pages;

use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\Resources\Pages\CreateRecord;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateReceivingSession extends CreateRecord
{
    protected static string $resource = ReceivingSessionResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Opening a scan-first receive workstation is not a compliance mutation; gate Complete receive instead.
     */
    protected function shouldGateCreateWithRegulatoryCompliance(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return 'Scan-first receive';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Scan-first receive opened';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(OpenScanFirstReceivingSession::class)->handle(
                siteId: isset($data['site_id']) ? (int) $data['site_id'] : null,
                openedBy: auth()->id(),
                notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
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
