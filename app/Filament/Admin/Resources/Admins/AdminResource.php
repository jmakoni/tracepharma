<?php

namespace App\Filament\Admin\Resources\Admins;

use App\Filament\Admin\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Admin\Resources\Admins\Pages\EditAdmin;
use App\Filament\Admin\Resources\Admins\Pages\ListAdmins;
use App\Filament\Admin\Resources\Admins\Schemas\AdminForm;
use App\Filament\Admin\Resources\Admins\Tables\AdminsTable;
use App\Models\Admin;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AdminResource extends Resource
{
    protected static ?string $model = Admin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Admins';

    protected static ?string $modelLabel = 'Admin';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::AdminsManage);
    }

    public static function canDelete(Model $record): bool
    {
        $admin = auth('admin')->user();

        if (! $admin instanceof Admin || $admin->is($record)) {
            return false;
        }

        return parent::canDelete($record);
    }

    public static function form(Schema $schema): Schema
    {
        return AdminForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmins::route('/'),
            'create' => CreateAdmin::route('/create'),
            'edit' => EditAdmin::route('/{record}/edit'),
        ];
    }
}
