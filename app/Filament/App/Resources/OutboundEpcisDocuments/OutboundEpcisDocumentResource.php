<?php

namespace App\Filament\App\Resources\OutboundEpcisDocuments;

use App\Filament\App\Resources\EpcisDocuments\RelationManagers\EpcsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\EventsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\ExceptionsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\ProductsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\Schemas\EpcisDocumentInfolist;
use App\Filament\App\Resources\OutboundEpcisDocuments\Pages\ListOutboundEpcisDocuments;
use App\Filament\App\Resources\OutboundEpcisDocuments\Pages\ViewOutboundEpcisDocument;
use App\Filament\App\Resources\OutboundEpcisDocuments\Tables\OutboundEpcisDocumentsTable;
use App\Models\Epcis\EpcisDocument;
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

class OutboundEpcisDocumentResource extends Resource
{
    protected static ?string $model = EpcisDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Ship';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Outbound EPCIS';

    protected static ?string $modelLabel = 'Outbound EPCIS document';

    protected static ?string $pluralModelLabel = 'Outbound EPCIS';

    protected static ?string $slug = 'outbound-epcis';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations())
            && JobRoleAccess::allows(Permissions::NavShip);
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
     * @return Builder<EpcisDocument>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('epcis_documents.direction', 'outbound');

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereIn('ship_from_site_id', SiteAccess::userSiteIds($user));
    }

    public static function infolist(Schema $schema): Schema
    {
        return EpcisDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutboundEpcisDocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
            EventsRelationManager::class,
            EpcsRelationManager::class,
            ExceptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutboundEpcisDocuments::route('/'),
            'view' => ViewOutboundEpcisDocument::route('/{record}'),
        ];
    }
}
