<?php

namespace App\Filament\App\Resources\EpcisDocuments;

use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Filament\App\Resources\EpcisDocuments\Pages\ViewEpcisDocument;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\EpcsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\EventsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\ExceptionsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\ProductsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\UnmatchedGlnsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\Schemas\EpcisDocumentInfolist;
use App\Filament\App\Resources\EpcisDocuments\Tables\EpcisDocumentsTable;
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

class EpcisDocumentResource extends Resource
{
    protected static ?string $model = EpcisDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Inbound EPCIS';

    protected static ?string $modelLabel = 'Inbound EPCIS document';

    protected static ?string $pluralModelLabel = 'Inbound EPCIS';

    protected static ?string $slug = 'inbound-epcis';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsInboundIntegrations())
            && JobRoleAccess::allows(Permissions::NavReceive);
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
            ->inboundCatalog();

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        return SiteAccess::constrainInboundDocuments($query, $user);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EpcisDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EpcisDocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
            EventsRelationManager::class,
            EpcsRelationManager::class,
            ExceptionsRelationManager::class,
            UnmatchedGlnsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEpcisDocuments::route('/'),
            'view' => ViewEpcisDocument::route('/{record}'),
        ];
    }
}
