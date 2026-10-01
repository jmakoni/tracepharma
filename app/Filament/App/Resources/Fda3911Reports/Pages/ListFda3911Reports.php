<?php

namespace App\Filament\App\Resources\Fda3911Reports\Pages;

use App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFda3911Reports extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = Fda3911ReportResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Author and track FDA Form 3911 — not an automated Pulse or FDA e-submit API.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
