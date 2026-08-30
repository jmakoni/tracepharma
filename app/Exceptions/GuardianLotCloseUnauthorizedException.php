<?php

namespace App\Exceptions;

use DomainException;

/**
 * Raised when a Guardian lot-close POST carries a missing or invalid Bearer
 * API key relative to the tenant's configured `l3.api_key`.
 */
final class GuardianLotCloseUnauthorizedException extends DomainException {}
