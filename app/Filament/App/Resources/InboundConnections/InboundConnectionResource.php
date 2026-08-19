<?php

namespace App\Filament\App\Resources\InboundConnections;

use App\Filament\App\Resources\InboundConnections\Pages\CreateInboundConnection;
use App\Filament\App\Resources\InboundConnections\Pages\EditInboundConnection;
use App\Filament\App\Resources\InboundConnections\Pages\ListInboundConnections;
use App\Filament\App\Resources\InboundConnections\Pages\ViewInboundConnection;
use App\Filament\App\Resources\InboundConnections\Schemas\InboundConnectionForm;
use App\Filament\App\Resources\InboundConnections\Schemas\InboundConnectionInfolist;
use App\Filament\App\Resources\InboundConnections\Tables\InboundConnectionsTable;
use App\Models\InboundConnection;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class InboundConnectionResource extends Resource
{
    protected static ?string $model = InboundConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Inbound Connections';

    protected static ?string $modelLabel = 'Inbound Connection';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsInboundIntegrations())
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
        return auth()->user()?->can('delete', $record) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return InboundConnectionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InboundConnectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InboundConnectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboundConnections::route('/'),
            'create' => CreateInboundConnection::route('/create'),
            'view' => ViewInboundConnection::route('/{record}'),
            'edit' => EditInboundConnection::route('/{record}/edit'),
        ];
    }
}
