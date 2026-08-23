<?php

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\IntegrationEndpointUrl;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Confirms existing VRS publish/consume surfaces. Verify Product is unchanged.
 */
class VrsLookupDirectory extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'VRS directory';

    protected static ?string $title = 'VRS directory';

    protected static ?int $navigationSort = 26;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected string $view = 'filament.app.pages.vrs-lookup-directory';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'vrs-directory';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsVrs()
            && JobRoleAccess::allows(Permissions::NavVerify);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Publish and consume endpoints already in this tenant. Verify Product is unchanged.';
    }

    public function publishUrl(): ?string
    {
        $tenantId = tenant()?->getKey();

        return is_string($tenantId) ? IntegrationEndpointUrl::vrsResponder($tenantId) : null;
    }

    public function consumePath(): string
    {
        return '/api/v1/dispense-check';
    }

    public function requestorGln(): ?string
    {
        return TenantSettings::forTenant(tenant())->gln();
    }

    public function responderConfigured(): bool
    {
        return filled(TenantSettings::forTenant(tenant())->vrsResponderApiKey());
    }
}
