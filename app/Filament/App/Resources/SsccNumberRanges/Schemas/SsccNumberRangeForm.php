<?php

namespace App\Filament\App\Resources\SsccNumberRanges\Schemas;

use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Models\SsccNumberRange;
use App\Models\TradingPartner;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSsccSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SsccNumberRangeForm
{
    public static function configure(Schema $schema): Schema
    {
        $sscc = TenantSsccSettings::resolve();
        $orgPrefix = (string) ($sscc['company_prefix'] ?? '');

        return $schema
            ->components([
                Section::make('Identity')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(64)
                            ->regex('/^[A-Za-z0-9_-]+$/')
                            ->helperText('Friendly API-safe name: letters, numbers, underscore, hyphen only.')
                            ->unique(ignoreRecord: true),
                        Select::make('scope')
                            ->options(collect(SsccNumberRangeScope::cases())->mapWithKeys(
                                fn (SsccNumberRangeScope $scope): array => [$scope->value => $scope->label()]
                            ))
                            ->default(SsccNumberRangeScope::Tenant->value)
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabled(fn (?SsccNumberRange $record): bool => $record !== null),
                        Select::make('site_id')
                            ->label('Site')
                            ->options(function (?SsccNumberRange $record): array {
                                $options = EligibleReceiveSites::forOrganization()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();

                                // Keep the current (possibly ineligible/inactive) owner visible so it doesn't
                                // silently disappear from an existing range's edit form.
                                if ($record?->site_id !== null && ! array_key_exists($record->site_id, $options)) {
                                    $options[$record->site_id] = $record->site?->name ?? "Site #{$record->site_id}";
                                }

                                return $options;
                            })
                            ->searchable()
                            ->helperText('Organization facilities only (tenant sites with GLN).')
                            ->visible(fn (Get $get): bool => $get('scope') === SsccNumberRangeScope::Site->value)
                            ->required(fn (Get $get): bool => $get('scope') === SsccNumberRangeScope::Site->value)
                            ->disabled(fn (?SsccNumberRange $record): bool => $record !== null),
                        Select::make('trading_partner_id')
                            ->label('Trading partner')
                            ->options(function (?SsccNumberRange $record): array {
                                $options = TradingPartner::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();

                                // Keep the current (possibly ineligible/inactive) owner visible so it doesn't
                                // silently disappear from an existing range's edit form.
                                if ($record?->trading_partner_id !== null && ! array_key_exists($record->trading_partner_id, $options)) {
                                    $options[$record->trading_partner_id] = $record->tradingPartner?->name ?? "Partner #{$record->trading_partner_id}";
                                }

                                return $options;
                            })
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('scope') === SsccNumberRangeScope::Partner->value)
                            ->required(fn (Get $get): bool => $get('scope') === SsccNumberRangeScope::Partner->value)
                            ->disabled(fn (?SsccNumberRange $record): bool => $record !== null),
                        TextInput::make('company_prefix')
                            ->label('Company prefix')
                            ->default($orgPrefix)
                            ->required()
                            ->helperText(blank($orgPrefix)
                                ? 'Set the organization GS1 Company Prefix under Organization settings first.'
                                : 'Must match Organization settings GCP. Change it there if needed.')
                            ->disabled(fn (?SsccNumberRange $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('extension_digit')
                            ->label('Extension digit')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(9)
                            ->default((int) ($sscc['extension_digit'] ?? 0))
                            ->required()
                            ->helperText('Defaults from Organization → SSCC labeling.')
                            ->disabled(fn (?SsccNumberRange $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('gs1_api_key')
                            ->label('GS1 API key')
                            ->password()
                            ->revealable()
                            ->helperText(fn (?SsccNumberRange $record): string => $record !== null
                                ? 'Leave blank to keep the existing key. Check “Clear GS1 API key” to remove it.'
                                : 'Optional credential for future replenishment. Stored encrypted; not called externally in this release.')
                            ->maxLength(255)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                        Toggle::make('clear_gs1_api_key')
                            ->label('Clear GS1 API key')
                            ->default(false)
                            ->helperText('Ignored if you also enter a new key above.')
                            ->visible(fn (?SsccNumberRange $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('index')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?SsccNumberRange $record): bool => $record !== null)
                            ->helperText('Auto-assigned when ranges are replenished for the same owner.'),
                    ]),
                Section::make('Allocation')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('increment_by')
                            ->label('Increment by')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(1)
                            ->required()
                            ->helperText('Increment each serial by this amount (max 100). Locked after create to protect the serial band.')
                            ->disabled(fn (?SsccNumberRange $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('range_size')
                            ->label('Number range size')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue((int) config('sscc.max_range_size', 1_000_000))
                            ->required()
                            ->helperText('How many serials this range can issue (max '.number_format((int) config('sscc.max_range_size', 1_000_000)).').')
                            ->disabled(fn (?SsccNumberRange $record): bool => $record !== null)
                            ->dehydrated(),
                        TextInput::make('threshold_percentage')
                            ->label('Threshold percentage')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(80)
                            ->required()
                            ->suffix('%')
                            ->helperText('When utilization reaches this %, owners are alerted to replenish.'),
                        Select::make('status')
                            ->options(function (?SsccNumberRange $record): array {
                                $options = collect([SsccNumberRangeStatus::Active, SsccNumberRangeStatus::Inactive])
                                    ->mapWithKeys(fn (SsccNumberRangeStatus $status): array => [$status->value => $status->label()]);

                                if ($record?->status === SsccNumberRangeStatus::Depleted) {
                                    $options->put(SsccNumberRangeStatus::Depleted->value, SsccNumberRangeStatus::Depleted->label());
                                }

                                return $options->all();
                            })
                            ->disableOptionWhen(fn (string $value): bool => $value === SsccNumberRangeStatus::Depleted->value)
                            ->default(SsccNumberRangeStatus::Active->value)
                            ->required()
                            ->native(false)
                            ->helperText('Inactive ranges are not used for new allocations. Depleted is set automatically and cannot be chosen manually.')
                            ->visible(fn (?SsccNumberRange $record): bool => $record !== null),
                        TextInput::make('start_number')
                            ->label('Start number')
                            ->numeric()
                            ->minValue(0)
                            ->default(1)
                            ->required()
                            ->helperText('First serial reference in this range. Typically matches Current number.')
                            ->visible(fn (?SsccNumberRange $record): bool => $record === null),
                        TextInput::make('current_number')
                            ->label('Current number')
                            ->numeric()
                            ->minValue(0)
                            ->default(1)
                            ->required()
                            ->helperText('Next serial to issue. Differ from Start only when restoring a range already partially used elsewhere.')
                            ->visible(fn (?SsccNumberRange $record): bool => $record === null),
                        TextInput::make('remaining')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?SsccNumberRange $record): bool => $record !== null),
                    ]),
            ]);
    }
}
