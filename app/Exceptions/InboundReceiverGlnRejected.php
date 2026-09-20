<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * SBDH Receiver is missing or is not this tenant's claimable GLN set.
 * Thrown before any EPCIS document or EPC rows are written.
 */
final class InboundReceiverGlnRejected extends RuntimeException {}
