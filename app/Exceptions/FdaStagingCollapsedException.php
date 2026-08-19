<?php

namespace App\Exceptions;

use App\Support\Fda\FdaStagingSnapshotSize;
use DomainException;

/**
 * The staging table holds far fewer rows than the last completed import, so it reads
 * as a truncated download rather than an FDA snapshot. Promoting from it would delist
 * every facility the short file left out.
 */
final class FdaStagingCollapsedException extends DomainException
{
    public function __construct(
        public readonly FdaStagingSnapshotSize $size,
    ) {
        parent::__construct(
            $size->summary().' Refusing to promote — check the download, then promote again with force to override.'
        );
    }
}
