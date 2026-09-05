<?php

namespace App\Filament\App\Resources\Verifications\Pages;

use App\Enums\VerificationRequestTrigger;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\Verifications\VerificationResource;
use App\Filament\Notifications\Notification;
use App\Models\Verification;
use App\Services\Vrs\VerificationRequestCaseService;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ViewVerification extends ViewRecord
{
    protected static string $resource = VerificationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['verifiedByUser', 'exception', 'verificationRequestCase.response']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestManufacturerVerification')
                ->label('Request manufacturer verification')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('warning')
                ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsManufacturerVerificationPortal()
                    && $this->getRecord()->status !== 'verified'
                    && $this->getRecord()->verificationRequestCase === null)
                ->requiresConfirmation()
                ->modalDescription('Email the manufacturer a secure portal link to submit a positive or negative verification response. A confirmation email is sent to your VRS contact when they answer positively.')
                ->action(function (): void {
                    /** @var Verification $record */
                    $record = $this->getRecord();

                    try {
                        $result = app(VerificationRequestCaseService::class)->openFromVerification(
                            $record,
                            VerificationRequestTrigger::fromVerificationStatus((string) $record->status),
                            auth()->user(),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Could not send request')
                            ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Manufacturer verification request sent')
                        ->body('Case '.$result['case']->uuid.' — portal link emailed to manufacturer.')
                        ->success()
                        ->send();

                    $this->record->refresh();
                    $this->record->loadMissing(['verificationRequestCase.response']);
                }),
            Action::make('verifyAgain')
                ->label('Verify again')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(fn (): string => $this->verifyAgainUrl())
                ->visible(fn (): bool => VerifyProduct::canAccess()),
        ];
    }

    private function verifyAgainUrl(): string
    {
        /** @var Verification $record */
        $record = $this->getRecord();

        $params = [];

        if (filled($record->scanned_barcode)) {
            $params['barcode'] = (string) $record->scanned_barcode;
        } elseif (filled($record->gtin14) && filled($record->serial)) {
            $params['gtin'] = (string) $record->gtin14;
            $params['serial'] = (string) $record->serial;
        }

        $url = VerifyProduct::getUrl();

        return $params === [] ? $url : $url.'?'.http_build_query($params);
    }
}
