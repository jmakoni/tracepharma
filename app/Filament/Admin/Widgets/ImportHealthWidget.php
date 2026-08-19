<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Fda\FdaImportRuns\FdaImportRunResource;
use App\Filament\Admin\Widgets\Concerns\AuthorizesAdminDashboardWidget;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardMetrics;
use Filament\Widgets\Widget;

class ImportHealthWidget extends Widget
{
    use AuthorizesAdminDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.admin.widgets.import-health-widget';

    public static function catalogKey(): string
    {
        return 'import_health';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = AdminDashboardMetrics::make()->importHealth();
        $empty = $metrics['incomplete'] === 0 && $metrics['failed'] === 0 && $metrics['partial'] === 0;

        return [
            'incomplete' => $metrics['incomplete'],
            'failed' => $metrics['failed'],
            'partial' => $metrics['partial'],
            'sources' => $metrics['sources'],
            'empty' => $empty,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'runsUrl' => AdminDashboardLinks::resourceIndexUrl(FdaImportRunResource::class),
        ];
    }
}
