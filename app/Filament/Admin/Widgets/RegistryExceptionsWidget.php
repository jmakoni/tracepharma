<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\FdaWdd3plUnmatchedResource;
use App\Filament\Admin\Widgets\Concerns\AuthorizesAdminDashboardWidget;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardMetrics;
use Filament\Widgets\Widget;

class RegistryExceptionsWidget extends Widget
{
    use AuthorizesAdminDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.admin.widgets.registry-exceptions-widget';

    public static function catalogKey(): string
    {
        return 'registry_exceptions';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = AdminDashboardMetrics::make()->registryExceptions();
        $empty = $metrics['pending_match_reviews'] === 0 && $metrics['unresolved_unmatched'] === 0;

        return [
            'pendingMatchReviews' => $metrics['pending_match_reviews'],
            'unresolvedUnmatched' => $metrics['unresolved_unmatched'],
            'empty' => $empty,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'reviewsUrl' => AdminDashboardLinks::resourceIndexUrl(FdaOrganizationMatchReviewResource::class),
            'unmatchedUrl' => AdminDashboardLinks::resourceIndexUrl(FdaWdd3plUnmatchedResource::class),
        ];
    }
}
