<?php

namespace App\Filament\App\Resources\Exceptions;

use App\Filament\App\Resources\Exceptions\Pages\ListExceptions;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Filament\App\Resources\Exceptions\RelationManagers\ActivitiesRelationManager;
use App\Filament\App\Resources\Exceptions\RelationManagers\EpcsRelationManager;
use App\Filament\App\Resources\Exceptions\Schemas\ExceptionInfolist;
use App\Filament\App\Resources\Exceptions\Tables\ExceptionsTable;
use App\Models\Exceptions\ExceptionCase;
use App\Support\Auth\SiteAccess;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExceptionResource extends Resource
{
    protected static ?string $model = ExceptionCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Exceptions';

    protected static ?string $modelLabel = 'Exception';

    protected static ?string $pluralModelLabel = 'Exceptions';

    protected static ?string $slug = 'exceptions';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsInboundIntegrations())
            && JobRoleAccess::allows(Permissions::NavExceptions);
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
     * @return Builder<ExceptionCase>
     */
    public static function getEloquentQuery(): Builder
    {
        return SiteAccess::constrainExceptionCases(parent::getEloquentQuery());
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExceptionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExceptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EpcsRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExceptions::route('/'),
            'view' => ViewException::route('/{record}'),
        ];
    }
}
