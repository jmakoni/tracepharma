<?php

namespace App\Filament\App\Resources\ReceivingSessions;

use App\Filament\App\Resources\ReceivingSessions\Pages\CreateReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\Pages\ListReceivingSessions;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\App\Resources\ReceivingSessions\Schemas\ReceivingSessionForm;
use App\Filament\App\Resources\ReceivingSessions\Schemas\ReceivingSessionInfolist;
use App\Filament\App\Resources\ReceivingSessions\Tables\ReceivingSessionsTable;
use App\Models\Receiving\ReceivingSession;
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

class ReceivingSessionResource extends Resource
{
    protected static ?string $model = ReceivingSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Receive';

    protected static ?string $modelLabel = 'Receiving session';

    protected static ?string $pluralModelLabel = 'Receiving sessions';

    protected static ?string $slug = 'receiving-sessions';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsReceiving())
            && JobRoleAccess::allows(Permissions::NavReceive);
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
     * @return Builder<ReceivingSession>
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
        return ReceivingSessionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReceivingSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReceivingSessionsTable::configure($table);
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
            'index' => ListReceivingSessions::route('/'),
            'create' => CreateReceivingSession::route('/create'),
            'view' => ViewReceivingSession::route('/{record}'),
            'floor' => MobileViewReceivingSession::route('/{record}/floor'),
        ];
    }
}
