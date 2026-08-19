<?php

namespace App\Filament\App\Resources\TransferringSessions\Pages;

use App\Actions\Transferring\OpenTransferringSession;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Filament\Resources\Pages\CreateRecord;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateTransferringSession extends CreateRecord
{
    protected static string $resource = TransferringSessionResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Opening a transfer workstation is not a compliance mutation; gate Ship transfer instead.
     */
    protected function shouldGateCreateWithRegulatoryCompliance(): bool
    {
        return false;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Transfer session opened';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(OpenTransferringSession::class)->handle(
                fromSiteId: isset($data['from_site_id']) ? (int) $data['from_site_id'] : null,
                toSiteId: isset($data['to_site_id']) ? (int) $data['to_site_id'] : null,
                openedBy: auth()->id(),
                notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
            );
        } catch (InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages([
                'from_site_id' => $e->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('view', $this->getRedirectUrlParameters());
    }
}
