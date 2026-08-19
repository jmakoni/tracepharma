<?php

namespace App\Filament\App\Resources\Fda3911Reports;

use App\Filament\App\Resources\Fda3911Reports\Pages\CreateFda3911Report;
use App\Filament\App\Resources\Fda3911Reports\Pages\EditFda3911Report;
use App\Filament\App\Resources\Fda3911Reports\Pages\ListFda3911Reports;
use App\Filament\App\Resources\Fda3911Reports\Pages\ViewFda3911Report;
use App\Filament\App\Resources\Fda3911Reports\Schemas\Fda3911ReportForm;
use App\Filament\App\Resources\Fda3911Reports\Schemas\Fda3911ReportInfolist;
use App\Filament\App\Resources\Fda3911Reports\Tables\Fda3911ReportsTable;
use App\Models\Fda3911Report;
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

class Fda3911ReportResource extends Resource
{
    protected static ?string $model = Fda3911Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'FDA 3911 reports';

    protected static ?string $modelLabel = 'FDA 3911 report';

    protected static ?string $pluralModelLabel = 'FDA 3911 reports';

    protected static ?string $slug = 'fda-3911-reports';

    protected static ?string $recordTitleAttribute = 'product_name';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsComplianceCases())
            && JobRoleAccess::allows(Permissions::NavCompliance);
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

    public static function canEdit(Model $record): bool
    {
        return static::canView($record);
    }

    /**
     * @return Builder<Fda3911Report>
     */
    public static function getEloquentQuery(): Builder
    {
        return SiteAccess::constrainExceptionCaseRelation(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return Fda3911ReportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Fda3911ReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Fda3911ReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFda3911Reports::route('/'),
            'create' => CreateFda3911Report::route('/create'),
            'view' => ViewFda3911Report::route('/{record}'),
            'edit' => EditFda3911Report::route('/{record}/edit'),
        ];
    }
}
