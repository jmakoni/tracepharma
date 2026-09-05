<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SerializationLots;

use App\Enums\TenantProfile;
use App\Filament\App\Resources\SerializationLots\Pages\ListSerializationLots;
use App\Filament\App\Resources\SerializationLots\Pages\ViewSerializationLot;
use App\Filament\App\Resources\SerializationLots\Schemas\SerializationLotInfolist;
use App\Filament\App\Resources\SerializationLots\Tables\SerializationLotsTable;
use App\Models\L3\SerializationLot;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * UniTrace-style lot master list/detail for Guardian L3 lot-close ingest
 * ({@see SerializationLot}). Manufacturer-only, read-only —
 * lot rows are written exclusively by ConvertAndAcceptGuardianLotJob.
 */
class SerializationLotResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = SerializationLot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Serialization Lots';

    protected static ?string $modelLabel = 'Serialization lot';

    protected static ?string $pluralModelLabel = 'Serialization lots';

    protected static ?string $slug = 'serialization-lots';

    protected static ?string $recordTitleAttribute = 'lot_number';

    public static function canAccess(): bool
    {
        return tenant()?->profile === TenantProfile::Manufacturer
            && JobRoleAccess::allowsAny(
                Permissions::NavShip,
                Permissions::NavIntegrations,
                Permissions::NavCompliance,
            );
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

    /**
     * @return Builder<SerializationLot>
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

    public static function infolist(Schema $schema): Schema
    {
        return SerializationLotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SerializationLotsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSerializationLots::route('/'),
            'view' => ViewSerializationLot::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.serialization-lots';
    }
}
