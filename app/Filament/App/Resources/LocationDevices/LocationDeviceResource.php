<?php

namespace App\Filament\App\Resources\LocationDevices;

use App\Filament\App\Resources\LocationDevices\Pages\CreateLocationDevice;
use App\Filament\App\Resources\LocationDevices\Pages\EditLocationDevice;
use App\Filament\App\Resources\LocationDevices\Pages\ListLocationDevices;
use App\Filament\App\Resources\LocationDevices\Schemas\LocationDeviceForm;
use App\Filament\App\Resources\LocationDevices\Tables\LocationDevicesTable;
use App\Models\LocationDevice;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
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

class LocationDeviceResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = LocationDevice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 45;

    protected static ?string $navigationLabel = 'Location Devices';

    protected static ?string $modelLabel = 'Location Device';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavMasterData);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', static::getModel()) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete', $record) ?? false;
    }

    /**
     * @return Builder<LocationDevice>
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

        return $query->whereIn('site_id', SiteAccess::userSiteIds($user));
    }

    public static function form(Schema $schema): Schema
    {
        return LocationDeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationDevicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocationDevices::route('/'),
            'create' => CreateLocationDevice::route('/create'),
            'edit' => EditLocationDevice::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getDocumentation(): array|string
    {
        return 'master-data.sites-and-devices';
    }
}
