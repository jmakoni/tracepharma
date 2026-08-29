<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\EpcisSubscriptions;

use App\Filament\App\Resources\EpcisSubscriptions\Pages\CreateEpcisSubscription;
use App\Filament\App\Resources\EpcisSubscriptions\Pages\EditEpcisSubscription;
use App\Filament\App\Resources\EpcisSubscriptions\Pages\ListEpcisSubscriptions;
use App\Filament\App\Resources\EpcisSubscriptions\Schemas\EpcisSubscriptionForm;
use App\Filament\App\Resources\EpcisSubscriptions\Tables\EpcisSubscriptionsTable;
use App\Models\Epcis\EpcisSubscription;
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

class EpcisSubscriptionResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = EpcisSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'EPCIS Subscriptions';

    protected static ?string $modelLabel = 'EPCIS Subscription';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return ($features->supportsInboundIntegrations() || $features->supportsOutboundIntegrations())
            && JobRoleAccess::allows(Permissions::NavIntegrations);
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
        return EpcisSubscriptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EpcisSubscriptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEpcisSubscriptions::route('/'),
            'create' => CreateEpcisSubscription::route('/create'),
            'edit' => EditEpcisSubscription::route('/{record}/edit'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'integrations.epcis-subscriptions';
    }
}
