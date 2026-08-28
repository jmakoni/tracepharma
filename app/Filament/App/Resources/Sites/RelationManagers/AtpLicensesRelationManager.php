<?php

namespace App\Filament\App\Resources\Sites\RelationManagers;

use App\Enums\AtpLicenseExpirationStatus;
use App\Enums\FacilityType;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Support\MasterData\AtpDisclosure;
use App\Support\MasterData\AtpLicenseExpiry;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\SiteAtpReadiness;
use App\Support\MasterData\TenantReceivingState;
use App\Support\Places\UsState;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class AtpLicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'atpLicenses';

    protected static ?string $title = 'ATP Licenses';

    protected static bool $isBadgeDeferred = true;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Site $ownerRecord */
        return (string) SiteAtpReadiness::summarize($ownerRecord)['relevant_total'];
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Site $ownerRecord */
        return SiteAtpReadiness::summarize($ownerRecord)['status']->badgeColor();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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
                    ? \Illuminate\Validation\Rule::in(UsState::codes())
                    : null)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
            TextInput::make('license_jurisdiction')
                ->label('License jurisdiction')
                ->required(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) !== 'US')
                ->maxLength(16)
                ->helperText('Province, territory, or other subdivision code (e.g. ON, BC).')
                ->visible(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) !== 'US')
                ->dehydrated(fn (Get $get): bool => AtpLicenseRelevance::normalizeCountry($get('license_country')) !== 'US')
                ->formatStateUsing(function (?string $state, ?AtpLicense $record): ?string {
                    if ($record instanceof AtpLicense
                        && AtpLicenseRelevance::normalizeCountry($record->license_country ?? 'US') !== 'US') {
                        return $record->license_state;
                    }

                    return $state;
                })
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
            DatePicker::make('license_expiration_date')
                ->helperText('Without an expiration date the license cannot be shown to be in force, so the site is not ATP ready.'),
            TextInput::make('reporting_year')->numeric()->required()->default((int) now()->year),
            TextInput::make('facility_contact_person')->maxLength(255),
            TextInput::make('facility_contact_email')->email()->maxLength(255),
            TextInput::make('facility_contact_phone')->tel()->maxLength(50),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): HtmlString => self::tableDescription())
            ->searchPlaceholder('License # or state')
            ->columns([
                TextColumn::make('facility_type')->badge(),
                TextColumn::make('license_number')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('license_country')
                    ->label('Country')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('license_state')
                    ->searchable(),
                TextColumn::make('expiration_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (AtpLicense $record): string => $record->expirationStatus()->label())
                    ->color(fn (AtpLicense $record): string => $record->expirationStatus()->badgeColor())
                    ->icon(fn (AtpLicense $record): Heroicon => match ($record->expirationStatus()) {
                        AtpLicenseExpirationStatus::Expired => Heroicon::OutlinedXCircle,
                        AtpLicenseExpirationStatus::Expiring => Heroicon::OutlinedExclamationTriangle,
                        AtpLicenseExpirationStatus::UnknownExpiry => Heroicon::OutlinedQuestionMarkCircle,
                        AtpLicenseExpirationStatus::Active => Heroicon::OutlinedCheckCircle,
                    }),
                TextColumn::make('license_expiration_date')
                    ->date()
                    ->placeholder('Unknown')
                    ->sortable(),
                TextColumn::make('reporting_year')->toggleable(),
                IconColumn::make('is_active')
                    ->label('In effect')
                    ->boolean()
                    ->tooltip(fn (AtpLicense $record): ?string => $record->is_active
                        ? null
                        : 'Removed from the catalog by the last sync.')
                    ->toggleable(),
            ])
            ->defaultSort('license_expiration_date')
            ->filters([
                TernaryFilter::make('for_org_footprint')
                    ->label('Org jurisdictions')
                    ->placeholder('All licenses')
                    ->trueLabel('Org footprint only')
                    ->falseLabel('Outside footprint')
                    ->visible(fn (): bool => AtpLicenseRelevance::evaluationJurisdictionKeys() !== [])
                    ->default(fn (): bool => $this->shouldDefaultFootprintFilter())
                    ->queries(
                        true: fn (Builder $query): Builder => SiteAtpReadiness::applyFootprintRelevantMatch($query),
                        false: fn (Builder $query): Builder => SiteAtpReadiness::applyOutsideFootprintMatch($query),
                    ),
                SelectFilter::make('license_state')
                    ->label('State')
                    ->options(fn (): array => $this->getOwnerRecord()
                        ->atpLicenses()
                        ->select('license_state')
                        ->distinct()
                        ->orderBy('license_state')
                        ->pluck('license_state', 'license_state')
                        ->mapWithKeys(fn (string $state): array => [
                            strtoupper(trim($state)) => strtoupper(trim($state)),
                        ])
                        ->all()),
                SelectFilter::make('facility_type')
                    ->options(collect(FacilityType::cases())->mapWithKeys(
                        fn (FacilityType $type) => [$type->value => $type->label()]
                    )),
                TernaryFilter::make('is_active')
                    ->label('In effect')
                    ->default(true),
                Filter::make('expired')
                    ->label('Expired')
                    ->toggle()
                    ->default(fn (): bool => request()->query('atp_status') === 'expired')
                    ->query(fn (Builder $query): Builder => AtpLicenseExpiry::expired($query)),
                Filter::make('expiring_soon')
                    ->label('Expiring within 90 days')
                    ->toggle()
                    ->default(fn (): bool => request()->query('atp_status') === 'expiring')
                    ->query(fn (Builder $query): Builder => AtpLicenseExpiry::expiringSoon($query)),
                Filter::make('unknown_expiry')
                    ->label('Unknown expiry')
                    ->toggle()
                    ->default(fn (): bool => request()->query('atp_status') === 'unknown_expiry')
                    ->query(fn (Builder $query): Builder => AtpLicenseExpiry::unknownExpiry($query)),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->emptyStateHeading('No ATP licenses for this site')
            ->emptyStateDescription('Licenses are copied from the catalog when you receive a partner site, or you can add one manually.')
            ->emptyStateActions([
                RegulatoryCompliance::apply(
                    CreateAction::make()
                        ->slideOver()
                        ->fillForm(fn (): array => [
                            'license_country' => 'US',
                            'license_state' => TenantReceivingState::resolve()
                                ?? (AtpLicenseRelevance::tenantFootprintUsStates()[0] ?? null),
                            'reporting_year' => (int) now()->year,
                        ])
                        ->mutateFormDataUsing(fn (array $data): array => $this->prepareLicenseFormData($data)),
                    'sites_atp_create',
                    requireReason: false,
                ),
            ])
            ->headerActions([
                RegulatoryCompliance::apply(
                    CreateAction::make()
                        ->slideOver()
                        ->fillForm(fn (): array => [
                            'license_country' => 'US',
                            'license_state' => TenantReceivingState::resolve()
                                ?? (AtpLicenseRelevance::tenantFootprintUsStates()[0] ?? null),
                            'reporting_year' => (int) now()->year,
                        ])
                        ->mutateFormDataUsing(fn (array $data): array => $this->prepareLicenseFormData($data)),
                    'sites_atp_create',
                    requireReason: false,
                ),
            ])
            ->recordActions(RecordActionGroup::make([
                RegulatoryCompliance::apply(
                    EditAction::make()
                        ->slideOver()
                        ->mutateFormDataUsing(fn (array $data, AtpLicense $record): array => $this->prepareLicenseFormData($data, $record)),
                    'sites_atp_edit',
                    requireReason: false,
                ),
                RegulatoryCompliance::apply(
                    DeleteAction::make(),
                    'sites_atp_delete',
                    requireReason: true,
                ),
            ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareLicenseFormData(array $data, ?AtpLicense $ignore = null): array
    {
        $data = self::normalizeLicenseFormData($data);
        self::assertUniqueLicense($this->getOwnerRecord(), $data, $ignore);

        return $data;
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
    private static function assertUniqueLicense(Model $site, array $data, ?AtpLicense $ignore = null): void
    {
        $rule = \Illuminate\Validation\Rule::unique('atp_licenses', 'license_number')
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
     * The provenance caveat is unconditional — every row here is a listing or a typed
     * entry, whether or not a receiving state is set to judge it against.
     */
    private static function tableDescription(): HtmlString
    {
        $lines = [AtpDisclosure::SOURCE];

        if (AtpLicenseRelevance::evaluationJurisdictionKeys() === []) {
            $lines[] = 'Add organization facility sites with country/state, or set a preferred receiving state, so licenses can be evaluated.';
        } elseif (TenantReceivingState::resolve() === null && AtpLicenseRelevance::tenantFootprintKeys() !== []) {
            $lines[] = 'Optional: set a preferred receiving state in Organization settings for badge labels (evaluation already uses org site jurisdictions).';
        }

        return new HtmlString(implode('', array_map(
            fn (string $line): string => '<span class="block text-sm text-gray-500 dark:text-gray-400">'.e($line).'</span>',
            $lines,
        )));
    }

    private function shouldDefaultFootprintFilter(): bool
    {
        $status = request()->query('atp_status');

        if (in_array($status, ['expired', 'expiring', 'unknown_expiry', 'relevant'], true)) {
            return true;
        }

        return AtpLicenseRelevance::evaluationJurisdictionKeys() !== [];
    }
}
