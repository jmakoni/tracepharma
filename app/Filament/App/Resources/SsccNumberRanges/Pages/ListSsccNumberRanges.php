<?php

namespace App\Filament\App\Resources\SsccNumberRanges\Pages;

use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSsccNumberRanges extends ListRecords
{
    protected static string $resource = SsccNumberRangeResource::class;

    public function getSubheading(): ?string
    {
        return 'Allocate SSCC serial bands by tenant, organization site, or trading partner. Prefix and extension defaults come from Organization settings.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('organizationSettings')
                ->label('Organization settings')
                ->icon(Heroicon::OutlinedBuildingOffice)
                ->color('gray')
                ->url(fn (): string => OrganizationSettings::getUrl(panel: 'app')),
            Action::make('sites')
                ->label('Sites')
                ->icon(Heroicon::OutlinedMapPin)
                ->color('gray')
                ->url(fn (): string => SiteResource::getUrl('index', panel: 'app')),
            CreateAction::make(),
        ];
    }
}
