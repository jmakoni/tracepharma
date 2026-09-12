<?php

namespace App\Filament\App\Resources\FdaProducts;

use App\Filament\App\Resources\FdaProducts\Pages\ListFdaProducts;
use App\Filament\App\Resources\FdaProducts\Pages\ViewFdaProduct;
use App\Filament\App\Resources\FdaProducts\Schemas\FdaProductInfolist;
use App\Filament\App\Resources\FdaProducts\Tables\FdaProductsTable;
use App\Models\Fda\FdaProduct;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FdaProductResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = FdaProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'FDA Products';

    protected static ?string $modelLabel = 'FDA Product';

    protected static ?string $recordTitleAttribute = 'product_ndc';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavMasterData);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        if (! parent::canView($record)) {
            return false;
        }

        return static::getEloquentQuery()
            ->whereKey($record->getKey())
            ->exists();
    }

    /**
     * @return Builder<FdaProduct>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->prescription()
            ->linkedToTenantProducts();
    }

    public static function infolist(Schema $schema): Schema
    {
        return FdaProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaProducts::route('/'),
            'view' => ViewFdaProduct::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'master-data.products';
    }
}
