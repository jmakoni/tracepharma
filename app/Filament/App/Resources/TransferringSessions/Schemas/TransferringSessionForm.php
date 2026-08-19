<?php

namespace App\Filament\App\Resources\TransferringSessions\Schemas;

use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransferringSessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Transfer')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    Select::make('from_site_id')
                        ->label('From site')
                        ->options(fn (): array => EligibleReceiveSites::organizationOptions())
                        ->default(fn (): ?int => CurrentSite::preferredId(
                            TenantSettings::forTenant(tenant())->defaultShipFromSiteId(),
                            EligibleReceiveSites::organizationOptions(),
                        ))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('Defaults to the site chooser’s current site when valid, otherwise Organization ship-from site when set.'),
                    Select::make('to_site_id')
                        ->label('To site')
                        ->options(fn (): array => EligibleReceiveSites::organizationOptions())
                        ->default(fn (): ?int => TenantSettings::forTenant(tenant())->defaultReceiveSiteId())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('Defaults to Organization receive site when set.')
                        ->different('from_site_id'),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->nullable()
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
