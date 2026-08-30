<?php

namespace App\Filament\App\Resources\SsccNumberRanges;

use App\Filament\App\Resources\SsccNumberRanges\Pages\CreateSsccNumberRange;
use App\Filament\App\Resources\SsccNumberRanges\Pages\EditSsccNumberRange;
use App\Filament\App\Resources\SsccNumberRanges\Pages\ListSsccNumberRanges;
use App\Filament\App\Resources\SsccNumberRanges\Schemas\SsccNumberRangeForm;
use App\Filament\App\Resources\SsccNumberRanges\Tables\SsccNumberRangesTable;
use App\Models\SsccNumberRange;
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

class SsccNumberRangeResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = SsccNumberRange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 42;

    protected static ?string $navigationLabel = 'SSCC Number Ranges';

    protected static ?string $modelLabel = 'SSCC number range';

    protected static ?string $pluralModelLabel = 'SSCC number ranges';

    protected static ?string $slug = 'sscc-number-ranges';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsSsccLabeling()
            && JobRoleAccess::allows(Permissions::NavMasterData);
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

    /**
     * Tenant- and partner-scoped ranges stay visible; site-scoped ranges are
     * limited to the actor's accessible organization facilities.
     *
     * @return Builder<SsccNumberRange>
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

        return $query->where(function (Builder $inner) use ($siteIds): void {
            $inner->whereNull('site_id')
                ->orWhereIn('site_id', $siteIds);
        });
    }

    public static function form(Schema $schema): Schema
    {
        return SsccNumberRangeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SsccNumberRangesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSsccNumberRanges::route('/'),
            'create' => CreateSsccNumberRange::route('/create'),
            'edit' => EditSsccNumberRange::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'settings.labeling';
    }
}
