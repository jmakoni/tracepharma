<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Documents whose current ingest_generation is a last-good projection:
 * parsed/validated, or error after a previously successful process (processed_at set).
 */
final class LastGoodIngestProjection
{
    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  list<string>  $successfulStatuses
     */
    public static function constrainDocuments(
        EloquentBuilder|QueryBuilder $query,
        string $table = 'epcis_documents',
        array $successfulStatuses = ['parsed', 'validated'],
    ): void {
        $query->where(function ($status) use ($table, $successfulStatuses): void {
            $status->whereIn($table.'.status', $successfulStatuses)
                ->orWhere(function ($error) use ($table): void {
                    $error->where($table.'.status', 'error')
                        ->whereNotNull($table.'.processed_at');
                });
        });
    }
}
