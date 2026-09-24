<?php

namespace App\Filament\App\Resources\Sites\Schemas;

use App\Filament\App\Support\FdaPicker;
use App\Models\Site;
use App\Rules\RejectPartnerGlnUnderOrgPrefix;
use App\Rules\RejectTenantGln;
use App\Support\Gs1\GlnRules;
use App\Support\Gs1\Gs1IdentityStatus;
use App\Support\Gs1\SglnRules;
use App\Support\Places\UsState;
use App\Support\TenantFeatures;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

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
                            ->live()
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
                            ->live(onBlur: true)
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
                        SglnRules::input()
                            ->live(onBlur: true)
                            ->disabled(fn (Get $get): bool => blank($get('trading_partner_id'))
                                && Gs1IdentityStatus::canDeriveOrgSiteSgln(
                                    is_string($get('gln')) ? $get('gln') : null,
                                ))
                            ->dehydrated(fn (Get $get): bool => filled($get('trading_partner_id'))
                                || ! Gs1IdentityStatus::canDeriveOrgSiteSgln(
                                    is_string($get('gln')) ? $get('gln') : null,
                                ))
                            ->placeholder(function (Get $get): ?string {
                                if (filled($get('trading_partner_id'))) {
                                    return 'urn:epc:id:sgln:0614141.12345.0';
                                }

                                return Gs1IdentityStatus::resolveOrgSiteSgln(
                                    is_string($get('gln')) ? $get('gln') : null,
                                ) ?? 'urn:epc:id:sgln:0614141.12345.0';
                            })
                            ->helperText(function (Get $get, ?Site $record): string {
                                if (filled($get('trading_partner_id'))) {
                                    return Gs1IdentityStatus::partnerSglnStatus(
                                        is_string($get('sgln')) ? $get('sgln') : null,
                                        is_string($get('gln')) ? $get('gln') : null,
                                        is_string($record?->sgln) ? $record->sgln : null,
                                    );
                                }

                                return Gs1IdentityStatus::orgSiteSglnHelper(
                                    is_string($get('gln')) ? $get('gln') : null,
                                );
                            }),
                        TextInput::make('duns_number')->label('DUNS')->maxLength(14),
                        TextInput::make('dea_number')->label('DEA')->maxLength(20),
                        TextInput::make('hin_number')->label('HIN')->maxLength(20),
                        TextInput::make('chemical_reg_number')->label('Chemical Reg')->maxLength(30),
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
                        Select::make('state')
                            ->label('State')
                            ->options(UsState::selectOptions())
                            ->searchable()
                            ->native(false)
                            ->nullable()
                            ->dehydrateStateUsing(fn (?string $state): ?string => UsState::normalize($state))
                            ->rule(fn (Get $get): mixed => strtoupper((string) ($get('country_code') ?? 'US')) === 'US'
                                ? Rule::in(UsState::codes())
                                : null)
                            ->helperText('US postal code (IL). Full names from FDA prefill are normalized on save.'),
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
