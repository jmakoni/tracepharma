<?php

namespace App\Filament\App\Resources\Verifications\Pages;

use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\Verifications\VerificationResource;
use App\Models\Verification;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewVerification extends ViewRecord
{
    protected static string $resource = VerificationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['verifiedByUser', 'exception']);
    }

    protected function getHeaderActions(): array
    {
        return [
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
