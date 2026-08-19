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
use Illuminate\Validation\Rule;

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
        $tenantState = TenantReceivingState::resolve();
        $licenses = $ownerRecord->atpLicenses()->getQuery()->active();

        if ($tenantState !== null) {
            SiteAtpReadiness::applyStateMatch($licenses, $tenantState);
        }

        return (string) $licenses->count();
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
            TextInput::make('license_number')->required()->maxLength(100),
            Select::make('license_state')
                ->label('License state')
                ->options(UsState::selectOptions())
                ->required()
                ->searchable()
                ->native(false)
                // Rows imported before the list was closed can hold a full name or lower
                // case, which would render as an empty Select and silently blank the state.
                ->formatStateUsing(fn (?string $state): ?string => UsState::normalize($state))
                ->rule(Rule::in(UsState::codes()))
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
                TernaryFilter::make('for_receiving_state')
                    ->label('Receiving state')
                    ->placeholder('All states')
                    ->trueLabel('Receiving state only')
                    ->falseLabel('Other states')
                    ->visible(fn (): bool => TenantReceivingState::resolve() !== null)
                    ->default(fn (): bool => $this->shouldDefaultReceivingStateFilter())
                    ->queries(
                        true: fn (Builder $query): Builder => SiteAtpReadiness::applyStateMatch(
                            $query,
                            TenantReceivingState::resolve() ?? '',
                        ),
                        false: fn (Builder $query): Builder => SiteAtpReadiness::applyOtherStateMatch(
                            $query,
                            TenantReceivingState::resolve() ?? '',
                        ),
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
                    CreateAction::make()->slideOver(),
                    'sites_atp_create',
                    requireReason: false,
                ),
            ])
            ->headerActions([
                RegulatoryCompliance::apply(
                    CreateAction::make()->slideOver(),
                    'sites_atp_create',
                    requireReason: false,
                ),
            ])
            ->recordActions(RecordActionGroup::make([
                RegulatoryCompliance::apply(
                    EditAction::make()->slideOver(),
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
     * The provenance caveat is unconditional — every row here is a listing or a typed
     * entry, whether or not a receiving state is set to judge it against.
     */
    private static function tableDescription(): HtmlString
    {
        $lines = [AtpDisclosure::SOURCE];

        if (TenantReceivingState::resolve() === null) {
            $lines[] = 'Ask your administrator to set the tenant receiving state in Admin → Tenants to evaluate licenses for your location.';
        }

        return new HtmlString(implode('', array_map(
            fn (string $line): string => '<span class="block text-sm text-gray-500 dark:text-gray-400">'.e($line).'</span>',
            $lines,
        )));
    }

    private function shouldDefaultReceivingStateFilter(): bool
    {
        $status = request()->query('atp_status');

        if (in_array($status, ['expired', 'expiring', 'unknown_expiry', 'relevant'], true)) {
            return true;
        }

        return TenantReceivingState::resolve() !== null;
    }
}
