<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OutboundNetworkProfiles;

use App\Filament\Admin\Resources\OutboundNetworkProfiles\Pages\EditOutboundNetworkProfile;
use App\Filament\Admin\Resources\OutboundNetworkProfiles\Pages\ListOutboundNetworkProfiles;
use App\Filament\Admin\Resources\OutboundNetworkProfiles\Schemas\OutboundNetworkProfileForm;
use App\Filament\Admin\Resources\OutboundNetworkProfiles\Tables\OutboundNetworkProfilesTable;
use App\Models\Admin;
use App\Models\OutboundNetworkProfile;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OutboundNetworkProfileResource extends Resource
{
    protected static ?string $model = OutboundNetworkProfile::class;

    protected static ?string $slug = 'outbound-networks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Outbound networks';

    protected static ?string $modelLabel = 'Network profile';

    protected static ?string $pluralModelLabel = 'Outbound networks';

    protected static ?string $recordTitleAttribute = 'label';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::CatalogManage);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return OutboundNetworkProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutboundNetworkProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutboundNetworkProfiles::route('/'),
            'edit' => EditOutboundNetworkProfile::route('/{record}/edit'),
        ];
    }
}
