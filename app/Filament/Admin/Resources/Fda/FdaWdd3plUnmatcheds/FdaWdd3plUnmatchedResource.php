<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds;

use App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\Pages\ListFdaWdd3plUnmatcheds;
use App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\Tables\FdaWdd3plUnmatchedsTable;
use App\Models\Fda\FdaWdd3plUnmatched;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FdaWdd3plUnmatchedResource extends Resource
{
    protected static ?string $model = FdaWdd3plUnmatched::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'WDD/3PL Unmatched';

    protected static ?string $modelLabel = 'Unmatched WDD/3PL Facility';

    protected static ?string $pluralModelLabel = 'WDD/3PL Unmatched';

    public static function table(Table $table): Table
    {
        return FdaWdd3plUnmatchedsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaWdd3plUnmatcheds::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
