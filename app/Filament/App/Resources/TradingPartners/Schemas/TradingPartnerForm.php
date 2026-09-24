<?php

namespace App\Filament\App\Resources\TradingPartners\Schemas;

use App\Enums\CmoOwnership;
use App\Enums\EpcisGuideline;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Filament\App\Support\FdaPicker;
use App\Models\TradingPartner;
use App\Rules\RejectPartnerGlnUnderOrgPrefix;
use App\Rules\RejectTenantGln;
use App\Support\Gs1\GlnRules;
use App\Support\Gs1\Gs1IdentityStatus;
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
                                        ->live(onBlur: true)
                                        ->unique(ignoreRecord: true)
                                        ->rule(new RejectTenantGln)
                                        ->rule(new RejectPartnerGlnUnderOrgPrefix),
                                    SglnRules::input()
                                        ->live(onBlur: true)
                                        ->helperText(function (Get $get, ?TradingPartner $record): string {
                                            if (TenantSettings::forTenant(tenant())->allowAssignPartnerGlnsFromPrefix()
                                                && Gs1IdentityStatus::canDeriveOrgSiteSgln(
                                                    is_string($get('gln')) ? $get('gln') : null,
                                                )) {
                                                return 'Optional — this GLN sits under your organization prefix, so SGLN is derived on save.';
                                            }

                                            return Gs1IdentityStatus::partnerSglnStatus(
                                                is_string($get('sgln')) ? $get('sgln') : null,
                                                is_string($get('gln')) ? $get('gln') : null,
                                                is_string($record?->sgln) ? $record->sgln : null,
                                            );
                                        }),
                                ]),
                                Grid::make(['default' => 4])->schema([
                                    TextInput::make('duns_number')->label('DUNS')->maxLength(14),
                                    TextInput::make('dea_number')->label('DEA')->maxLength(20),
                                    TextInput::make('hin_number')->label('HIN')->maxLength(20),
                                    TextInput::make('chemical_reg_number')->label('Chemical Reg')->maxLength(30),
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
                                Select::make('epcis_guideline')
                                    ->label('Outbound GS1 US DSCSA guideline')
                                    ->options(collect(EpcisGuideline::cases())->mapWithKeys(
                                        fn (EpcisGuideline $case): array => [$case->value => $case->label()]
                                    ))
                                    ->default(EpcisGuideline::R12->value)
                                    ->required()
                                    ->native(false)
                                    ->helperText('Outbound TI/TS only. Inbound accepts both GS1 US DSCSA guidelines R1.2 and R1.3 (independent of EPCIS schema 1.2/1.3/2.0).'),
                                Grid::make(['default' => 2])
                                    ->visible(fn (): bool => tenant()?->profile === TenantProfile::Manufacturer)
                                    ->schema([
                                        Toggle::make('is_cmo')
                                            ->label('Contract manufacturer (CMO)')
                                            ->helperText('Marks this partner as a CMO / contract packager for inbound auto-receive. Not a tenant profile.')
                                            ->default(false)
                                            ->live()
                                            ->inline(false),
                                        Toggle::make('auto_receive_inbound')
                                            ->label('Auto-receive inbound')
                                            ->helperText('Requires Organization “Auto-receive from CMO partners” and this CMO flag. Ignored when either gate is off.')
                                            ->default(false)
                                            ->visible(fn (Get $get): bool => (bool) $get('is_cmo'))
                                            ->disabled(fn (): bool => ! TenantSettings::forTenant(tenant())->autoReceiveFromCmo())
                                            ->dehydrated()
                                            ->inline(false),
                                    ]),
                                Select::make('cmo_ownership')
                                    ->label('CMO product ownership')
                                    ->options(collect(CmoOwnership::cases())->mapWithKeys(
                                        fn (CmoOwnership $case): array => [$case->value => $case->label()]
                                    ))
                                    ->default(CmoOwnership::CmoSells->value)
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => (bool) $get('is_cmo')
                                        && tenant()?->profile === TenantProfile::Manufacturer)
                                    ->helperText('Own product: inbound may omit DSCSA TS (contract packager). CMO sells: full TS required.'),
                                Grid::make(['default' => 2])->schema([
                                    TextInput::make('telephone')->tel()->maxLength(50),
                                    TextInput::make('email')->email()->maxLength(255),
                                ]),
                                TextInput::make('vrs_notify_email')
                                    ->label('VRS notify email')
                                    ->email()
                                    ->maxLength(255)
                                    ->helperText('Where manufacturer verification failures are emailed. Leave blank to use the partner email for manufacturers.'),
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
