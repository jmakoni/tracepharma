<?php

namespace App\Filament\App\Resources\TracingRequests;

use App\Filament\App\Resources\TracingRequests\Pages\CreateTracingRequest;
use App\Filament\App\Resources\TracingRequests\Pages\ListTracingRequests;
use App\Filament\App\Resources\TracingRequests\Pages\ViewTracingRequest;
use App\Filament\App\Resources\TracingRequests\Schemas\TracingRequestForm;
use App\Filament\App\Resources\TracingRequests\Schemas\TracingRequestInfolist;
use App\Filament\App\Resources\TracingRequests\Tables\TracingRequestsTable;
use App\Models\TracingRequest;
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

class TracingRequestResource extends Resource
{
    protected static ?string $model = TracingRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Tracing requests';

    protected static ?string $modelLabel = 'Tracing request';

    protected static ?string $pluralModelLabel = 'Tracing requests';

    protected static ?string $slug = 'tracing-requests';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsTracingRequests())
            && JobRoleAccess::allows(Permissions::NavCompliance);
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
     * @return Builder<TracingRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        return SiteAccess::constrainExceptionCaseRelation(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return TracingRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TracingRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TracingRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTracingRequests::route('/'),
            'create' => CreateTracingRequest::route('/create'),
            'view' => ViewTracingRequest::route('/{record}'),
        ];
    }
}
