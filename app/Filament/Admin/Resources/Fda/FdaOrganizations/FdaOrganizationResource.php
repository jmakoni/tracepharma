<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\EditFdaOrganization;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\ListFdaOrganizations;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\ViewFdaOrganization;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers\EstablishmentsRelationManager;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers\MatchReviewsRelationManager;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers\ProductsRelationManager;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers\WddFacilitiesRelationManager;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Schemas\FdaOrganizationForm;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Schemas\FdaOrganizationInfolist;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Tables\FdaOrganizationsTable;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Fda\FdaOrganization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FdaOrganizationResource extends Resource
{
    use ViewOnlyFdaRegistryResource;

    protected static ?string $model = FdaOrganization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Organizations';

    protected static ?string $modelLabel = 'Organization';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FdaOrganizationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FdaOrganizationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaOrganizationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EstablishmentsRelationManager::class,
            WddFacilitiesRelationManager::class,
            ProductsRelationManager::class,
            MatchReviewsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaOrganizations::route('/'),
            'view' => ViewFdaOrganization::route('/{record}'),
            'edit' => EditFdaOrganization::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'canonical_name', 'gln', 'duns_number'];
    }
}
