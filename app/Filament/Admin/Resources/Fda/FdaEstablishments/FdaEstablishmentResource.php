<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\EditFdaEstablishment;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\ListFdaEstablishments;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\ViewFdaEstablishment;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\RelationManagers\OperationsRelationManager;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Schemas\FdaEstablishmentForm;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Schemas\FdaEstablishmentInfolist;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Tables\FdaEstablishmentsTable;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Fda\FdaEstablishment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FdaEstablishmentResource extends Resource
{
    use ViewOnlyFdaRegistryResource;

    protected static ?string $model = FdaEstablishment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Establishments';

    protected static ?string $modelLabel = 'Establishment';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FdaEstablishmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FdaEstablishmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaEstablishmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OperationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaEstablishments::route('/'),
            'view' => ViewFdaEstablishment::route('/{record}'),
            'edit' => EditFdaEstablishment::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['fei_number', 'name', 'firm_name', 'gln'];
    }
}
