<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\Tables;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Auth\Permissions;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\WddOrganizationName;
use App\Support\PartnerSlug;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FdaWdd3plUnmatchedsTable
{
    /**
     * Resolutions are read back by the staging importer, so the facility's rows
     * land without anyone re-running triage.
     */
    private const NEXT_IMPORT_NOTE = 'The next weekly WDD/3PL import will stage its rows.';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('fdaOrganization'))
            ->columns([
                TextColumn::make('facility_name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('slug_attempt')
                    ->searchable()
                    ->sortable()
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(),
                TextColumn::make('facility_type')
                    ->label('FDA type')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (FacilityType|string|null $state): ?string => match (true) {
                        $state instanceof FacilityType => $state->label(),
                        is_string($state) => FacilityType::tryFrom($state)?->label() ?? $state,
                        default => null,
                    }),
                TextColumn::make('row_count')
                    ->label('Rows')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->since()
                    ->tooltip(fn (FdaWdd3plUnmatched $record): ?string => $record->last_seen_at?->toDayDateTimeString())
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Resolved' : 'Open')
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'warning'),
                TextColumn::make('fdaOrganization.name')
                    ->label('Linked organization')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                TernaryFilter::make('resolved')
                    ->label('Resolution status')
                    ->nullable()
                    ->trueLabel('Resolved')
                    ->falseLabel('Unresolved')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->resolved(),
                        false: fn (Builder $query): Builder => $query->unresolved(),
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->default(false),
            ], FiltersLayout::AboveContentCollapsible)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                self::createOrganizationAction(),
                self::linkExistingOrganizationAction(),
            ]));
    }

    private static function createOrganizationAction(): Action
    {
        return Action::make('createCatalogPartner')
            ->label('Create organization')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->authorize(fn (): bool => self::canCurateCatalog())
            ->visible(fn (FdaWdd3plUnmatched $record): bool => ! $record->isResolved())
            ->modal()
            ->modalWidth(Width::Large)
            ->modalHeading('Create organization')
            ->fillForm(fn (FdaWdd3plUnmatched $record): array => [
                'name' => $record->facility_name,
                'partner_type' => self::proposedPartnerType($record)->value,
            ])
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('partner_type')
                    ->options(collect(PartnerType::cases())->mapWithKeys(
                        fn (PartnerType $type): array => [$type->value => $type->label()],
                    )->all())
                    ->helperText(fn (FdaWdd3plUnmatched $record): ?string => $record->facility_type === null
                        ? null
                        : 'Proposed from the FDA Type on the skipped rows.')
                    ->required()
                    ->native(false),
            ])
            ->action(function (FdaWdd3plUnmatched $record, array $data): void {
                abort_unless(self::canCurateCatalog(), 403);

                $name = trim((string) $data['name']);
                $canonical = CompanyNameNormalizer::canonical($name) ?: $name;

                $organization = FdaOrganization::query()->create([
                    'original_name' => $name,
                    'canonical_name' => $canonical,
                    'name' => $name,
                    'partner_type' => $data['partner_type'] ?? PartnerType::Wholesaler,
                    'is_active' => true,
                ]);

                $record->update([
                    'fda_organization_id' => $organization->id,
                    'resolved_at' => now(),
                ]);

                Notification::make()
                    ->title('Organization created')
                    ->body("Linked {$record->facility_name} to {$organization->name}. ".self::NEXT_IMPORT_NOTE)
                    ->success()
                    ->send();
            });
    }

    private static function linkExistingOrganizationAction(): Action
    {
        return Action::make('linkExistingPartner')
            ->label('Link existing organization')
            ->icon(Heroicon::OutlinedLink)
            ->authorize(fn (): bool => self::canCurateCatalog())
            ->visible(fn (FdaWdd3plUnmatched $record): bool => ! $record->isResolved())
            ->modal()
            ->modalHeading('Link existing organization')
            ->schema([
                Select::make('fda_organization_id')
                    ->label('FDA organization')
                    ->searchable()
                    ->options(fn (FdaWdd3plUnmatched $record): array => self::nearDuplicateOptions($record))
                    ->helperText(fn (FdaWdd3plUnmatched $record): string => self::nearDuplicateOptions($record) === []
                        ? 'Search by name, canonical name or GLN.'
                        : 'Organizations with a matching name family are listed first.')
                    ->getSearchResultsUsing(function (?string $search): array {
                        if (blank($search)) {
                            return [];
                        }

                        return FdaOrganization::query()
                            ->where(function (Builder $query) use ($search): void {
                                $query->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('canonical_name', 'like', '%'.$search.'%')
                                    ->orWhere('original_name', 'like', '%'.$search.'%')
                                    ->orWhere('gln', 'like', '%'.$search.'%');
                            })
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => FdaOrganization::query()
                        ->find($value)?->name)
                    ->required(),
            ])
            ->action(function (FdaWdd3plUnmatched $record, array $data): void {
                abort_unless(self::canCurateCatalog(), 403);

                $organizationId = (int) $data['fda_organization_id'];

                $record->update([
                    'fda_organization_id' => $organizationId,
                    'resolved_at' => now(),
                ]);

                $organizationName = FdaOrganization::query()->find($organizationId)?->name ?? 'organization';

                Notification::make()
                    ->title('Organization linked')
                    ->body("Resolved {$record->facility_name} → {$organizationName}. ".self::NEXT_IMPORT_NOTE)
                    ->success()
                    ->send();
            });
    }

    private static function canCurateCatalog(): bool
    {
        return auth('admin')->user()?->can(Permissions::CatalogManage) ?? false;
    }
}
