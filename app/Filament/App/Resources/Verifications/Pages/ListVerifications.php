<?php

namespace App\Filament\App\Resources\Verifications\Pages;

use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\Verifications\VerificationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListVerifications extends ListRecords
{
    protected static string $resource = VerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verifyProduct')
                ->label('Verify product')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(fn (): string => VerifyProduct::getUrl())
                ->visible(fn (): bool => VerifyProduct::canAccess()),
        ];
    }
}
