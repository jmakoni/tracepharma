<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\BuyingGroup\BuyingGroupNetworkSnapshots;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class MemberNetworkHealth extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Member health';

    protected static ?string $title = 'Member network health';

    protected static ?int $navigationSort = 26;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.member-network-health';

    public static function canAccess(): bool
    {
        $tenant = tenant();

        return TenantFeatures::forTenant($tenant)->supportsBuyingGroupNetwork()
            && TenantSettings::forTenant($tenant)->buyingGroupMemberRollupsEnabled()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Network readiness snapshots from linked pharmacy members (ATP gaps, exceptions, connections). Soft roster rows stay N/A until hard-linked.';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function healthRows(): Collection
    {
        return app(BuyingGroupNetworkSnapshots::class)->memberHealthRows();
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.buying-group';
    }
}
