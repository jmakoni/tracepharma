<?php

namespace App\Filament\App\Pages;

use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Outbound\CustomerPortalService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

class CustomerPortalLinks extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Customer portal';

    protected static ?string $title = 'Customer portal';

    protected static ?int $navigationSort = 12;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected string $view = 'filament.app.pages.customer-portal-links';

    public ?string $issuedUrl = null;

    public ?int $issuedPartnerId = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'customer-portal';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavMasterData)
            && $user instanceof User
            && $user->can('deleteAny', TradingPartner::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Issue a signed buyer download link. Separate from the supplier exception portal. Trading Partners is unchanged.';
    }

    /**
     * @return Collection<int, TradingPartner>
     */
    public function partners(): Collection
    {
        return TradingPartner::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'gln', 'email', 'customer_portal_uuid']);
    }

    public function issueLinkAction(): Action
    {
        return Action::make('issueLink')
            ->label('Issue link')
            ->color('primary')
            ->action(function (array $arguments): void {
                $partner = TradingPartner::query()
                    ->where('is_active', true)
                    ->whereKey((int) ($arguments['partner'] ?? 0))
                    ->first();

                if ($partner === null) {
                    Notification::make()->title('Partner not found')->danger()->send();

                    return;
                }

                $this->authorize('managePortalLink', $partner);

                try {
                    $this->issuedUrl = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);
                    $this->issuedPartnerId = (int) $partner->getKey();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not issue link')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Customer portal link ready')
                    ->body('Copy the signed URL below. It is not the supplier exception portal.')
                    ->success()
                    ->send();
            });
    }
}
