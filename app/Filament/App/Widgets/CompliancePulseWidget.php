<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\Quarantine;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use App\Filament\App\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Support\Dashboard\DashboardLinks;
use App\Support\Dashboard\DashboardMetrics;
use App\Support\TenantFeatures;
use Filament\Widgets\Widget;

class CompliancePulseWidget extends Widget
{
    use AuthorizesDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.app.widgets.compliance-pulse-widget';

    public static function catalogKey(): string
    {
        return 'compliance_pulse';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = DashboardMetrics::make(auth()->user())->compliancePulse();
        $features = TenantFeatures::forTenant(tenant());

        $tracing = array_map(function (array $request): array {
            $due = $request['due_at'];

            return [
                'id' => $request['id'],
                'title' => $request['title'],
                'overdue' => $request['overdue'],
                'dueLabel' => $due?->timezone(config('app.timezone'))->format('M j, g:i A'),
                'url' => DashboardLinks::resourceViewUrl(TracingRequestResource::class, $request['id']),
            ];
        }, $metrics['tracing_at_risk']);

        return [
            'openExceptions' => $metrics['open_exceptions'],
            'openHolds' => $metrics['open_quarantine_holds'],
            'tracingAtRisk' => $tracing,
            'showExceptions' => $features->supportsComplianceCases(),
            'showTracing' => $features->supportsTracingRequests(),
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'exceptionsUrl' => DashboardLinks::resourceIndexUrl(ExceptionResource::class),
            'quarantineUrl' => DashboardLinks::pageUrl(Quarantine::class),
            'tracingUrl' => DashboardLinks::resourceIndexUrl(TracingRequestResource::class),
        ];
    }
}
