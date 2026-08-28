<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Integrations\WholesalerIntegrationPack;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class WholesalerIntegrationPackPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Wholesaler / WMS pack';

    protected static ?string $title = 'Wholesaler / WMS integration pack';

    protected static ?int $navigationSort = 22;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected string $view = 'filament.app.pages.wholesaler-integration-pack';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()
            && JobRoleAccess::allowsAny(
                Permissions::NavIntegrations,
                Permissions::NavShip,
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Certify WMS ship-confirm and Sanctum outbound EPCIS for wholesaler warehouse partners.';
    }

    public function checklistScore(): int
    {
        return app(WholesalerIntegrationPack::class)->score();
    }

    /**
     * @return list<array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}>
     */
    public function checklistItems(): array
    {
        return app(WholesalerIntegrationPack::class)->items();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openApiTokens')
                ->label('Create ship-confirm token')
                ->icon(Heroicon::OutlinedKey)
                ->url(fn (): string => ApiTokens::getUrl(panel: 'app').'?ability=wms:ship-confirm')
                ->visible(fn (): bool => ApiTokens::canAccess()),
            Action::make('openOrganization')
                ->label('WMS bridge key')
                ->icon(Heroicon::OutlinedBuildingOffice)
                ->url(fn (): string => OrganizationSettings::getUrl(panel: 'app'))
                ->visible(fn (): bool => OrganizationSettings::canAccess()),
        ];
    }
}
