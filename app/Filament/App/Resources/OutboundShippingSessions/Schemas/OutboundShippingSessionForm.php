<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Schemas;

use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OutboundShippingSessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ship from')
                ->compact()
                ->schema([
                    Select::make('site_id')
                        ->label('Ship-from site')
                        ->options(fn (): array => EligibleReceiveSites::organizationOptions())
                        ->default(fn (): ?int => CurrentSite::preferredId(
                            TenantSettings::forTenant(tenant())->defaultShipFromSiteId(),
                            EligibleReceiveSites::organizationOptions(),
                        ))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('Defaults to the site chooser’s current site when valid, otherwise Organization ship-from site when set.'),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->nullable(),
                ]),
        ]);
    }
}
