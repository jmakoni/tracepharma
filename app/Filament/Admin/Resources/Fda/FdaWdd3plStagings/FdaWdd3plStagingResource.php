<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plStagings;

use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Pages\ListFdaWdd3plStagings;
use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Tables\FdaWdd3plStagingsTable;
use App\Models\Fda\FdaWdd3plStaging;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use UnitEnum;

class FdaWdd3plStagingResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = FdaWdd3plStaging::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'WDD/3PL Staging';

    protected static ?string $modelLabel = 'WDD/3PL Staging Row';

    protected static ?string $pluralModelLabel = 'WDD/3PL Staging';

    public static function table(Table $table): Table
    {
        return FdaWdd3plStagingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaWdd3plStagings::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.wdd-3pl-staging';
    }
}
