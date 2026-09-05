<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts;

use App\Filament\Admin\Resources\Fda\FdaProducts\Pages\EditFdaProduct;
use App\Filament\Admin\Resources\Fda\FdaProducts\Pages\ListFdaProducts;
use App\Filament\Admin\Resources\Fda\FdaProducts\Pages\ViewFdaProduct;
use App\Filament\Admin\Resources\Fda\FdaProducts\RelationManagers\IngredientsRelationManager;
use App\Filament\Admin\Resources\Fda\FdaProducts\RelationManagers\PackagesRelationManager;
use App\Filament\Admin\Resources\Fda\FdaProducts\RelationManagers\RoutesRelationManager;
use App\Filament\Admin\Resources\Fda\FdaProducts\Schemas\FdaProductForm;
use App\Filament\Admin\Resources\Fda\FdaProducts\Schemas\FdaProductInfolist;
use App\Filament\Admin\Resources\Fda\FdaProducts\Tables\FdaProductsTable;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Fda\FdaProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FdaProductResource extends Resource implements HasKnowledgeBase
{
    use ViewOnlyFdaRegistryResource;

    protected static ?string $model = FdaProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $modelLabel = 'Product';

    protected static ?string $recordTitleAttribute = 'product_ndc';

    public static function form(Schema $schema): Schema
    {
        return FdaProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FdaProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PackagesRelationManager::class,
            IngredientsRelationManager::class,
            RoutesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaProducts::route('/'),
            'view' => ViewFdaProduct::route('/{record}'),
            'edit' => EditFdaProduct::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['product_ndc', 'name', 'brand_name', 'generic_name', 'packaging.ndc11', 'packaging.gtin'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['fdaOrganization', 'packaging']);
    }

    public static function getDocumentation(): array|string
    {
        return 'registry.fda-products';
    }
}
