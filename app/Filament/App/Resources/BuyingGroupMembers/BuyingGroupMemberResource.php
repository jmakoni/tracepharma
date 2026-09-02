<?php

namespace App\Filament\App\Resources\BuyingGroupMembers;

use App\Filament\App\Resources\BuyingGroupMembers\Pages\CreateBuyingGroupMember;
use App\Filament\App\Resources\BuyingGroupMembers\Pages\EditBuyingGroupMember;
use App\Filament\App\Resources\BuyingGroupMembers\Pages\ListBuyingGroupMembers;
use App\Filament\App\Resources\BuyingGroupMembers\Schemas\BuyingGroupMemberForm;
use App\Filament\App\Resources\BuyingGroupMembers\Tables\BuyingGroupMembersTable;
use App\Models\BuyingGroupMember;
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

class BuyingGroupMemberResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = BuyingGroupMember::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Member roster';

    protected static ?string $modelLabel = 'Member';

    protected static ?string $pluralModelLabel = 'Members';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsBuyingGroupNetwork()
            && JobRoleAccess::allowsOwnerOrAny(Permissions::UsersManage);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
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
        return BuyingGroupMemberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BuyingGroupMembersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuyingGroupMembers::route('/'),
            'create' => CreateBuyingGroupMember::route('/create'),
            'edit' => EditBuyingGroupMember::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.buying-group';
    }
}
