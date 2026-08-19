<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddLicenses;

use App\Filament\Admin\Resources\Fda\FdaWddLicenses\Pages\ListFdaWddLicenses;
use App\Filament\Admin\Resources\Fda\FdaWddLicenses\Schemas\FdaWddLicenseForm;
use App\Filament\Admin\Resources\Fda\FdaWddLicenses\Tables\FdaWddLicensesTable;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Fda\FdaWddLicense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FdaWddLicenseResource extends Resource
{
    use ViewOnlyFdaRegistryResource;

    protected static ?string $model = FdaWddLicense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Licenses';

    protected static ?string $modelLabel = 'License';

    protected static ?string $recordTitleAttribute = 'license_number';

    public static function form(Schema $schema): Schema
    {
        return FdaWddLicenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FdaWddLicensesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFdaWddLicenses::route('/'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['license_number'];
    }
}
