<?php

namespace App\Filament\App\Resources\Principals;

use App\Filament\App\Resources\Principals\Pages\CreatePrincipal;
use App\Filament\App\Resources\Principals\Pages\EditPrincipal;
use App\Filament\App\Resources\Principals\Pages\ListPrincipals;
use App\Filament\App\Resources\Principals\Schemas\PrincipalForm;
use App\Filament\App\Resources\Principals\Tables\PrincipalsTable;
use App\Models\Principal;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use UnitEnum;

class PrincipalResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = Principal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 35;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Principals';

    protected static ?string $modelLabel = 'principal';

    protected static ?string $pluralModelLabel = 'principals';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsPrincipals()
            && JobRoleAccess::allowsOwnerOrAny(Permissions::NavMasterData);
    }

    public static function form(Schema $schema): Schema
    {
        return PrincipalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrincipalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrincipals::route('/'),
            'create' => CreatePrincipal::route('/create'),
            'edit' => EditPrincipal::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'master-data.products';
    }
}
