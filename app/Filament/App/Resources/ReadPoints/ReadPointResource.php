<?php

namespace App\Filament\App\Resources\ReadPoints;

use App\Filament\App\Resources\ReadPoints\Pages\CreateReadPoint;
use App\Filament\App\Resources\ReadPoints\Pages\EditReadPoint;
use App\Filament\App\Resources\ReadPoints\Pages\ListReadPoints;
use App\Filament\App\Resources\ReadPoints\Schemas\ReadPointForm;
use App\Filament\App\Resources\ReadPoints\Tables\ReadPointsTable;
use App\Models\ReadPoint;
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

class ReadPointResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = ReadPoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Read Points';

    protected static ?string $modelLabel = 'Read Point';

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
     * @return Builder<ReadPoint>
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
        return ReadPointForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReadPointsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReadPoints::route('/'),
            'create' => CreateReadPoint::route('/create'),
            'edit' => EditReadPoint::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'master-data.sites-and-devices';
    }
}
