<?php

namespace App\Filament\App\Resources\LabelPrinters;

use App\Filament\App\Resources\LabelPrinters\Pages\CreateLabelPrinter;
use App\Filament\App\Resources\LabelPrinters\Pages\EditLabelPrinter;
use App\Filament\App\Resources\LabelPrinters\Pages\ListLabelPrinters;
use App\Filament\App\Resources\LabelPrinters\Schemas\LabelPrinterForm;
use App\Filament\App\Resources\LabelPrinters\Tables\LabelPrintersTable;
use App\Models\LabelPrinter;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class LabelPrinterResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = LabelPrinter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Label Printers';

    protected static ?string $modelLabel = 'Label Printer';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsSsccLabeling()
            && JobRoleAccess::allows(Permissions::NavIntegrations);
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

    public static function form(Schema $schema): Schema
    {
        return LabelPrinterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LabelPrintersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabelPrinters::route('/'),
            'create' => CreateLabelPrinter::route('/create'),
            'edit' => EditLabelPrinter::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'settings.labeling';
    }
}
