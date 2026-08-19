<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities;

use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\EditFdaWddFacility;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\ListFdaWddFacilities;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\ViewFdaWddFacility;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\RelationManagers\LicensesRelationManager;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Schemas\FdaWddFacilityForm;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Schemas\FdaWddFacilityInfolist;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Tables\FdaWddFacilitiesTable;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Fda\FdaWddFacility;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FdaWddFacilityResource extends Resource
{
    use ViewOnlyFdaRegistryResource;

    protected static ?string $model = FdaWddFacility::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'WDD Facilities';

    protected static ?string $modelLabel = 'WDD Facility';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FdaWddFacilityForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FdaWddFacilityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaWddFacilitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LicensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaWddFacilities::route('/'),
            'view' => ViewFdaWddFacility::route('/{record}'),
            'edit' => EditFdaWddFacility::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'facility_name', 'gln', 'code'];
    }
}
