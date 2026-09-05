<?php

namespace App\Filament\App\Resources\OutboundConnections;

use App\Filament\App\Resources\OutboundConnections\Pages\CreateOutboundConnection;
use App\Filament\App\Resources\OutboundConnections\Pages\EditOutboundConnection;
use App\Filament\App\Resources\OutboundConnections\Pages\ListOutboundConnections;
use App\Filament\App\Resources\OutboundConnections\Pages\ViewOutboundConnection;
use App\Filament\App\Resources\OutboundConnections\Schemas\OutboundConnectionForm;
use App\Filament\App\Resources\OutboundConnections\Schemas\OutboundConnectionInfolist;
use App\Filament\App\Resources\OutboundConnections\Tables\OutboundConnectionsTable;
use App\Models\OutboundConnection;
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

class OutboundConnectionResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = OutboundConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Outbound Connections';

    protected static ?string $modelLabel = 'Outbound Connection';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()
            && JobRoleAccess::allows(Permissions::NavIntegrations);
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
        if ($record instanceof OutboundConnection && $record->isSystemTemplate()) {
            return false;
        }

        return auth()->user()?->can('delete', $record) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return OutboundConnectionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OutboundConnectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutboundConnectionsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['tradingPartners']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutboundConnections::route('/'),
            'create' => CreateOutboundConnection::route('/create'),
            'view' => ViewOutboundConnection::route('/{record}'),
            'edit' => EditOutboundConnection::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'integrations.connections';
    }
}
