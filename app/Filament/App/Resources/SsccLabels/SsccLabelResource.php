<?php

namespace App\Filament\App\Resources\SsccLabels;

use App\Filament\App\Resources\SsccLabels\Pages\ListSsccLabels;
use App\Filament\App\Resources\SsccLabels\Pages\ViewSsccLabelBatch;
use App\Filament\App\Resources\SsccLabels\Schemas\SsccLabelForm;
use App\Filament\App\Resources\SsccLabels\Schemas\SsccLabelInfolist;
use App\Filament\App\Resources\SsccLabels\Tables\SsccLabelsTable;
use App\Models\SsccLabel;
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
use UnitEnum;

class SsccLabelResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = SsccLabel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'SSCC Labels';

    protected static ?string $modelLabel = 'SSCC label';

    protected static ?string $pluralModelLabel = 'SSCC labels';

    protected static ?string $recordTitleAttribute = 'sscc_18';

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return ($features->supportsPacking() || $features->supportsSsccLabeling())
            && JobRoleAccess::allows(Permissions::NavMasterData);
    }

    /**
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereHas(
            'batch',
            fn (Builder $batch): Builder => self::constrainBatchQuery($batch, $user),
        );
    }

    /**
     * AccessAll sees every batch. Site-restricted users only see batches whose
     * commission_site_id is one of their assigned organization facilities.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function constrainBatchQuery(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereIn('commission_site_id', SiteAccess::userSiteIds($user));
    }

    public static function form(Schema $schema): Schema
    {
        return SsccLabelForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SsccLabelInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SsccLabelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSsccLabels::route('/'),
            'view-batch' => ViewSsccLabelBatch::route('/batches/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'settings.labeling';
    }
}
