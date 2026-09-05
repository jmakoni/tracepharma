<?php

namespace App\Filament\App\Resources\Verifications;

use App\Filament\App\Resources\Verifications\Pages\ListVerifications;
use App\Filament\App\Resources\Verifications\Pages\ViewVerification;
use App\Filament\App\Resources\Verifications\Schemas\VerificationInfolist;
use App\Filament\App\Resources\Verifications\Tables\VerificationsTable;
use App\Models\Verification;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class VerificationResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = Verification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected static ?int $navigationSort = 26;

    protected static ?string $navigationLabel = 'Verification history';

    protected static ?string $modelLabel = 'Verification';

    protected static ?string $pluralModelLabel = 'Verifications';

    protected static ?string $slug = 'verifications';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsVrs()
            && JobRoleAccess::allows(Permissions::NavVerify);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        if (! static::canAccess()) {
            return false;
        }

        if (! $record->exists) {
            return true;
        }

        return static::getEloquentQuery()
            ->whereKey($record->getKey())
            ->exists();
    }

    /**
     * @return Builder<Verification>
     */
    public static function getEloquentQuery(): Builder
    {
        return SiteAccess::constrainVerifications(
            parent::getEloquentQuery(),
            'exception',
        );
    }

    public static function infolist(Schema $schema): Schema
    {
        return VerificationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VerificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerifications::route('/'),
            'view' => ViewVerification::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'exceptions.verifications';
    }
}
