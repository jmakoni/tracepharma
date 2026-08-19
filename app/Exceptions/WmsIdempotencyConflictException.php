<?php

namespace App\Exceptions;

use DomainException;

/**
 * Raised when an Idempotency-Key replay carries party/scans/connection/complete
 * data that does not match the original WMS ship-confirm request.
 */
final class WmsIdempotencyConflictException extends DomainException {}
