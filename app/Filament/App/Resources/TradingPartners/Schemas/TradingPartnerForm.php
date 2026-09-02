<?php

namespace App\Filament\App\Resources\TradingPartners\Schemas;

use App\Enums\PartnerType;
use App\Filament\App\Support\FdaPicker;
use App\Rules\RejectPartnerGlnUnderOrgPrefix;
use App\Rules\RejectTenantGln;
use App\Support\Gs1\GlnRules;
use App\Support\Gs1\SglnRules;
use App\Support\TenantSettings;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class TradingPartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        $fdaFields = FdaPicker::tradingPartnerOrganization();
        $fdaSelect = $fdaFields[0]
            ->label('From FDA organization')
            ->placeholder('Start from the FDA registry...')
            ->helperText(null)
            ->native(false);

        return $schema
            ->columns(1)
            ->components([
                $fdaSelect,
                ...array_slice($fdaFields, 1),
                Placeholder::make('fda_create_preview')
                    ->hiddenLabel()
                    ->hiddenOn('edit')
                    ->visible(fn (Get $get): bool => filled($get('fda_pick')) && filled($get('name')))
                    ->content(function (Get $get): HtmlString {
                        $preview = FdaPicker::tradingPartnerCreatePreview(
                            is_string($get('fda_pick')) ? $get('fda_pick') : null,
                            is_string($get('name')) ? $get('name') : null,
                            is_string($get('gln')) ? $get('gln') : null,
                        );

                        return new HtmlString(nl2br(e($preview ?? ''), false));
                    }),
                Grid::make(['default' => 2])
                    ->extraAttributes(['class' => 'tp-equal-height-sections'])
                    ->schema([
                        Section::make('Address')
                            ->compact()
                            ->columns(1)
                            ->schema([
                                TextInput::make('street_address')->maxLength(255),
                                TextInput::make('street_address_2')->maxLength(255),
                                Grid::make(['default' => 4])
                                    ->schema([
                                        TextInput::make('city')->maxLength(100),
                                        TextInput::make('state')->maxLength(100),
                                        TextInput::make('zipcode')->maxLength(20),
                                        TextInput::make('country_code')->default('US')->maxLength(3),
                                    ]),
                                TextInput::make('timezone')->maxLength(64)->placeholder('America/New_York'),
                            ]),
                        Section::make('Identity')
                            ->compact()
                            ->columns(1)
                            ->schema([
                                Grid::make(['default' => 2])->schema([
                                    TextInput::make('name')->required()->maxLength(255),
                                    TextInput::make('doing_business_as')->label('DBA')->maxLength(255),
                                ]),
                                Grid::make(['default' => 2])->schema([
                                    GlnRules::input()
                                        ->unique(ignoreRecord: true)
                                        ->rule(new RejectTenantGln)
                                        ->rule(new RejectPartnerGlnUnderOrgPrefix),
                                    SglnRules::input()
                                        ->helperText(fn (): string => TenantSettings::forTenant(tenant())->allowAssignPartnerGlnsFromPrefix()
                                            ? 'Optional when the GLN is under your organization prefix — SGLN is derived on save. Otherwise copy the partner\'s stated SGLN from their EPCIS.'
                                            : 'Copy the partner\'s stated SGLN from their EPCIS — we do not guess where a partner\'s GS1 company prefix ends unless you allow partner GLNs from your prefix in Organization settings.'),
                                ]),
                                Grid::make(['default' => 3])->schema([
                                    TextInput::make('duns_number')->label('DUNS')->maxLength(9),
                                    TextInput::make('dea_number')->label('DEA')->maxLength(20),
                                    TextInput::make('hin_number')->label('HIN')->maxLength(20),
                                ]),
                                Grid::make(['default' => 2])->schema([
                                    Select::make('partner_type')
                                        ->options(collect(PartnerType::cases())->mapWithKeys(
                                            fn (PartnerType $type) => [$type->value => $type->label()]
                                        ))
                                        ->required()
                                        ->native(false),
                                    Toggle::make('is_active')
                                        ->default(true)
                                        ->inline(false),
                                ]),
                                Grid::make(['default' => 2])->schema([
                                    TextInput::make('telephone')->tel()->maxLength(50),
                                    TextInput::make('email')->email()->maxLength(255),
                                ]),
                                TextInput::make('website')->url()->maxLength(255),
                            ]),
                    ]),
                Section::make('Geo')
                    ->compact()
                    ->collapsed()
                    ->columns(['default' => 3])
                    ->schema([
                        TextInput::make('latitude')->numeric(),
                        TextInput::make('longitude')->numeric(),
                        TextInput::make('altitude')->numeric(),
                    ]),
            ]);
    }
}
