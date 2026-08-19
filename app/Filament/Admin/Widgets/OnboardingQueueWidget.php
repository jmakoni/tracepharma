<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource;
use App\Filament\Admin\Resources\DemoRequests\DemoRequestResource;
use App\Filament\Admin\Widgets\Concerns\AuthorizesAdminDashboardWidget;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardMetrics;
use Filament\Widgets\Widget;

class OnboardingQueueWidget extends Widget
{
    use AuthorizesAdminDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.admin.widgets.onboarding-queue-widget';

    public static function catalogKey(): string
    {
        return 'onboarding_queue';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = AdminDashboardMetrics::make()->onboardingQueue();
        $empty = $metrics['submitted'] === 0
            && $metrics['approved'] === 0
            && $metrics['demo_requests_last_7d'] === 0;

        return [
            'submitted' => $metrics['submitted'],
            'approved' => $metrics['approved'],
            'demoRequests' => $metrics['demo_requests_last_7d'],
            'empty' => $empty,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'onboardingUrl' => AdminDashboardLinks::resourceIndexUrl(CustomerOnboardingResource::class),
            'demoUrl' => AdminDashboardLinks::resourceIndexUrl(DemoRequestResource::class),
        ];
    }
}
