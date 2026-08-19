<?php

namespace App\Filament\App\Resources\TradingPartners;

use App\Filament\App\Resources\TradingPartners\Pages\ListTradingPartners;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Filament\App\Resources\TradingPartners\RelationManagers\ContactRelationManager;
use App\Filament\App\Resources\TradingPartners\RelationManagers\ProductsRelationManager;
use App\Filament\App\Resources\TradingPartners\RelationManagers\SitesRelationManager;
use App\Filament\App\Resources\TradingPartners\Schemas\TradingPartnerForm;
use App\Filament\App\Resources\TradingPartners\Schemas\TradingPartnerInfolist;
use App\Filament\App\Resources\TradingPartners\Tables\TradingPartnersTable;
use App\Models\TradingPartner;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TradingPartnerResource extends Resource
{
    protected static ?string $model = TradingPartner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Trading Partners';

    protected static ?string $modelLabel = 'Trading Partner';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsMasterData()
            && static::canViewAny())
            && JobRoleAccess::allows(Permissions::NavMasterData);
    }

    public static function form(Schema $schema): Schema
    {
        return TradingPartnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TradingPartnerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TradingPartnersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SitesRelationManager::class,
            ProductsRelationManager::class,
            ContactRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTradingPartners::route('/'),
            'view' => ViewTradingPartner::route('/{record}'),
        ];
    }
}
