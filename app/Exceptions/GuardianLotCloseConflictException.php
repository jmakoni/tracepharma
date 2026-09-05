<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Controllers\Api\V1\GuardianLotCloseController;
use DomainException;

/**
 * Raised when a Guardian lot-close resubmission reuses Envelope/MessageID with a
 * different body (file SHA-256 mismatch). Mapped to HTTP 409 by
 * {@see GuardianLotCloseController}.
 */
final class GuardianLotCloseConflictException extends DomainException {}
