<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Tables;

use App\Enums\FacilityType;
use App\Models\Fda\FdaWdd3plStaging;
use App\Support\Fda\FdaDate;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FdaWdd3plStagingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fdaOrganization']))
            ->columns([
                TextColumn::make('facility_name')->searchable()->sortable(),
                TextColumn::make('fdaOrganization.name')->label('Organization')->searchable()->sortable()->toggleable(),
                TextColumn::make('facility_type')->badge()->toggleable(),
                TextColumn::make('license_number')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('license_state'),
                TextColumn::make('state')->toggleable(),
                TextColumn::make('street_address')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->toggleable(),
                TextColumn::make('reporting_year')->toggleable(),
                TextColumn::make('contact_phone')
                    ->label('Contact phone')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expiration_date')
                    ->formatStateUsing(fn (?string $state): ?string => FdaDate::display($state))
                    ->toggleable(),
            ])
            ->defaultSort('expiration_date')
            ->filters([
                Filter::make('unpromoted')
                    ->label('Unpromoted')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->unpromoted()),
                Filter::make('missing_promote_fields')
                    ->label('Missing promote fields')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->missingPromoteFields()),
                SelectFilter::make('facility_type')
                    ->options(collect(FacilityType::cases())->mapWithKeys(
                        fn (FacilityType $type) => [$type->value => $type->label()]
                    )),
                SelectFilter::make('license_state')
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search): array => blank($search) ? [] : FdaWdd3plStaging::query()
                        ->whereNotNull('license_state')
                        ->where('license_state', 'like', '%'.$search.'%')
                        ->distinct()
                        ->orderBy('license_state')
                        ->limit(50)
                        ->pluck('license_state', 'license_state')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (string) $value : null),
            ], FiltersLayout::AboveContentCollapsible)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks();
    }
}
