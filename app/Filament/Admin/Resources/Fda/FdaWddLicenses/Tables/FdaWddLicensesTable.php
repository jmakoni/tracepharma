<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddLicenses\Tables;

use App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource;
use App\Filament\Admin\Resources\Fda\FdaWddLicenses\Schemas\FdaWddLicenseForm;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaWddLicensesTable
{
    public static function configure(Table $table, bool $standalone = true): Table
    {
        $columns = [
            FdaRegistryBadges::identifierColumn('license_number', 'License number')
                ->columnFilter(
                    ColumnFilter::select()
                        ->attribute('license_number')
                        ->options(fn (): array => DistinctColumnOptions::of(FdaWddLicense::class, 'license_number')),
                ),
            TextColumn::make('jurisdiction')
                ->searchable()
                ->sortable()
                ->columnFilter(
                    ColumnFilter::select()
                        ->attribute('jurisdiction')
                        ->options(fn (): array => DistinctColumnOptions::of(FdaWddLicense::class, 'jurisdiction')),
                ),
            TextColumn::make('expiration_date')
                ->date()
                ->sortable()
                ->columnFilter(ColumnFilter::date()),
            FdaRegistryBadges::licenseColumn(),
        ];

        if ($standalone) {
            array_unshift(
                $columns,
                TextColumn::make('facility.name')
                    ->label('Facility')
                    ->placeholder(fn (FdaWddLicense $record): ?string => $record->facility?->facility_name)
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('fda_wdd_facility_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                FdaWddLicense::class,
                                'fda_wdd_facility_id',
                                FdaWddFacility::class,
                            )),
                    ),
                TextColumn::make('facility.organization.name')->label('Organization')->searchable(),
                TextColumn::make('facility.street_address')
                    ->label('Street')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            );
        }

        $table = $table
            ->columns($columns)
            ->defaultSort('jurisdiction')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks();

        if ($standalone) {
            $table
                ->modifyQueryUsing(fn (Builder $query) => $query->with(['facility.organization']))
                ->searchPlaceholder('License number, facility, organization, or street')
                ->recordUrl(fn (FdaWddLicense $record): ?string => $record->fda_wdd_facility_id
                    ? FdaWddFacilityResource::getUrl('view', ['record' => $record->fda_wdd_facility_id])
                    : null)
                ->recordActions(RecordActionGroup::make([
                    ViewAction::make()
                        ->url(fn (FdaWddLicense $record): ?string => $record->fda_wdd_facility_id
                            ? FdaWddFacilityResource::getUrl('view', ['record' => $record->fda_wdd_facility_id])
                            : null),
                    EditAction::make()
                        ->schema(fn (Schema $schema): Schema => FdaWddLicenseForm::configure($schema)),
                ]));
        }

        return $table;
    }
}
