<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Roles;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Roles\Pages\EditRole;
use App\Filament\App\Resources\Roles\Pages\ListRoles;
use App\Filament\Support\Roles\RoleForm;
use App\Filament\Support\Roles\RolesTable;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $guardName = 'web';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can(Permissions::UsersManage)
            && JobRoleAccess::allows(Permissions::NavUsers);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema, self::$guardName);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table, self::$guardName, self::catalogRoleNames());
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('guard_name', self::$guardName)
            ->whereIn('name', self::catalogRoleNames());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'settings.users';
    }

    /**
     * @return list<string>
     */
    public static function catalogRoleNames(): array
    {
        $tenant = tenant();
        $profile = $tenant instanceof Tenant && $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::Pharmacy;

        return array_map(
            static fn (TenantRole $role): string => $role->value,
            TenantRole::forProfile($profile),
        );
    }
}
