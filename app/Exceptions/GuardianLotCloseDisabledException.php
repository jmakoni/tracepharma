<?php

namespace App\Exceptions;

use DomainException;

/**
 * Raised when Guardian lot-close inbound is not enabled/configured for the
 * tenant (l3.enabled, l3.guardian_lot_close_enabled, or provider mismatch).
 */
final class GuardianLotCloseDisabledException extends DomainException {}
