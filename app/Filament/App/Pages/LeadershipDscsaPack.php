<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Models\User;
use App\Support\Auth\HidesForPharmacySimplifiedNav;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\LeadershipDscsaPackMetrics;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class LeadershipDscsaPack extends Page implements HasKnowledgeBase
{
    use HidesForPharmacySimplifiedNav;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Leadership DSCSA pack';

    protected static ?string $title = 'Leadership DSCSA pack';

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.leadership-dscsa-pack';

    public string $range = 'mtd';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'leadership-dscsa-pack';
    }

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        if (! $features->hasAnyOperations() && ! $features->supportsComplianceCases()) {
            return false;
        }

        return JobRoleAccess::isOwner()
            || JobRoleAccess::allowsAny(
                Permissions::NavCompliance,
                Permissions::NavReceive,
                Permissions::NavShip,
                Permissions::NavIntegrations,
            );
    }

    public function mount(): void
    {
        $this->range = $this->normalizeRange($this->range);
    }

    public function updatedRange(mixed $value): void
    {
        $this->range = $this->normalizeRange(is_string($value) ? $value : (string) $value);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Outbound transmit and MDN success, late MDNs, decommission reasons, stuck serials, open exceptions, and L3→L4 ingest lag for leadership oversight.';
    }

    public function asOfLabel(): string
    {
        return now()->timezone((string) config('app.timezone'))->toDayDateTimeString();
    }

    public function rangeLabel(): string
    {
        return match ($this->range) {
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            default => 'Month to date',
        };
    }

    /**
     * @return array<string, array{summary: array<string, mixed>, rows: list<array<string, mixed>>}>
     */
    public function metrics(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        return LeadershipDscsaPackMetrics::make($user, $this->range)->all();
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     data: array{summary: array<string, mixed>, rows: list<array<string, mixed>>},
     *     index_url: string|null,
     *     index_label: string|null
     * }>
     */
    public function sections(): array
    {
        $metrics = $this->metrics();

        $catalog = [
            'transmit_success' => [
                'label' => 'Transmit success',
                'description' => 'Outbound EPCIS documents successfully transmitted in the selected range.',
                'index_resource' => OutboundEpcisDocumentResource::class,
                'index_label' => 'View outbound EPCIS',
            ],
            'mdn_success' => [
                'label' => 'MDN success',
                'description' => 'Message disposition notifications acknowledged by trading partners.',
                'index_resource' => OutboundEpcisDocumentResource::class,
                'index_label' => 'View outbound EPCIS',
            ],
            'late_missing_mdn' => [
                'label' => 'Late & missing MDN',
                'description' => 'Transmits awaiting or past expected MDN SLA.',
                'index_resource' => OutboundEpcisDocumentResource::class,
                'index_label' => 'View outbound EPCIS',
            ],
            'decommission_by_reason' => [
                'label' => 'Decommission by reason',
                'description' => 'Decommission events grouped by disposition reason.',
            ],
            'stuck_serials' => [
                'label' => 'Stuck serials',
                'description' => 'EPCs on open, overdue exception cases, grouped by case status.',
            ],
            'open_exceptions_by_code' => [
                'label' => 'Open exceptions by code',
                'description' => 'Unresolved exception cases grouped by code.',
                'index_resource' => ExceptionResource::class,
                'index_label' => 'View exceptions',
            ],
            'l3_l4_ingest_lag' => [
                'label' => 'L3→L4 ingest lag',
                'description' => 'Hours from labeling / commission-source timestamp to L4 commissioning event time versus the configured SLA.',
                'index_resource' => SsccLabelResource::class,
                'index_label' => 'View SSCC labels',
            ],
        ];

        $sections = [];
        foreach ($catalog as $key => $meta) {
            if (! isset($metrics[$key])) {
                continue;
            }

            $resource = $meta['index_resource'] ?? null;

            $sections[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'data' => $metrics[$key],
                'index_url' => $resource !== null ? $this->resourceIndexUrl($resource) : null,
                'index_label' => $meta['index_label'] ?? 'Open',
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function summaryPercent(array $summary): ?float
    {
        foreach (['success_percent', 'percent', 'rate', 'success_rate'] as $key) {
            if (isset($summary[$key]) && is_numeric($summary[$key])) {
                return (float) $summary[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function summaryCount(array $summary, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($summary[$key]) && is_numeric($summary[$key])) {
                return (int) $summary[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function rowDisplayKeys(array $row): array
    {
        $hidden = [
            'exception_id',
            'document_id',
            'outbound_document_id',
            'epcis_document_id',
            'batch_id',
        ];

        return array_values(array_filter(
            array_keys($row),
            fn (string $key): bool => ! in_array($key, $hidden, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{url: string, label: string}|null
     */
    public function rowLink(array $row): ?array
    {
        foreach (['case_id', 'exception_id'] as $caseKey) {
            if (isset($row[$caseKey]) && is_numeric($row[$caseKey])) {
                $url = $this->exceptionUrl((int) $row[$caseKey]);
                if ($url !== null) {
                    return ['url' => $url, 'label' => 'View exception'];
                }
            }
        }

        foreach (['document_id', 'outbound_document_id', 'epcis_document_id'] as $key) {
            if (! isset($row[$key]) || ! is_numeric($row[$key])) {
                continue;
            }

            $url = $this->outboundDocumentUrl((int) $row[$key]);
            if ($url !== null) {
                return ['url' => $url, 'label' => 'View document'];
            }
        }

        return null;
    }

    public function exceptionUrl(?int $id): ?string
    {
        if ($id === null) {
            return $this->resourceIndexUrl(ExceptionResource::class);
        }

        return $this->resourceViewUrl(ExceptionResource::class, $id);
    }

    public function outboundDocumentUrl(?int $id): ?string
    {
        if ($id === null) {
            return $this->resourceIndexUrl(OutboundEpcisDocumentResource::class);
        }

        return $this->resourceViewUrl(OutboundEpcisDocumentResource::class, $id);
    }

    public function exportCsvAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (): StreamedResponse => $this->streamExportCsv());
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->exportCsvAction(),
        ];
    }

    private function streamExportCsv(): StreamedResponse
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $rows = LeadershipDscsaPackMetrics::make($user, $this->range)->exportRows();
        $filename = 'leadership-dscsa-pack-'.$this->range.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            $headers = $this->exportCsvHeaders($rows);
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $header) {
                    $value = $row[$header] ?? '';
                    $line[] = is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value);
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function exportCsvHeaders(array $rows): array
    {
        $keys = ['metric'];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if ($key !== 'metric' && ! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    private function normalizeRange(string $value): string
    {
        return match ($value) {
            '7', '30' => $value,
            default => 'mtd',
        };
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            $panel = Filament::getPanel('app');
            $name = $resource::getRouteBaseName($panel).'.index';

            if (! Route::has($name)) {
                return null;
            }

            return $resource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceViewUrl(string $resource, int $id): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $id], panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function getDocumentation(): array|string
    {
        return 'compliance.leadership-dscsa-pack';
    }
}
