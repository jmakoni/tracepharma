<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews;

use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Pages\ListFdaOrganizationMatchReviews;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Pages\ViewFdaOrganizationMatchReview;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Schemas\FdaOrganizationMatchReviewInfolist;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Tables\FdaOrganizationMatchReviewsTable;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Fda\FdaOrganizationMatchReview;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FdaOrganizationMatchReviewResource extends Resource
{
    use ViewOnlyFdaRegistryResource;

    protected static ?string $model = FdaOrganizationMatchReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Match Reviews';

    protected static ?string $modelLabel = 'Match Review';

    protected static ?string $recordTitleAttribute = 'original_name';

    public static function infolist(Schema $schema): Schema
    {
        return FdaOrganizationMatchReviewInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaOrganizationMatchReviewsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaOrganizationMatchReviews::route('/'),
            'view' => ViewFdaOrganizationMatchReview::route('/{record}'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['original_name', 'canonical_name'];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FdaOrganizationMatchReview::query()->pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }
}
