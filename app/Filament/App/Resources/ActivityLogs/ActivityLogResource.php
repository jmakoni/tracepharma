<?php

namespace App\Filament\App\Resources\ActivityLogs;

use App\Filament\App\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\App\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Filament\Support\ActivityLogs\ActivityLogInfolist;
use App\Filament\Support\ActivityLogs\ActivityLogsTable;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

class ActivityLogResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activity Log';

    protected static ?string $slug = 'activity-log';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function canView(Model $record): bool
    {
        if (! static::canViewAny()) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User || $user->can(Permissions::SitesAccessAll)) {
            return true;
        }

        return static::getEloquentQuery()
            ->whereKey($record->getKey())
            ->exists();
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
     * @return Builder<Activity>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User || $user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        $siteIds = SiteAccess::userSiteIds($user)->all();

        return $query->where(function (Builder $outer) use ($siteIds, $user): void {
            $outer->where(function (Builder $own) use ($user): void {
                $own->where('causer_type', User::class)
                    ->where('causer_id', $user->getKey());
            });

            if ($siteIds === []) {
                return;
            }

            foreach ($siteIds as $siteId) {
                $outer->orWhere('properties->site_id', $siteId);
            }

            $outer->orWhere(function (Builder $exceptions) use ($siteIds): void {
                $exceptions->where('subject_type', ExceptionCase::class)
                    ->whereIn(
                        'subject_id',
                        ExceptionCase::query()->whereIn('site_id', $siteIds)->select('id'),
                    );
            });

            $outer->orWhere(function (Builder $documents) use ($siteIds): void {
                $documents->where('subject_type', EpcisDocument::class)
                    ->whereIn(
                        'subject_id',
                        EpcisDocument::query()
                            ->where(function (Builder $scoped) use ($siteIds): void {
                                $scoped->whereIn('ship_to_site_id', $siteIds)
                                    ->orWhereIn('ship_from_site_id', $siteIds);
                            })
                            ->select('id'),
                    );
            });
        });
    }

    public static function infolist(Schema $schema): Schema
    {
        return ActivityLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view' => ViewActivityLog::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.activity-log';
    }
}
