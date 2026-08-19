<?php

namespace App\Filament\App\Resources\OutboundShippingSessions;

use App\Filament\App\Resources\OutboundShippingSessions\Pages\CreateOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\ListOutboundShippingSessions;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\MobileViewOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\Pages\ViewOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\App\Resources\OutboundShippingSessions\Schemas\OutboundShippingSessionForm;
use App\Filament\App\Resources\OutboundShippingSessions\Schemas\OutboundShippingSessionInfolist;
use App\Filament\App\Resources\OutboundShippingSessions\Tables\OutboundShippingSessionsTable;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OutboundShippingSessionResource extends Resource
{
    protected static ?string $model = OutboundShippingSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Ship';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Ship Order';

    protected static ?string $modelLabel = 'Ship order';

    protected static ?string $pluralModelLabel = 'Ship orders';

    protected static ?string $slug = 'outbound-shipping-sessions';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations())
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
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
     * @return Builder<OutboundShippingSession>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        $siteIds = SiteAccess::userSiteIds($user);

        return $query->whereIn('site_id', $siteIds);
    }

    public static function form(Schema $schema): Schema
    {
        return OutboundShippingSessionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OutboundShippingSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutboundShippingSessionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ScanLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutboundShippingSessions::route('/'),
            'create' => CreateOutboundShippingSession::route('/create'),
            'view' => ViewOutboundShippingSession::route('/{record}'),
            'floor' => MobileViewOutboundShippingSession::route('/{record}/floor'),
        ];
    }
}
