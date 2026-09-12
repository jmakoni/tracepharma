<?php

namespace App\Filament\App\Resources\Sites\Schemas;

use App\Enums\AtpLicenseExpirationStatus;
use App\Enums\FacilityType;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Support\Catalog\DisplayName;
use App\Support\Gs1\GlnRules;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\SiteAtpReadiness;
use App\Support\MasterData\TenantReceivingState;
use App\Support\Places\UsState;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteSlideOverInfolist
{
    public static function configure(Schema $schema, ?ViewAction $viewAction = null): Schema
    {
        return $schema->components([
            View::make('filament.admin.infolists.catalog-site-profile')
                ->columnSpanFull(),
            Tabs::make('Site details')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('ATP Readiness')
                        ->badge(fn (?Site $record): ?string => $record
                            ? (string) SiteAtpReadiness::relevantLicenses($record)->count()
                            : null)
                        ->schema([
                            View::make('filament.app.infolists.site-atp-readiness')
                                ->columnSpanFull(),
                            Section::make()
                                ->compact()
                                ->heading(fn (): HtmlString => self::sectionHeading(
                                    'Licenses for org jurisdictions',
                                    $viewAction?->getModalAction('createRelevantLicense'),
                                ))
                                ->schema([
                                    self::licenseRepeatableEntry(
                                        name: 'relevant_atp_licenses',
                                        state: fn (Site $record) => SiteAtpReadiness::relevantLicenses($record)->all(),
                                        emptyPlaceholder: 'No licenses for organization jurisdictions.',
                                    ),
                                ]),
                        ]),
                    Tab::make('Associated devices')
                        ->badge(fn (?Site $record): ?string => $record
                            ? (string) $record->locationDevices->count()
                            : null)
                        ->schema([
                            Section::make()
                                ->compact()
                                ->heading(fn (): HtmlString => self::sectionHeading(
                                    'Associated devices',
                                    $viewAction?->getModalAction('createDevice'),
                                ))
                                ->schema([
                                    RepeatableEntry::make('locationDevices')
                                        ->label('')
                                        ->table([
                                            TableColumn::make('Name'),
                                            TableColumn::make('GLN'),
                                            TableColumn::make('SGLN'),
                                        ])
                                        ->schema([
                                            TextEntry::make('name')
                                                ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                                            TextEntry::make('gln')
                                                ->label('GLN')
                                                ->copyable()
                                                ->fontFamily(FontFamily::Mono)
                                                ->placeholder('—'),
                                            TextEntry::make('sgln')
                                                ->label('SGLN')
                                                ->copyable()
                                                ->fontFamily(FontFamily::Mono)
                                                ->placeholder('—'),
                                        ])
                                        ->placeholder('No associated devices.'),
                                ]),
                        ]),
                    Tab::make('Outside org jurisdictions')
                        ->badge(fn (?Site $record): ?string => $record
                            ? (string) SiteAtpReadiness::otherStateLicenses($record)->count()
                            : null)
                        ->schema([
                            Section::make()
                                ->compact()
                                ->heading(fn (): HtmlString => self::sectionHeading(
                                    'Licenses outside org jurisdictions',
                                    $viewAction?->getModalAction('createOtherStateLicense'),
                                ))
                                ->schema([
                                    self::licenseRepeatableEntry(
                                        name: 'other_state_atp_licenses',
                                        state: fn (Site $record) => SiteAtpReadiness::otherStateLicenses($record)->all(),
                                        emptyPlaceholder: 'No licenses outside organization jurisdictions.',
                                    ),
                                ]),
                        ]),
                ]),
        ]);
    }

    /**
     * @return array<int, Action>
     */
    public static function createActions(): array
    {
        return [
            self::createRelevantLicenseAction(),
            self::createOtherStateLicenseAction(),
            self::createDeviceAction(),
        ];
    }

    public static function createRelevantLicenseAction(): Action
    {
        $preferredState = TenantReceivingState::resolve()
            ?? (AtpLicenseRelevance::tenantFootprintUsStates()[0] ?? '');

        return self::baseCreateLicenseAction('createRelevantLicense')
            ->fillForm(fn (): array => [
                'license_country' => 'US',
                'license_state' => $preferredState,
                'reporting_year' => (int) now()->year,
            ]);
    }

    public static function createOtherStateLicenseAction(): Action
    {
        return self::baseCreateLicenseAction('createOtherStateLicense')
            ->fillForm(fn (): array => [
                'license_country' => 'US',
                'reporting_year' => (int) now()->year,
            ]);
    }

    public static function createDeviceAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('createDevice')
                ->label('Add device')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->modal()
                ->modalHeading('Add device')
                ->modalWidth(Width::Large)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Textarea::make('description')->rows(2),
                    GlnRules::input()
                        ->required()
                        ->unique(table: 'location_devices'),
                ])
                ->action(function (Site $record, array $data): void {
                    $record->locationDevices()->create($data);
                    $record->unsetRelation('locationDevices');
                    $record->load('locationDevices');
                }),
            'sites_create_device',
            requireReason: false,
        );
    }

    private static function baseCreateLicenseAction(string $name): Action
    {
        return RegulatoryCompliance::apply(
            Action::make($name)
                ->label('Add license')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->modal()
                ->modalHeading('Add license')
                ->modalWidth(Width::Large)
                ->schema(self::atpLicenseFormComponents())
                ->action(function (Site $record, array $data): void {
                    $data = self::normalizeLicenseFormData($data);
                    self::assertUniqueLicense($record, $data);
                    $record->atpLicenses()->create($data);
                    $record->unsetRelation('atpLicenses');
                    $record->load('atpLicenses');
                    SiteAtpReadiness::forget($record);
                }),
            'sites_create_license',
            requireReason: false,
        );
    }

    private static function sectionHeading(string $heading, ?Action $action): HtmlString
    {
        $actionHtml = $action?->toHtml() ?? '';

        return new HtmlString(
            '<span class="tp-site-slideover-section-heading flex w-full items-center justify-between gap-3">'
            .'<span>'.e($heading).'</span>'
            .'<span class="shrink-0">'.$actionHtml.'</span>'
            .'</span>',
        );
    }

    /**
     * @return array<int, Select|TextInput|DatePicker>
     */
    private static function atpLicenseFormComponents(): array
    {
        return [
            Select::make('facility_type')
                ->options(collect(FacilityType::cases())->mapWithKeys(
                    fn (FacilityType $type) => [$type->value => $type->label()]
                ))
                ->required()
                ->native(false),
            TextInput::make('license_number')
                ->required()
                ->maxLength(100),
            Select::make('license_country')
                ->label('License country')
                ->options([
                    'US' => 'United States',
                    'CA' => 'Canada',
                    'MX' => 'Mexico',
                    'GB' => 'United Kingdom',
                    'DE' => 'Germany',
                    'FR' => 'France',
                    'IE' => 'Ireland',
                    'AU' => 'Australia',
                    'NZ' => 'New Zealand',
                    'JP' => 'Japan',
                    'CN' => 'China',
                    'IN' => 'India',
                    'BR' => 'Brazil',
                ])
                ->default('US')
                ->required()
                ->live()
                ->native(false)
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if (AtpLicenseRelevance::normalizeCountry($state) === 'US') {
                        $set('license_jurisdiction', null);
                    } else {
                        $set('license_state', null);
                    }
                })
                ->dehydrateStateUsing(fn (?string $state): string => AtpLicenseRelevance::normalizeCountry($state)),
            Select::make('license_state')
                ->label('License state')
                ->options(UsState::selectOptions())
                ->required(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) === 'US')
                ->searchable()
                ->native(false)
                ->visible(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) === 'US')
                ->dehydrated(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) === 'US')
                ->formatStateUsing(fn (?string $state): ?string => UsState::normalize($state))
                ->rule(fn (Get $get): mixed => AtpLicenseRelevance::normalizeCountry($get('license_country')) === 'US'
                    ? Rule::in(UsState::codes())
                    : null)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
            TextInput::make('license_jurisdiction')
                ->label('License jurisdiction')
                ->required(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) !== 'US')
                ->maxLength(16)
                ->helperText('Province, territory, or other subdivision code (e.g. ON, BC).')
                ->visible(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) !== 'US')
                ->dehydrated(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) !== 'US')
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
            DatePicker::make('license_expiration_date')
                ->helperText('Without an expiration date the license cannot be shown to be in force, so the site is not ATP ready.'),
            TextInput::make('reporting_year')->numeric()->required()->default((int) now()->year),
            TextInput::make('facility_contact_person')->maxLength(255),
            TextInput::make('facility_contact_email')->email()->maxLength(255),
            TextInput::make('facility_contact_phone')->tel()->maxLength(50),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeLicenseFormData(array $data): array
    {
        $country = AtpLicenseRelevance::normalizeCountry(
            isset($data['license_country']) ? (string) $data['license_country'] : 'US',
        );
        $data['license_country'] = $country;

        $rawState = $country === 'US'
            ? ($data['license_state'] ?? '')
            : ($data['license_jurisdiction'] ?? $data['license_state'] ?? '');

        unset($data['license_jurisdiction']);

        $data['license_state'] = AtpLicenseRelevance::normalizeSubdivision($country, (string) $rawState)
            ?? strtoupper(trim((string) $rawState));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertUniqueLicense(Site $site, array $data, ?AtpLicense $ignore = null): void
    {
        $rule = Rule::unique('atp_licenses', 'license_number')
            ->where('site_id', $site->getKey())
            ->where('license_country', $data['license_country'] ?? 'US')
            ->where('license_state', $data['license_state'] ?? '');

        if ($ignore instanceof AtpLicense) {
            $rule->ignore($ignore);
        }

        $validator = validator(
            ['license_number' => $data['license_number'] ?? null],
            ['license_number' => [$rule]],
            ['license_number.unique' => 'This site already has a license with this country, state, and number.'],
        );

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * @param  \Closure(Site): array<int, AtpLicense>  $state
     */
    private static function licenseRepeatableEntry(string $name, \Closure $state, string $emptyPlaceholder): RepeatableEntry
    {
        return RepeatableEntry::make($name)
            ->label('')
            ->getStateUsing($state)
            ->table([
                TableColumn::make('Facility'),
                TableColumn::make('License #'),
                TableColumn::make('State'),
                TableColumn::make('Status'),
                TableColumn::make('Expires'),
            ])
            ->schema([
                TextEntry::make('facility_type')
                    ->badge()
                    ->formatStateUsing(fn (FacilityType|string|null $state): string => match (true) {
                        $state instanceof FacilityType => $state->label(),
                        is_string($state) => FacilityType::tryFrom($state)?->label() ?? $state,
                        default => '—',
                    }),
                TextEntry::make('license_number')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextEntry::make('license_state')
                    ->placeholder('—'),
                TextEntry::make('expiration_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (AtpLicense $record): string => $record->expirationStatus()->label())
                    ->color(fn (AtpLicense $record): string => $record->expirationStatus()->badgeColor())
                    ->icon(fn (AtpLicense $record): Heroicon => match ($record->expirationStatus()) {
                        AtpLicenseExpirationStatus::Expired => Heroicon::OutlinedXCircle,
                        AtpLicenseExpirationStatus::Expiring => Heroicon::OutlinedExclamationTriangle,
                        AtpLicenseExpirationStatus::UnknownExpiry => Heroicon::OutlinedQuestionMarkCircle,
                        AtpLicenseExpirationStatus::Active => Heroicon::OutlinedCheckCircle,
                    }),
                TextEntry::make('license_expiration_date')
                    ->date()
                    ->placeholder('Unknown'),
            ])
            ->placeholder($emptyPlaceholder);
    }
}
