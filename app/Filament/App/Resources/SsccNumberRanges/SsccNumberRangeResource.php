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
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SsccNumberRangeResource extends Resource
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
        return (TenantFeatures::forTenant(tenant())->supportsSsccLabeling())
            && JobRoleAccess::allows(Permissions::NavMasterData);
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
}
