<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The http VRS driver is selected but its endpoint is unusable (missing, malformed or
 * still pointing at a shipped example host). Refusing to start is deliberate: silently
 * resolving a placeholder host would record verification failures against real product.
 */
final class VrsConfigurationException extends RuntimeException {}
