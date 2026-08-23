<?php

namespace App\Filament\App\Resources\TransferringSessions;

use App\Filament\App\Resources\TransferringSessions\Pages\CreateTransferringSession;
use App\Filament\App\Resources\TransferringSessions\Pages\ListTransferringSessions;
use App\Filament\App\Resources\TransferringSessions\Pages\MobileViewTransferringSession;
use App\Filament\App\Resources\TransferringSessions\Pages\ViewTransferringSession;
use App\Filament\App\Resources\TransferringSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\App\Resources\TransferringSessions\Schemas\TransferringSessionForm;
use App\Filament\App\Resources\TransferringSessions\Schemas\TransferringSessionInfolist;
use App\Filament\App\Resources\TransferringSessions\Tables\TransferringSessionsTable;
use App\Models\Transferring\TransferringSession;
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

class TransferringSessionResource extends Resource
{
    protected static ?string $model = TransferringSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Transfer';

    protected static ?string $modelLabel = 'Transfer session';

    protected static ?string $pluralModelLabel = 'Transfer sessions';

    protected static ?string $slug = 'transferring-sessions';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsTransferring())
            && JobRoleAccess::allows(Permissions::NavShip);
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
        if (! $record instanceof TransferringSession) {
            return false;
        }

        $user = auth()->user();

        return $record->canHardDelete()
            && $user !== null
            && $user->can('delete', $record);
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
     * @return Builder<TransferringSession>
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

        $siteIds = SiteAccess::userSiteIds($user);

        return $query->where(function (Builder $scopedQuery) use ($siteIds): void {
            $scopedQuery
                ->whereIn('from_site_id', $siteIds)
                ->orWhereIn('to_site_id', $siteIds);
        });
    }

    public static function form(Schema $schema): Schema
    {
        return TransferringSessionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TransferringSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransferringSessionsTable::configure($table);
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
            'index' => ListTransferringSessions::route('/'),
            'create' => CreateTransferringSession::route('/create'),
            'view' => ViewTransferringSession::route('/{record}'),
            'floor' => MobileViewTransferringSession::route('/{record}/floor'),
        ];
    }
}
