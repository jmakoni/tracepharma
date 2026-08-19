<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\DashboardLinks;
use App\Support\Dashboard\DashboardMetrics;
use App\Support\TenantFeatures;
use Filament\Widgets\Widget;

class TodayActivityWidget extends Widget
{
    use AuthorizesDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.app.widgets.today-activity-widget';

    public static function catalogKey(): string
    {
        return 'today_activity';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $metrics = DashboardMetrics::make($user instanceof User ? $user : null)->todayActivity();
        $features = TenantFeatures::forTenant(tenant());
        $showVrsCounts = $features->supportsVrs()
            && $user instanceof User
            && $user->can(Permissions::SitesAccessAll);

        return [
            'receivesCompleted' => $metrics['receives_completed'],
            'shipsCompleted' => $metrics['ships_completed'],
            'exceptionsOpened' => $metrics['exceptions_opened'],
            'vrsAllowed' => $metrics['vrs_allowed'],
            'vrsBlocked' => $metrics['vrs_blocked'],
            'showReceive' => $features->supportsReceiving(),
            'showShip' => $features->supportsOutboundIntegrations(),
            'showExceptions' => $features->supportsComplianceCases() || $features->supportsInboundIntegrations(),
            'showVrs' => $showVrsCounts,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'receiveUrl' => DashboardLinks::resourceIndexUrl(ReceivingSessionResource::class),
            'shipUrl' => DashboardLinks::resourceIndexUrl(OutboundShippingSessionResource::class),
            'exceptionsUrl' => DashboardLinks::resourceIndexUrl(ExceptionResource::class),
            'verifyUrl' => DashboardLinks::pageUrl(VerifyProduct::class),
        ];
    }
}
