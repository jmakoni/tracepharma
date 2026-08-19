<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Pages;

use App\Filament\Admin\Resources\Fda\FdaProducts\FdaProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFdaProduct extends ViewRecord
{
    protected static string $resource = FdaProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return parent::resolveRecord($key)->load('pharmClasses', 'fdaOrganization');
    }
}
