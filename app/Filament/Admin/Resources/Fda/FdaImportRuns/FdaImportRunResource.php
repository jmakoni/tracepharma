<?php

namespace App\Filament\Admin\Resources\Fda\FdaImportRuns;

use App\Filament\Admin\Resources\Fda\FdaImportRuns\Pages\ListFdaImportRuns;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\Pages\ViewFdaImportRun;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\Schemas\FdaImportRunInfolist;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\Tables\FdaImportRunsTable;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Fda\FdaImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FdaImportRunResource extends Resource implements HasKnowledgeBase
{
    use ViewOnlyFdaRegistryResource;

    protected static ?string $model = FdaImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Import Runs';

    protected static ?string $modelLabel = 'Import Run';

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return FdaImportRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaImportRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaImportRuns::route('/'),
            'view' => ViewFdaImportRun::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.fda-imports';
    }
}
