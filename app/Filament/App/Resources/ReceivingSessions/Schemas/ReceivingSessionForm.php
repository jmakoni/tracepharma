<?php

namespace App\Filament\App\Resources\ReceivingSessions\Schemas;

use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReceivingSessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scan-first receive')
                ->compact()
                ->description('Start receiving without an ASN. Scan known SSCCs/SGTINs; TI and ASN matches appear as you confirm.')
                ->schema([
                    Select::make('site_id')
                        ->label('Receive site')
                        ->options(fn (): array => EligibleReceiveSites::options())
                        ->default(fn (): ?int => CurrentSite::preferredId(
                            TenantSettings::forTenant(tenant())->defaultReceiveSiteId(),
                            EligibleReceiveSites::options(),
                        ))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('Organization-owned sites with a GLN. Defaults to the site chooser’s current site when valid, otherwise Organization receive site when set.'),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->nullable(),
                ]),
        ]);
    }
}
