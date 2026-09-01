<?php

namespace App\Filament\App\Resources\Sites\Schemas;

use App\Filament\App\Support\FdaPicker;
use App\Rules\RejectPartnerGlnUnderOrgPrefix;
use App\Rules\RejectTenantGln;
use App\Support\Gs1\GlnRules;
use App\Support\Gs1\SglnRules;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Source')
                    ->compact()
                    ->columns(1)
                    ->schema([
                        ...FdaPicker::establishment(),
                        // Scoped to the selected establishment's FDA organization: blank
                        // search lists that org's WDD facilities; typing filters them.
                        ...FdaPicker::wddFacility(),
                        Select::make('trading_partner_id')
                            ->label('Trading partner')
                            ->relationship('tradingPartner', 'name')
                            ->searchable()
                            ->preload()
                            ->searchDebounce(500)
                            ->nullable()
                            ->helperText('Leave blank for your organization\'s own site. Set a partner for that partner\'s location.'),
                        Select::make('principal_id')
                            ->label('Principal')
                            ->relationship(
                                'principal',
                                'name',
                                fn ($query) => $query->where('is_active', true)->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->searchDebounce(500)
                            ->nullable()
                            ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsPrincipals())
                            ->helperText('Optional soft label for 3PL client tagging — not custody isolation.'),
                    ]),
                Section::make('Identity')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                        TextInput::make('code')->unique(ignoreRecord: true)->maxLength(255),
                        GlnRules::input()
                            ->unique(ignoreRecord: true)
                            // Only a partner-owned location is barred from our GLNs; an
                            // organization facility is supposed to carry one.
                            ->rule(
                                new RejectTenantGln,
                                fn (Get $get): bool => filled($get('trading_partner_id')),
                            )
                            ->rule(
                                new RejectPartnerGlnUnderOrgPrefix,
                                fn (Get $get): bool => filled($get('trading_partner_id')),
                            ),
                        // Our own facilities get theirs from the organization company
                        // prefix; a partner's location has to be told to us unless we
                        // allocate GLNs from our prefix.
                        SglnRules::input()
                            ->helperText(fn (Get $get): string => filled($get('trading_partner_id'))
                                ? (TenantSettings::forTenant(tenant())->allowAssignPartnerGlnsFromPrefix()
                                    ? 'Optional when the GLN is under your organization prefix — SGLN is derived on save. Otherwise copy from the partner\'s EPCIS.'
                                    : 'Copy the SGLN from the partner\'s EPCIS — we do not guess where a partner\'s GS1 company prefix ends unless you allow partner GLNs from your prefix in Organization settings.')
                                : 'Filled from the GLN for your own facilities.'),
                        Toggle::make('is_headquarters')->default(false),
                        Toggle::make('is_active')->default(true),
                        TextInput::make('google_place_id')
                            ->label('Google Place ID')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Set by place enrichment')
                            ->columnSpanFull(),
                    ]),
                Section::make('Address')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('street_address')->maxLength(255)->columnSpanFull(),
                        TextInput::make('street_address_2')->maxLength(255)->columnSpanFull(),
                        TextInput::make('city')->maxLength(255),
                        TextInput::make('state')->maxLength(100),
                        TextInput::make('zipcode')->maxLength(20),
                        TextInput::make('country_code')->default('US')->maxLength(3),
                        TextInput::make('timezone')->maxLength(64)->placeholder('America/New_York')->columnSpanFull(),
                    ]),
                Section::make('Geo')
                    ->compact()
                    ->collapsed()
                    ->columns(['md' => 2, 'lg' => 3])
                    ->schema([
                        TextInput::make('latitude')->numeric(),
                        TextInput::make('longitude')->numeric(),
                        TextInput::make('altitude')->numeric(),
                    ]),
            ]);
    }
}
