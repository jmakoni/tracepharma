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
use App\Filament\App\Support\UsesTenantScoutGlobalSearch;
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
    use UsesTenantScoutGlobalSearch;

    protected static ?string $model = EpcisDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Inbound EPCIS';

    protected static ?string $modelLabel = 'Inbound EPCIS document';

    protected static ?string $pluralModelLabel = 'Inbound EPCIS';

    protected static ?string $slug = 'inbound-epcis';

    protected static ?string $recordTitleAttribute = 'original_filename';

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

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'original_filename',
            'document_uuid',
            'asn_number',
            'customer_po',
            'sender_gln',
        ];
    }

    /**
     * @return list<string>
     */
    protected static function tenantScoutSqlColumns(): array
    {
        return [
            'original_filename',
            'document_uuid',
            'asn_number',
            'customer_po',
            'sender_gln',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof EpcisDocument) {
            return [];
        }

        return array_filter([
            'Status' => $record->status,
            'ASN' => $record->asn_number,
            'PO' => $record->customer_po,
        ]);
    }
}
