<?php

namespace App\Filament\App\Resources\Principals\Pages;

use App\Filament\App\Resources\Principals\PrincipalResource;
use App\Support\PrincipalsHonesty;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListPrincipals extends ListRecords
{
    protected static string $resource = PrincipalResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $honesty = PrincipalsHonesty::forTenant();

        return $honesty->shouldShow() ? $honesty->sentence() : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
