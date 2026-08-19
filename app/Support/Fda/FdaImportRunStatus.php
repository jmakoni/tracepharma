<?php

namespace App\Support\Fda;

use App\Models\Fda\FdaImportRun;
use Illuminate\Support\Collection;

final class FdaImportRunStatus
{
    public const SOURCE_WDD = 'wdd';

    public const SOURCE_DECRS = 'decrs';

    public const SOURCE_NDC = 'openfda_ndc';

    public const SOURCE_DRUGSFDA = 'openfda_drugsfda';

    /** @var array<string, string> */
    public const LABELS = [
        self::SOURCE_WDD => 'WDD',
        self::SOURCE_DECRS => 'DECRS',
        self::SOURCE_NDC => 'NDC',
        self::SOURCE_DRUGSFDA => 'Drugs@FDA',
    ];

    /**
     * @return list<array{
     *     source: string,
     *     label: string,
     *     run: ?FdaImportRun,
     *     outcome: ?string,
     *     last_sync: string,
     *     last_result: string,
     *     rows_read: ?int
     * }>
     */
    public static function cards(): array
    {
        /** @var Collection<string, FdaImportRun> $latest */
        $latest = FdaImportRun::query()
            ->whereIn('source', array_keys(self::LABELS))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->unique('source')
            ->keyBy('source');

        return collect(self::LABELS)
            ->map(function (string $label, string $source) use ($latest): array {
                $run = $latest->get($source);

                return [
                    'source' => $source,
                    'label' => $label,
                    'run' => $run,
                    'outcome' => $run instanceof FdaImportRun ? $run->outcome() : null,
                    'last_sync' => $run?->started_at?->timezone(config('app.timezone'))->format('M j, Y g:i A T') ?? 'Never',
                    'last_result' => match ($run instanceof FdaImportRun ? $run->outcome() : null) {
                        FdaRegistryStatus::IMPORT_SUCCESS => 'Success',
                        FdaRegistryStatus::IMPORT_PARTIAL => 'Partial',
                        FdaRegistryStatus::IMPORT_FAILED => 'Failed',
                        default => '—',
                    },
                    'rows_read' => $run?->rows_read,
                ];
            })
            ->values()
            ->all();
    }
}
