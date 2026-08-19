<?php

namespace App\Filament\App\Resources\EpcisJobs;

use App\Enums\EpcisJobKind;
use App\Filament\App\Resources\EpcisJobs\Pages\ListEpcisJobs;
use App\Filament\App\Resources\EpcisJobs\Pages\ViewEpcisJob;
use App\Filament\App\Resources\EpcisJobs\Schemas\EpcisJobInfolist;
use App\Filament\App\Resources\EpcisJobs\Tables\EpcisJobsTable;
use App\Models\EpcisJob;
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

class EpcisJobResource extends Resource
{
    protected static ?string $model = EpcisJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'EPCIS Jobs';

    protected static ?string $modelLabel = 'EPCIS job';

    protected static ?string $pluralModelLabel = 'EPCIS Jobs';

    protected static ?string $slug = 'epcis-jobs';

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return ($features->supportsInboundIntegrations()
            || $features->supportsOutboundIntegrations()
            || $features->supportsTransferring()
            || $features->supportsSsccLabeling())
            && JobRoleAccess::allows(Permissions::NavIntegrations);
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
     * @return Builder<EpcisJob>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->notArchived();

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        $siteIds = SiteAccess::userSiteIds($user);

        return $query->where(function (Builder $scoped) use ($siteIds): void {
            $scoped->whereIn('ship_from_site_id', $siteIds)
                ->orWhere(function (Builder $inbound) use ($siteIds): void {
                    $inbound->where('kind', EpcisJobKind::InboundProcess->value)
                        ->whereHas(
                            'document',
                            fn (Builder $document): Builder => $document->whereIn('ship_to_site_id', $siteIds),
                        );
                });
        });
    }

    public static function infolist(Schema $schema): Schema
    {
        return EpcisJobInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EpcisJobsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEpcisJobs::route('/'),
            'view' => ViewEpcisJob::route('/{record}'),
        ];
    }
}
