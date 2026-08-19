<?php

namespace App\Filament\App\Resources\Devices;

use App\Filament\App\Resources\Devices\Pages\CreateDevice;
use App\Filament\App\Resources\Devices\Pages\EditDevice;
use App\Filament\App\Resources\Devices\Pages\ListDevices;
use App\Filament\App\Resources\Devices\Schemas\DeviceForm;
use App\Filament\App\Resources\Devices\Tables\DevicesTable;
use App\Models\Device;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsMasterData())
            && JobRoleAccess::allows(Permissions::NavMasterData);
    }

    public static function form(Schema $schema): Schema
    {
        return DeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'edit' => EditDevice::route('/{record}/edit'),
        ];
    }
}
