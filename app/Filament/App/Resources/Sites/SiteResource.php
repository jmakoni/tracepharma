<?php

namespace App\Filament\App\Resources\Sites;

use App\Filament\App\Resources\Sites\Pages\CreateSite;
use App\Filament\App\Resources\Sites\Pages\ListSites;
use App\Filament\App\Resources\Sites\Pages\ViewSite;
use App\Filament\App\Resources\Sites\RelationManagers\AtpLicensesRelationManager;
use App\Filament\App\Resources\Sites\RelationManagers\LocationDevicesRelationManager;
use App\Filament\App\Resources\Sites\RelationManagers\SsccNumberRangesRelationManager;
use App\Filament\App\Resources\Sites\Schemas\SiteForm;
use App\Filament\App\Resources\Sites\Schemas\SiteInfolist;
use App\Filament\App\Resources\Sites\Tables\SitesTable;
use App\Models\Site;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use UnitEnum;

class SiteResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Sites';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavMasterData);
    }

    public static function form(Schema $schema): Schema
    {
        return SiteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SiteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SitesTable::configure($table);
    }

    public static function getRelations(): array
    {
        $relations = [
            LocationDevicesRelationManager::class,
            AtpLicensesRelationManager::class,
        ];

        if (TenantFeatures::forTenant(tenant())->supportsSsccLabeling()) {
            $relations[] = SsccNumberRangesRelationManager::class;
        }

        return $relations;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'view' => ViewSite::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'master-data.sites-and-devices';
    }
}
