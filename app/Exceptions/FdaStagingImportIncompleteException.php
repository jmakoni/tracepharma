<?php

namespace App\Exceptions;

use App\Models\Fda\FdaWdd3plImportRun;
use DomainException;

/**
 * The last FDA WDD/3PL import never finished, so fda_wdd_3pl_staging holds part of a
 * report rather than a snapshot. Promoting from it would delist licenses whose rows
 * simply never loaded.
 */
final class FdaStagingImportIncompleteException extends DomainException
{
    public function __construct(
        public readonly FdaWdd3plImportRun $run,
    ) {
        parent::__construct(sprintf(
            'FDA WDD/3PL import run #%d started %s never completed; staging holds a partial report. Re-run tracepharma:import-fda-wdd-3pl before promoting.',
            (int) $run->getKey(),
            $run->started_at?->toDateTimeString() ?? 'unknown',
        ));
    }
}
